<?php
/**
 * Publish-notify: notify a submitter when their submission goes live.
 *
 * A generic PRODUCER on the network notification substrate (service.php). It
 * closes the last gap in a cross-site submission lifecycle: a contributor whose
 * post is published never hears about it. This observer fires the moment a post
 * transitions to `publish` and — if the post was registered as a "notifiable
 * submission" by some feature — enqueues a bell + email notification to the
 * recorded submitter via an idempotent notification receipt.
 *
 * WHY THIS LIVES IN extrachill-users (and why it is feature-agnostic)
 * ------------------------------------------------------------------
 * The load-bearing constraint is activation scope. A submission can be authored
 * on a tool subsite but PUBLISHED on a different site (e.g. born-on-main
 * workflows where the tool plugin is active only on its own subsite but the
 * post lives on the main site). `transition_post_status` for that publish fires
 * in a request on the PUBLISHING site — a request where the tool plugin is not
 * loaded and therefore cannot observe the transition or register a runtime
 * hook. A hook registered by a subsite-only plugin simply never runs there.
 *
 * extrachill-users is Network:true, so it loads on EVERY site including the
 * publishing site. That makes it the only correct home for the observer. But
 * the observer must stay generic — it must not know about any particular
 * feature, meta key, or message copy (layer purity). It therefore reads a
 * DESCRIPTOR REGISTRY: a network site-option that any feature writes a small,
 * data-only descriptor into (see ec_users_register_publish_notify_source()).
 * A site-option is network-global, so a descriptor a feature registers from its
 * own subsite is visible on the publishing site where this observer runs —
 * bridging the exact activation-scope gap that a per-request runtime filter
 * cannot. The descriptor is pure data (no closures) so it survives
 * serialization and cross-request/cross-site visibility.
 *
 * WHAT A DESCRIPTOR DECLARES
 * --------------------------
 * A feature that wants "notify the submitter when this kind of post publishes"
 * registers, keyed by a context slug it owns:
 *   - meta_key       string  Post meta present on notifiable posts (the opt-in
 *                            marker). A post publishing WITHOUT this meta is
 *                            ignored.
 *   - user_id_field  string  Optional. When the marker meta is an array, the
 *                            key inside it holding the submitter user id. When
 *                            empty, the meta value itself is treated as the id.
 *   - type           string  Notification type (e.g. a feature-specific slug).
 *   - title_template string  Human title with a single %s placeholder for the
 *                            post title (e.g. 'Your post "%s" is live').
 *
 * The observer resolves the recipient id, builds the title from the template +
 * the post title, links to the post permalink, sets item_id = post id, and
 * requests one notification per post (a per-post meta guard prevents
 * re-fire on later edits or re-publish). Publication is a transactional
 * lifecycle event, so this producer owns and immediately queues the email arm;
 * the generic unread-notification digest excludes the claimed receipt.
 *
 * @package ExtraChill\Users
 * @since 0.24.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/service.php';

/**
 * Network site-option holding the publish-notify descriptor registry.
 *
 * Shape: array<string context, array descriptor>. Network-global (site-option)
 * so a descriptor registered from any subsite is readable on the publishing
 * site where the observer runs.
 */
const EC_USERS_PUBLISH_NOTIFY_SOURCES_OPTION = 'ec_users_publish_notify_sources';

/**
 * Post-meta key prefix marking that a publish notification already fired.
 *
 * Namespaced per context so two features watching the same post never suppress
 * each other. Written after a successful notify; presence short-circuits any
 * later re-publish/edit transition for that context.
 */
const EC_USERS_PUBLISH_NOTIFIED_META_PREFIX = '_ec_users_publish_notified_';

/**
 * Stable notification producer for every registered publish-notify source.
 */
const EC_USERS_PUBLISH_NOTIFY_PRODUCER = 'extrachill-users.publish-notify';

/**
 * Register (or refresh) a publish-notify source descriptor.
 *
 * A feature calls this to declare "when a post carrying <meta_key> publishes,
 * notify the recorded submitter." Registration is idempotent and data-only, so
 * a feature can safely call it on every init on its own site; the descriptor is
 * persisted to a network site-option and thereby made visible on whatever site
 * the post actually publishes on.
 *
 * The substrate stores only the whitelisted descriptor fields, so a caller
 * cannot smuggle arbitrary data (or closures) into the registry.
 *
 * @since 0.24.0
 *
 * @param string $context    Caller-owned slug identifying this source (also the
 *                           dedupe namespace). Sanitized to a key.
 * @param array  $descriptor {
 *     Data-only descriptor.
 *
 *     @type string $meta_key       Required. Opt-in marker meta on notifiable posts.
 *     @type string $user_id_field  Optional. Key inside an array-valued marker holding
 *                                   the submitter id. Empty = the meta value is the id.
 *     @type string $type           Required. Notification type slug.
 *     @type string $title_template Required. Title with one %s for the post title.
 * }
 * @return bool True when the registry was written/updated, false on bad input.
 */
function ec_users_register_publish_notify_source( string $context, array $descriptor ): bool {
	$context = sanitize_key( $context );
	if ( '' === $context ) {
		return false;
	}

	$meta_key       = isset( $descriptor['meta_key'] ) ? (string) $descriptor['meta_key'] : '';
	$type           = isset( $descriptor['type'] ) ? sanitize_key( (string) $descriptor['type'] ) : '';
	$title_template = isset( $descriptor['title_template'] ) ? (string) $descriptor['title_template'] : '';
	$user_id_field  = isset( $descriptor['user_id_field'] ) ? (string) $descriptor['user_id_field'] : '';

	if ( '' === $meta_key || '' === $type || '' === $title_template ) {
		return false;
	}

	$clean = array(
		'meta_key'       => $meta_key,
		'user_id_field'  => $user_id_field,
		'type'           => $type,
		'title_template' => $title_template,
	);

	$sources = get_site_option( EC_USERS_PUBLISH_NOTIFY_SOURCES_OPTION, array() );
	if ( ! is_array( $sources ) ) {
		$sources = array();
	}

	// No-op write avoidance: skip the update when nothing changed.
	if ( isset( $sources[ $context ] ) && $sources[ $context ] === $clean ) {
		return true;
	}

	$sources[ $context ] = $clean;

	return (bool) update_site_option( EC_USERS_PUBLISH_NOTIFY_SOURCES_OPTION, $sources );
}

/**
 * Read the publish-notify descriptor registry.
 *
 * @since 0.24.0
 *
 * @return array<string, array> Context-keyed descriptors.
 */
function ec_users_get_publish_notify_sources(): array {
	$sources = get_site_option( EC_USERS_PUBLISH_NOTIFY_SOURCES_OPTION, array() );

	return is_array( $sources ) ? $sources : array();
}

/**
 * Observe post publishes and notify submitters of registered sources.
 *
 * Fires on every site (extrachill-users is network-active), so it sees the
 * transition on whatever site the post publishes on. Acts only on the
 * draft/pending → publish edge; edits and re-saves of an already-published post
 * do not re-fire, both because of the edge check and the per-context guard meta.
 *
 * @since 0.24.0
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Old post status.
 * @param \WP_Post $post       Post object.
 * @return void
 */
function ec_users_publish_notify_on_transition( string $new_status, string $old_status, $post ): void {
	// Only the transition INTO publish — never a re-save of a live post.
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	if ( ! $post instanceof \WP_Post ) {
		return;
	}

	// Skip auto-drafts / revisions and anything that is not a real post row.
	if ( 'auto-draft' === get_post_status( $post ) && '' === $post->post_title ) {
		return;
	}

	$sources = ec_users_get_publish_notify_sources();
	if ( empty( $sources ) ) {
		return;
	}

	foreach ( $sources as $context => $descriptor ) {
		ec_users_publish_notify_apply_source( (string) $context, (array) $descriptor, $post );
	}
}
add_action( 'transition_post_status', 'ec_users_publish_notify_on_transition', 10, 3 );

/**
 * Apply a single registered source to a just-published post.
 *
 * Reads the source's marker meta off the post; if present, resolves the
 * submitter id, builds the notification payload from the descriptor, and fires
 * one idempotent notification (guarded by a per-context meta marker).
 *
 * @since 0.24.0
 *
 * @param string   $context    Source context slug (dedupe namespace).
 * @param array    $descriptor Stored descriptor.
 * @param \WP_Post $post       The published post.
 * @return void
 */
function ec_users_publish_notify_apply_source( string $context, array $descriptor, \WP_Post $post ): void {
	$context = sanitize_key( $context );
	if ( '' === $context ) {
		return;
	}

	$meta_key = isset( $descriptor['meta_key'] ) ? (string) $descriptor['meta_key'] : '';
	if ( '' === $meta_key ) {
		return;
	}

	$marker = get_post_meta( $post->ID, $meta_key, true );
	if ( empty( $marker ) ) {
		return; // Not a notifiable post for this source.
	}

	// Once-only guard: already notified for this context on this post.
	$guard_key = EC_USERS_PUBLISH_NOTIFIED_META_PREFIX . $context;
	if ( get_post_meta( $post->ID, $guard_key, true ) ) {
		return;
	}

	$user_id = ec_users_publish_notify_resolve_user_id(
		$marker,
		isset( $descriptor['user_id_field'] ) ? (string) $descriptor['user_id_field'] : ''
	);
	if ( $user_id <= 0 ) {
		return;
	}

	$title_template = isset( $descriptor['title_template'] ) ? (string) $descriptor['title_template'] : '';
	$type           = isset( $descriptor['type'] ) ? (string) $descriptor['type'] : '';
	if ( '' === $title_template || '' === $type ) {
		return;
	}

	$post_title = get_the_title( $post );
	$title      = sprintf( $title_template, $post_title );
	$link       = (string) get_permalink( $post->ID );
	$producer   = EC_USERS_PUBLISH_NOTIFY_PRODUCER;
	$key        = sprintf( 'context:%s:blog:%d:post:%d', $context, get_current_blog_id(), $post->ID );

	$delivery = ec_users_notify_with_receipts(
		$user_id,
		array(
			// The submitter is both the subject and, for a system-authored
			// "you're live" notice, the actor — the substrate requires a
			// valid actor_id that resolves to a real user.
			'actor_id'            => $user_id,
			'type'                => $type,
			'title'               => $title,
			'link'                => $link,
			'item_id'             => (int) $post->ID,
			'producer'            => $producer,
			'idempotency_key'     => $key,
			'producer_owns_email' => true,
		)
	);

	$recipient_receipt = is_array( $delivery['recipients'][ $user_id ] ?? null ) ? $delivery['recipients'][ $user_id ] : array();
	$status            = (string) ( $recipient_receipt['status'] ?? 'failed' );
	$notification_id   = (int) ( $recipient_receipt['notification_id'] ?? 0 );

	if ( 'existing' === $status ) {
		// Another request owns this claim. The receipt itself is the dedupe;
		// do not write the permanent guard until queue admission is confirmed.
		return;
	}

	if ( 'inserted' !== $status || $notification_id <= 0 ) {
		// Stable receipt failures preserve the historical attempt-once behavior.
		update_post_meta( $post->ID, $guard_key, current_time( 'mysql', true ) );
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user instanceof \WP_User || ! ec_users_publish_notify_queue_email( $user, $title, $post_title, $link ) ) {
		$released = function_exists( 'ec_users_release_notification_receipt' )
			&& ec_users_release_notification_receipt( $notification_id, $user_id, $producer, $key );
		if ( ! $released ) {
			// The claim cannot be retried safely when its exact receipt remains.
			update_post_meta( $post->ID, $guard_key, current_time( 'mysql', true ) );
		}
		error_log( sprintf( 'ec_users_publish_notify: immediate email enqueue failed for user %d, post %d.', $user_id, $post->ID ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
		return;
	}

	update_post_meta( $post->ID, $guard_key, current_time( 'mysql', true ) );
}

/**
 * Queue the immediate branded publication email for a submitter.
 *
 * @param \WP_User $user       Recipient.
 * @param string   $subject    Descriptor-resolved notification title.
 * @param string   $post_title Published post title.
 * @param string   $link       Public post permalink.
 * @return bool True when the email was accepted by the queue.
 */
function ec_users_publish_notify_queue_email( \WP_User $user, string $subject, string $post_title, string $link ): bool {
	if ( ! is_email( $user->user_email ) || '' === $subject || '' === $post_title || ! wp_http_validate_url( $link ) || ! function_exists( 'ec_send_email_queued' ) ) {
		return false;
	}

	$body_html = '<p>' . sprintf(
		/* translators: %s: published post title. */
		esc_html__( 'Your submission “%s” has been published on Extra Chill.', 'extrachill-users' ),
		esc_html( $post_title )
	) . '</p><p>' . esc_html__( 'Thanks for contributing. Your post is live and ready to share.', 'extrachill-users' ) . '</p>';
	try {
		$result = ec_send_email_queued(
			array(
				'to'       => $user->user_email,
				'subject'  => $subject,
				'template' => 'extrachill/branded',
				'context'  => array(
					'subject_html'   => esc_html( $subject ),
					'recipient_name' => $user->display_name,
					'body_html'      => $body_html,
					'cta_url'        => $link,
					'cta_label'      => __( 'Read your post', 'extrachill-users' ),
					'preheader'      => __( 'Your Extra Chill submission is live.', 'extrachill-users' ),
				),
			)
		);
	} catch ( \Throwable $exception ) {
		error_log( sprintf( 'ec_users_publish_notify: email queue exception for user %1$d: %2$s', $user->ID, $exception->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
		return false;
	}

	return ! empty( $result['success'] );
}

/**
 * Resolve a submitter user id from a marker meta value.
 *
 * Supports two marker shapes:
 *   - Array marker: the id lives under $user_id_field (e.g. a provenance record
 *     like { user_id, submitted_at, source }).
 *   - Scalar marker: the meta value itself is the user id.
 *
 * @since 0.24.0
 *
 * @param mixed  $marker        The marker meta value.
 * @param string $user_id_field Field within an array marker holding the id.
 * @return int Resolved user id, or 0 when not resolvable.
 */
function ec_users_publish_notify_resolve_user_id( $marker, string $user_id_field ): int {
	if ( is_array( $marker ) ) {
		if ( '' === $user_id_field ) {
			return 0;
		}
		return isset( $marker[ $user_id_field ] ) ? (int) $marker[ $user_id_field ] : 0;
	}

	return (int) $marker;
}
