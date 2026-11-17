<?php
/**
 * Push delivery channel for the network notification substrate.
 *
 * A DELIVERY CHANNEL beside the in-app bell (bell.php) and the email digest
 * (email.php). When ec_users_notify_with_receipts() INSERTS a new row (never on
 * an idempotent replay), the substrate fires `ec_users_notification_inserted`
 * once per freshly inserted recipient. This file listens there — one hook
 * point, no per-producer code — and hands a lock-screen-safe message to the
 * wp-native-auth push primitive.
 *
 * Design:
 *   - wp-native-auth is an OPTIONAL dependency. The primitive
 *     wp_native_auth_send_push( int $user_id, array $message ): array is
 *     feature-detected with function_exists() at send time, the same way
 *     inc/wp-native-bridge.php integrates optionally. Without it this channel is
 *     a silent no-op. Device tokens live in wp-native-auth; nothing is stored
 *     here. The primitive is non-blocking (it queues and delivers later).
 *   - Only DIRECT, meaningful types push: replies, mentions, subscribed-topic
 *     replies, approvals and requested work. The allow-list is one filterable
 *     map (ec_users_notification_push_types()). Upvote, popularity, digest and
 *     engagement nudges are never on it.
 *   - Lock-screen safety: the title is a generic, per-type string ("New reply
 *     from {actor}"). The notification's own title (topic title, submission
 *     summary, …) is only used as the body when the item is verifiably public.
 *     Forum items on a private/hidden/closed forum or topic, venue-scoped items
 *     and review queues never carry a body.
 *   - `data` carries notification_id, type and the canonical url (the app
 *     routes taps from it). `collapse_id` is per item (per topic for forum
 *     types), so bursts on one topic replace each other instead of stacking.
 *   - Per-user opt-out via the ec_notification_push_disabled user_meta flag
 *     (default OFF == opted-in), written ONLY through
 *     ec_users_set_notification_push_disabled(), mirroring the email opt-out
 *     helpers in email.php. Exposed through the notification-preferences
 *     abilities and the community settings UI.
 *
 * Issue: Extra-Chill/extrachill#1391 (push epic: Extra-Chill/wp-native#59).
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/service.php';

/**
 * User_meta opt-out flag. Truthy value == this user does NOT want push
 * notifications. Absent/falsy == opted-in (default ON).
 */
const EC_NOTIFICATIONS_PUSH_DISABLED_META = 'ec_notification_push_disabled';

/**
 * Name of the optional wp-native-auth push primitive.
 */
const EC_NOTIFICATIONS_PUSH_PRIMITIVE = 'wp_native_auth_send_push';

// ─── Preference ──────────────────────────────────────────────────────────────

/**
 * Has the user opted out of push notifications?
 *
 * Default is OFF (opted-in). Any truthy value on the opt-out meta == disabled.
 *
 * @param int $user_id User ID.
 * @return bool True if the user should NOT receive push notifications.
 */
function ec_notifications_push_user_opted_out( $user_id ) {
	return (bool) get_user_meta( (int) $user_id, EC_NOTIFICATIONS_PUSH_DISABLED_META, true );
}

/**
 * Canonical setter for the push opt-out flag.
 *
 * THE single source of truth for writing ec_notification_push_disabled.
 * Mirrors ec_users_set_notification_emails_disabled(): enabling removes the
 * flag entirely so the default "opted-in" state is the absence of the meta.
 *
 * @param int  $user_id  User ID.
 * @param bool $disabled True to DISABLE push notifications, false to enable.
 * @return bool True on success, false on an invalid user.
 */
function ec_users_set_notification_push_disabled( $user_id, $disabled ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	if ( $disabled ) {
		update_user_meta( $user_id, EC_NOTIFICATIONS_PUSH_DISABLED_META, 1 );
		return true;
	}

	delete_user_meta( $user_id, EC_NOTIFICATIONS_PUSH_DISABLED_META );
	return true;
}

/**
 * Whether push notifications are ENABLED for a user.
 *
 * User-facing sense: true == the user receives push notifications. Derived
 * from the inverted internal DISABLED flag. Settings UIs bind to this.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_users_notification_push_enabled( $user_id ) {
	return ! ec_notifications_push_user_opted_out( $user_id );
}

// ─── Allow-list ──────────────────────────────────────────────────────────────

/**
 * The push allow-list: notification types that may reach a lock screen.
 *
 * THE single place that decides which substrate `type` values push. Each entry
 * maps a type to:
 *   - `title`: generic, lock-screen-safe title. A single `%s` is replaced with
 *     the actor's display name.
 *   - `scope`: how much of the notification may appear on the lock screen.
 *       'forum'   — community forum item; the stored title becomes the body
 *                   only when the topic and every ancestor forum are public
 *                   and open. Collapses per topic.
 *       'public'  — system-authored text addressed to the recipient about a
 *                   public object (e.g. "Your post is live"); body allowed.
 *       'private' — venue-scoped, booking or review-queue item; never a body.
 *
 * Unknown/missing scope is treated as 'private' (fail closed).
 *
 * @return array<string,array{title:string,scope:string}>
 */
function ec_users_notification_push_types(): array {
	$types = array(
		// Replies, mentions and subscribed topics (extrachill-community).
		'reply'                                 => array(
			/* translators: %s: name of the member who replied. */
			'title' => __( 'New reply from %s', 'extrachill-users' ),
			'scope' => 'forum',
		),
		'subscription'                          => array(
			/* translators: %s: name of the member who replied. */
			'title' => __( 'New reply from %s', 'extrachill-users' ),
			'scope' => 'forum',
		),
		'mention'                               => array(
			/* translators: %s: name of the member who mentioned the recipient. */
			'title' => __( '%s mentioned you', 'extrachill-users' ),
			'scope' => 'forum',
		),

		// Direct replies to the recipient's article or event comment
		// (extrachill-users comment-notify). The body is the published,
		// non-password-protected post title.
		'comment_reply'                         => array(
			/* translators: %s: name of the member who replied. */
			'title' => __( '%s replied to your comment', 'extrachill-users' ),
			'scope' => 'public',
		),

		// Approvals and decisions on the recipient's own requests/submissions.
		'artist_dispatch_approved'              => array(
			'title' => __( 'Artist Dispatch access approved', 'extrachill-users' ),
			'scope' => 'public',
		),
		'artist_dispatch_rejected'              => array(
			'title' => __( 'Artist Dispatch request update', 'extrachill-users' ),
			'scope' => 'public',
		),
		'artist_dispatch_published'             => array(
			'title' => __( 'Your Artist Dispatch is live', 'extrachill-users' ),
			'scope' => 'public',
		),
		'studio_published'                      => array(
			'title' => __( 'Your post is live', 'extrachill-users' ),
			'scope' => 'public',
		),
		'artist_import_approved'                => array(
			'title' => __( 'Artist submission approved', 'extrachill-users' ),
			'scope' => 'public',
		),
		'artist_import_rejected'                => array(
			'title' => __( 'Artist submission update', 'extrachill-users' ),
			'scope' => 'public',
		),
		'event_source_approved'                 => array(
			'title' => __( 'Event source approved', 'extrachill-users' ),
			'scope' => 'private',
		),

		// Requested work: someone is waiting on the recipient.
		'editorial_submission'                  => array(
			'title' => __( 'New submission to review', 'extrachill-users' ),
			'scope' => 'private',
		),
		'guest_list_request'                    => array(
			'title' => __( 'New guest list request', 'extrachill-users' ),
			'scope' => 'private',
		),
		'booking_inquiry_submitted'             => array(
			'title' => __( 'New booking inquiry', 'extrachill-users' ),
			'scope' => 'private',
		),
		'booking_information_received'          => array(
			'title' => __( 'Booking information received', 'extrachill-users' ),
			'scope' => 'private',
		),
		'booking_artist_replied'                => array(
			'title' => __( 'An artist replied about a booking', 'extrachill-users' ),
			'scope' => 'private',
		),
		'booking_artist_correction_requested'   => array(
			'title' => __( 'Booking correction requested', 'extrachill-users' ),
			'scope' => 'private',
		),
		'booking_artist_cancellation_requested' => array(
			'title' => __( 'Booking cancellation requested', 'extrachill-users' ),
			'scope' => 'private',
		),
	);

	/**
	 * Filter the push allow-list.
	 *
	 * Add a direct, meaningful type (with a generic lock-screen title and a
	 * scope) or remove one. Never add upvote, popularity or engagement nudges.
	 *
	 * @param array<string,array{title:string,scope:string}> $types Allow-list keyed by type.
	 */
	$types = apply_filters( 'ec_users_notification_push_types', $types );

	return is_array( $types ) ? $types : array();
}

// ─── Channel ─────────────────────────────────────────────────────────────────

/**
 * Push a freshly inserted notification to its recipient.
 *
 * Listener for `ec_users_notification_inserted`, which the substrate fires
 * only for genuine inserts (never for idempotent replays). Never throws: a push
 * failure must not affect notification insertion.
 *
 * @param int   $notification_id Inserted notification row ID.
 * @param int   $user_id         Recipient user ID.
 * @param array $notification    Sanitized payload (actor_id, type, title, link, item_id).
 * @return array|null Primitive result when a push was handed off, null otherwise.
 */
function ec_users_notification_push_on_insert( $notification_id, $user_id, $notification ) {
	$notification_id = (int) $notification_id;
	$user_id         = (int) $user_id;

	if ( $notification_id <= 0 || $user_id <= 0 || ! is_array( $notification ) ) {
		return null;
	}

	// Optional dependency: without wp-native-auth this channel does nothing.
	if ( ! function_exists( EC_NOTIFICATIONS_PUSH_PRIMITIVE ) ) {
		return null;
	}

	if ( ec_notifications_push_user_opted_out( $user_id ) ) {
		return null;
	}

	$message = ec_users_notification_push_build_message( $notification_id, $notification );
	if ( null === $message ) {
		return null;
	}

	try {
		$result = call_user_func( EC_NOTIFICATIONS_PUSH_PRIMITIVE, $user_id, $message );
	} catch ( \Throwable $e ) {
		error_log( sprintf( 'ec_notifications_push: send failed for user %d, notification %d: %s', $user_id, $notification_id, substr( $e->getMessage(), 0, 200 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- canonical operational logging surface.
		return null;
	}

	return is_array( $result ) ? $result : array();
}
add_action( 'ec_users_notification_inserted', 'ec_users_notification_push_on_insert', 10, 3 );

/**
 * Build the lock-screen-safe push message for a notification.
 *
 * Pure with respect to delivery (no sending); returns null when the type is
 * not on the allow-list.
 *
 * @param int   $notification_id Notification row ID.
 * @param array $notification    Sanitized payload (actor_id, type, title, link, item_id).
 * @return array{title:string, body?:string, data:array{notification_id:int,type:string,url:string}, collapse_id:string}|null
 */
function ec_users_notification_push_build_message( int $notification_id, array $notification ): ?array {
	$type  = isset( $notification['type'] ) ? (string) $notification['type'] : '';
	$types = ec_users_notification_push_types();

	if ( '' === $type || ! isset( $types[ $type ] ) || ! is_array( $types[ $type ] ) ) {
		return null;
	}

	$config  = $types[ $type ];
	$scope   = isset( $config['scope'] ) ? (string) $config['scope'] : 'private';
	$item_id = isset( $notification['item_id'] ) ? (int) $notification['item_id'] : 0;
	$link    = isset( $notification['link'] ) ? (string) $notification['link'] : '';

	$title = isset( $config['title'] ) ? (string) $config['title'] : '';
	if ( '' === $title ) {
		$title = __( 'New notification on Extra Chill', 'extrachill-users' );
	}
	if ( false !== strpos( $title, '%s' ) ) {
		$title = sprintf( $title, ec_users_notification_push_actor_name( isset( $notification['actor_id'] ) ? (int) $notification['actor_id'] : 0 ) );
	}

	$forum_item = 'forum' === $scope ? ec_users_notification_push_resolve_forum_item( $item_id ) : null;

	if ( 'forum' === $scope ) {
		$private = ! ( $forum_item && $forum_item['public'] );
	} else {
		$private = 'public' !== $scope;
	}

	/**
	 * Filter whether a notification is too private for lock-screen content.
	 *
	 * Returning true strips the body so only the generic title shows.
	 *
	 * @param bool   $private         Whether the item is private.
	 * @param array  $notification    Sanitized notification payload.
	 * @param string $scope           Allow-list scope for the type.
	 * @param int    $notification_id Notification row ID.
	 */
	$private = (bool) apply_filters( 'ec_users_notification_push_is_private', $private, $notification, $scope, $notification_id );

	if ( $forum_item && $forum_item['topic_id'] > 0 ) {
		$collapse_id = 'ec-topic-' . $forum_item['topic_id'];
	} elseif ( $item_id > 0 ) {
		$collapse_id = 'ec-' . $type . '-' . $item_id;
	} else {
		$collapse_id = 'ec-notification-' . $notification_id;
	}

	$message = array(
		'title'       => wp_strip_all_tags( $title ),
		'data'        => array(
			'notification_id' => $notification_id,
			'type'            => $type,
			'url'             => $link,
		),
		'collapse_id' => substr( $collapse_id, 0, 64 ),
	);

	$body = isset( $notification['title'] ) ? trim( wp_strip_all_tags( (string) $notification['title'] ) ) : '';
	if ( ! $private && '' !== $body ) {
		$message['body'] = $body;
	}

	return $message;
}

/**
 * Display name for the actor, or a neutral fallback.
 *
 * @param int $actor_id Actor user ID.
 * @return string
 */
function ec_users_notification_push_actor_name( int $actor_id ): string {
	$actor = $actor_id > 0 ? get_userdata( $actor_id ) : false;
	if ( $actor && '' !== (string) $actor->display_name ) {
		return wp_strip_all_tags( (string) $actor->display_name );
	}

	return __( 'someone', 'extrachill-users' );
}

/**
 * Blog ID that owns forum items (topics/replies).
 *
 * @return int Blog ID, or 0 when it cannot be resolved.
 */
function ec_users_notification_push_forum_blog_id(): int {
	$blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'community' ) : 0;

	/**
	 * Filter the blog that owns forum notification items.
	 *
	 * @param int $blog_id Community blog ID.
	 */
	return (int) apply_filters( 'ec_users_notification_push_forum_blog_id', $blog_id );
}

/**
 * Resolve a forum notification item: its topic and whether it is public.
 *
 * Public == the item, and every ancestor (topic, parent forums), is published
 * and none is marked closed. bbPress private/hidden forums use the `private` /
 * `hidden` post statuses and closed topics use `closed`, so anything other than
 * `publish` fails. Closed forums carry `_bbp_status = closed`. Unresolvable
 * items (missing blog/post) fail closed.
 *
 * @param int $item_id Topic or reply ID on the community blog.
 * @return array{topic_id:int, public:bool}|null Null when the item cannot be resolved.
 */
function ec_users_notification_push_resolve_forum_item( int $item_id ): ?array {
	if ( $item_id <= 0 ) {
		return null;
	}

	$blog_id  = ec_users_notification_push_forum_blog_id();
	$switched = false;

	if ( is_multisite() && $blog_id > 0 && get_current_blog_id() !== $blog_id ) {
		if ( ! get_site( $blog_id ) ) {
			return null;
		}
		switch_to_blog( $blog_id );
		$switched = true;
	} elseif ( is_multisite() && $blog_id <= 0 ) {
		return null;
	}

	try {
		$post = get_post( $item_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$topic_id = ( 'reply' === $post->post_type && $post->post_parent > 0 ) ? (int) $post->post_parent : (int) $post->ID;
		$public   = true;

		$chain = array_merge( array( (int) $post->ID ), array_map( 'intval', get_post_ancestors( $post ) ) );
		foreach ( $chain as $chain_id ) {
			$node = get_post( $chain_id );
			if ( ! $node instanceof WP_Post || 'publish' !== $node->post_status || 'closed' === get_post_meta( $chain_id, '_bbp_status', true ) ) {
				$public = false;
				break;
			}
		}

		return array(
			'topic_id' => $topic_id,
			'public'   => $public,
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}
