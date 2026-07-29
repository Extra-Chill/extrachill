<?php
/**
 * Entity pillar subscription controls and publication notifications.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_BLOG_ENTITY_SUBSCRIPTION_PRODUCER        = 'extrachill-blog';
const EXTRACHILL_BLOG_FESTIVAL_SUBSCRIPTION_NOTIFIED_META = '_extrachill_blog_festival_subscriptions_notified';
const EXTRACHILL_BLOG_ARTIST_SUBSCRIPTION_NOTIFIED_META   = '_extrachill_blog_artist_subscriptions_notified';

/**
 * Allow this plugin to resolve private festival subscription recipients.
 *
 * @param bool   $authorized Whether the producer is already authorized.
 * @param string $producer   Producer requesting recipients.
 * @return bool
 */
function extrachill_blog_authorize_festival_subscription_producer( $authorized, $producer ) {
	return $authorized || EXTRACHILL_BLOG_ENTITY_SUBSCRIPTION_PRODUCER === $producer;
}
add_filter( 'extrachill_users_entity_subscription_producer_authorized', 'extrachill_blog_authorize_festival_subscription_producer', 10, 2 );

/**
 * Render the private festival notification subscription control.
 *
 * @return void
 */
function extrachill_blog_render_festival_subscription_control() {
	if ( ! extrachill_blog_is_festival_pillar() || is_paged() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		?>
		<section class="entity-pillar-subscription" aria-labelledby="festival-pillar-subscription-title">
			<h2 id="festival-pillar-subscription-title"><?php esc_html_e( 'Get festival updates', 'extrachill-blog' ); ?></h2>
			<p><?php esc_html_e( 'Log in to subscribe to private Extra Chill notifications for this festival.', 'extrachill-blog' ); ?></p>
			<a class="button-1 button-medium entity-pillar-subscription-button" href="<?php echo esc_url( wp_login_url( get_term_link( $term ) ) ); ?>"><?php esc_html_e( 'Log in to subscribe', 'extrachill-blog' ); ?></a>
		</section>
		<?php
		return;
	}

	wp_enqueue_script( 'extrachill-blog-entity-subscriptions' );
	?>
	<section class="entity-pillar-subscription" data-entity-subscription-control aria-labelledby="festival-pillar-subscription-title">
		<h2 id="festival-pillar-subscription-title"><?php esc_html_e( 'Get festival updates', 'extrachill-blog' ); ?></h2>
		<p><?php esc_html_e( 'Subscribe to private Extra Chill notifications for new editorial coverage of this festival.', 'extrachill-blog' ); ?></p>
		<button
			class="button-1 button-medium entity-pillar-subscription-button"
			type="button"
			aria-pressed="false"
			disabled
			data-entity-type="festival"
			data-taxonomy="festival"
			data-entity-subscription
			data-slug="<?php echo esc_attr( $term->slug ); ?>"
			data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		>
			<?php esc_html_e( 'Subscribe to updates', 'extrachill-blog' ); ?>
		</button>
		<p class="entity-pillar-subscription-status" aria-live="polite"></p>
	</section>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_blog_render_festival_subscription_control', 5 );

/**
 * Get canonical entity terms assigned to a main editorial post.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return WP_Term[] Entity terms.
 */
function extrachill_blog_get_post_entity_terms( $post_id, $taxonomy ) {
	$terms = wp_get_post_terms( $post_id, $taxonomy );

	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Get canonical festival terms assigned to a main editorial post.
 *
 * @param int $post_id Post ID.
 * @return WP_Term[] Festival terms.
 */
function extrachill_blog_get_post_festival_terms( $post_id ) {
	return extrachill_blog_get_post_entity_terms( $post_id, 'festival' );
}

/**
 * Notify unique entity subscribers only when a main post first publishes.
 *
 * @param string  $new_status         New post status.
 * @param string  $old_status         Previous post status.
 * @param WP_Post $post               Published post.
 * @param string  $entity_type        Entity type and taxonomy slug.
 * @param string  $notification_type  Notification type.
 * @param string  $notification_title Translated notification title template.
 * @param string  $notified_meta      Post meta key that claims first publication.
 * @return void
 */
function extrachill_blog_notify_entity_subscribers( $new_status, $old_status, $post, $entity_type, $notification_type, $notification_title, $notified_meta ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status || ! is_object( $post ) || 'post' !== $post->post_type ) {
		return;
	}
	$is_main_site = function_exists( 'ec_get_current_site_key' )
		? 'main' === ec_get_current_site_key()
		: 1 === (int) get_current_blog_id();
	if ( ! $is_main_site ) {
		return;
	}

	if ( get_post_meta( $post->ID, $notified_meta, true ) ) {
		return;
	}

	$terms = extrachill_blog_get_post_entity_terms( $post->ID, $entity_type );
	if ( empty( $terms ) || ! function_exists( 'extrachill_users_entity_subscription_recipients' ) || ! function_exists( 'ec_users_notify_with_receipts' ) ) {
		return;
	}

	$recipient_ids = array();
	foreach ( $terms as $term ) {
		if ( ! ( $term instanceof WP_Term ) ) {
			continue;
		}

		$recipients = extrachill_users_entity_subscription_recipients(
			EXTRACHILL_BLOG_ENTITY_SUBSCRIPTION_PRODUCER,
			$entity_type,
			$entity_type,
			$term->slug
		);
		if ( ! is_wp_error( $recipients ) ) {
			$recipient_ids = array_merge( $recipient_ids, $recipients );
		}
	}

	$recipient_ids = array_values( array_unique( array_map( 'absint', $recipient_ids ) ) );
	$recipient_ids = array_filter( $recipient_ids );
	if ( empty( $recipient_ids ) ) {
		update_post_meta( $post->ID, $notified_meta, current_time( 'mysql', true ) );
		return;
	}

	$actor_id = (int) $post->post_author;
	if ( ! get_userdata( $actor_id ) && function_exists( 'ec_get_network_bot_user_id' ) ) {
		$actor_id = ec_get_network_bot_user_id();
	}
	if ( $actor_id <= 0 || ! get_userdata( $actor_id ) ) {
		return;
	}

	// Claim before delivery; canonical receipts make a released claim safe to retry.
	if ( ! add_post_meta( $post->ID, $notified_meta, current_time( 'mysql', true ), true ) ) {
		return;
	}

	$receipt = ec_users_notify_with_receipts(
		$recipient_ids,
		array(
			'actor_id'        => $actor_id,
			'type'            => $notification_type,
			/* translators: %s: post title. */
			'title'           => sprintf( $notification_title, get_the_title( $post ) ),
			'link'            => get_permalink( $post ),
			'item_id'         => (int) $post->ID,
			'producer'        => EXTRACHILL_BLOG_ENTITY_SUBSCRIPTION_PRODUCER,
			'idempotency_key' => 'post:' . (int) $post->ID . ':' . $notification_type,
		)
	);

	$recipient_receipts = is_array( $receipt ) && is_array( $receipt['recipients'] ?? null ) ? $receipt['recipients'] : array();
	foreach ( $recipient_ids as $recipient_id ) {
		$status = is_array( $recipient_receipts[ $recipient_id ] ?? null ) ? ( $recipient_receipts[ $recipient_id ]['status'] ?? '' ) : '';
		if ( ! in_array( $status, array( 'inserted', 'existing' ), true ) ) {
			delete_post_meta( $post->ID, $notified_meta );
			return;
		}
	}
}

/**
 * Notify festival subscribers only when a main post first publishes.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       Published post.
 * @return void
 */
function extrachill_blog_notify_festival_subscribers( $new_status, $old_status, $post ) {
	extrachill_blog_notify_entity_subscribers(
		$new_status,
		$old_status,
		$post,
		'festival',
		'festival_update',
		/* translators: %s: post title. */
		__( 'New festival update: %s', 'extrachill-blog' ),
		EXTRACHILL_BLOG_FESTIVAL_SUBSCRIPTION_NOTIFIED_META
	);
}
add_action( 'transition_post_status', 'extrachill_blog_notify_festival_subscribers', 10, 3 );

/**
 * Notify artist subscribers only when a main post first publishes.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       Published post.
 * @return void
 */
function extrachill_blog_notify_artist_subscribers( $new_status, $old_status, $post ) {
	extrachill_blog_notify_entity_subscribers(
		$new_status,
		$old_status,
		$post,
		'artist',
		'artist_update',
		/* translators: %s: post title. */
		__( 'New artist update: %s', 'extrachill-blog' ),
		EXTRACHILL_BLOG_ARTIST_SUBSCRIPTION_NOTIFIED_META
	);
}
add_action( 'transition_post_status', 'extrachill_blog_notify_artist_subscribers', 10, 3 );
