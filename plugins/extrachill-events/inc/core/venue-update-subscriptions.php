<?php
/**
 * Explicit private venue update subscriptions.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_EVENTS_VENUE_UPDATE_PRODUCER  = 'extrachill-events-venue-updates';
const EXTRACHILL_EVENTS_VENUE_UPDATE_SENT_META = '_extrachill_events_venue_update_notification_sent';

/** Register venue archive controls and private notification delivery. */
function extrachill_events_init_venue_update_subscriptions(): void {
	add_filter( 'extrachill_users_entity_subscription_producer_authorized', 'extrachill_events_authorize_venue_update_producer', 10, 4 );
	add_action( 'transition_post_status', 'extrachill_events_notify_venue_subscribers', 10, 3 );
	add_action( 'extrachill_archive_below_description', 'extrachill_events_render_venue_update_control', 5 );
	add_action( 'wp_enqueue_scripts', 'extrachill_events_venue_update_scripts' );
}

/**
 * Authorize only private notification delivery for canonical venue updates.
 *
 * @param bool   $authorized Existing authorization decision.
 * @param string $producer   Requesting producer.
 * @param array  $entity     Normalized entity identity.
 * @param string $delivery   Delivery channel.
 * @return bool
 */
function extrachill_events_authorize_venue_update_producer( $authorized, $producer, $entity, $delivery ): bool {
	if ( EXTRACHILL_EVENTS_VENUE_UPDATE_PRODUCER !== $producer ) {
		return (bool) $authorized;
	}

	return 'notification' === $delivery
		&& is_array( $entity )
		&& 'venue' === ( $entity['entity_type'] ?? '' )
		&& 'venue' === ( $entity['taxonomy'] ?? '' );
}

/**
 * Return the canonical venue represented by the current archive.
 *
 * @return WP_Term|null
 */
function extrachill_events_get_venue_archive_term(): ?WP_Term {
	if ( ! is_tax( 'venue' ) ) {
		return null;
	}

	$term = get_queried_object();
	return $term instanceof WP_Term && 'venue' === $term->taxonomy ? $term : null;
}

/** Render an explicit venue update opt-in on canonical venue archives. */
function extrachill_events_render_venue_update_control(): void {
	$term = extrachill_events_get_venue_archive_term();
	if ( null === $term ) {
		return;
	}

	$archive_url = get_term_link( $term );
	if ( ! is_user_logged_in() ) {
		echo '<aside class="events-market-context events-market-context--quiet"><span>' . esc_html__( 'Get notified when new events are published at this venue.', 'extrachill-events' ) . '</span> <a href="' . esc_url( wp_login_url( $archive_url ) ) . '">' . esc_html__( 'Sign in to subscribe', 'extrachill-events' ) . '</a></aside>';
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	?>
	<aside class="events-market-context" data-venue-update-control>
		<div class="events-market-context__copy">
			<strong><?php esc_html_e( 'Venue updates', 'extrachill-events' ); ?></strong>
			<span data-venue-update-status><?php esc_html_e( 'Checking your subscription...', 'extrachill-events' ); ?></span>
		</div>
		<button class="button-1 button-small" type="button" disabled aria-pressed="false" data-venue-update-subscription data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-slug="<?php echo esc_attr( $term->slug ); ?>"><?php esc_html_e( 'Subscribe to updates', 'extrachill-events' ); ?></button>
	</aside>
	<?php
}

/** Enqueue the progressive control only for authenticated venue archives. */
function extrachill_events_venue_update_scripts(): void {
	if ( null === extrachill_events_get_venue_archive_term() || ! is_user_logged_in() ) {
		return;
	}

	wp_enqueue_script( 'extrachill-events-venue-update-subscriptions', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/venue-update-subscriptions.js', array(), EXTRACHILL_EVENTS_VERSION, true );
}

/**
 * Notify unique subscribers when an event first becomes published.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       Post transitioning status.
 */
function extrachill_events_notify_venue_subscribers( $new_status, $old_status, $post ): void {
	if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post ) {
		return;
	}
	if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) || DATA_MACHINE_EVENTS_POST_TYPE !== get_post_type( $post ) ) {
		return;
	}
	if ( get_post_meta( $post->ID, EXTRACHILL_EVENTS_VENUE_UPDATE_SENT_META, true ) ) {
		return;
	}
	if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) || ! function_exists( 'ec_users_notify_with_receipts' ) ) {
		return;
	}

	$slugs = wp_get_post_terms( $post->ID, 'venue', array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
		return;
	}

	$recipient_ids = array();
	foreach ( array_values( array_unique( array_filter( array_map( 'sanitize_title', $slugs ) ) ) ) as $slug ) {
		$recipients = extrachill_users_entity_subscription_recipients(
			EXTRACHILL_EVENTS_VENUE_UPDATE_PRODUCER,
			'venue',
			'venue',
			$slug,
			'notification'
		);
		if ( ! is_wp_error( $recipients ) ) {
			$recipient_ids = array_merge( $recipient_ids, $recipients );
		}
	}

	$recipient_ids = array_values( array_unique( array_filter( array_map( 'absint', $recipient_ids ) ) ) );
	if ( empty( $recipient_ids ) ) {
		return;
	}

	// Claim before delivery so concurrent publication hooks cannot duplicate notices.
	if ( ! add_post_meta( $post->ID, EXTRACHILL_EVENTS_VENUE_UPDATE_SENT_META, current_time( 'mysql', true ), true ) ) {
		return;
	}

	$receipt = ec_users_notify_with_receipts(
		$recipient_ids,
		array(
			'actor_id'        => (int) $post->post_author,
			'type'            => 'venue_event_published',
			/* translators: %s: event title. */
			'title'           => sprintf( __( 'New venue event: %s', 'extrachill-events' ), get_the_title( $post ) ),
			'link'            => get_permalink( $post ),
			'item_id'         => (int) $post->ID,
			'producer'        => EXTRACHILL_EVENTS_VENUE_UPDATE_PRODUCER,
			'idempotency_key' => 'event:' . (int) $post->ID,
		)
	);

	if ( ! is_array( $receipt ) || 0 < absint( $receipt['failed'] ?? count( $recipient_ids ) ) ) {
		delete_post_meta( $post->ID, EXTRACHILL_EVENTS_VENUE_UPDATE_SENT_META );
	}
}
