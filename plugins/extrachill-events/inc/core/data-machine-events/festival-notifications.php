<?php
/**
 * Event entity notifications.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER = 'extrachill-events-festival-notifications';
const EXTRACHILL_EVENTS_NEARBY_ARTIST_EVENT_NOTIFICATION = 'nearby_artist_event_published';

/**
 * Register event entity notification hooks.
 *
 * @return void
 */
function extrachill_events_init_festival_notifications(): void {
	add_filter( 'extrachill_users_entity_subscription_producer_authorized', 'extrachill_events_authorize_festival_notification_producer', 10, 4 );
	add_action( 'transition_post_status', 'extrachill_events_notify_festival_subscribers', 10, 3 );
}

/**
 * Authorize this plugin to resolve private event entity notification recipients.
 *
 * @param bool   $authorized Whether the producer is already authorized.
 * @param string $producer   Producer identifier.
 * @param array  $entity     Normalized entity identity.
 * @param string $delivery   Requested delivery channel.
 * @return bool Whether the producer may resolve recipients.
 */
function extrachill_events_authorize_festival_notification_producer( $authorized, $producer, $entity, $delivery ): bool {
	if ( EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER !== $producer || 'notification' !== $delivery ) {
		return (bool) $authorized;
	}

	return is_array( $entity ) && in_array( $entity['entity_type'] ?? '', array( 'artist', 'festival' ), true ) && ( $entity['entity_type'] ?? '' ) === ( $entity['taxonomy'] ?? '' );
}

/**
 * Notify artist and festival subscribers when an event first becomes published.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Previous post status.
 * @param \WP_Post $post       Post transitioning status.
 * @return void
 */
function extrachill_events_notify_festival_subscribers( $new_status, $old_status, $post ): void {
	if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post ) {
		return;
	}

	if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) || DATA_MACHINE_EVENTS_POST_TYPE !== get_post_type( $post ) ) {
		return;
	}

	if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) || ! function_exists( 'ec_users_notify' ) ) {
		return;
	}

	$recipient_ids        = array();
	$artist_recipient_ids = array();
	foreach ( array( 'artist', 'festival' ) as $taxonomy ) {
		$slugs = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
			continue;
		}

		foreach ( $slugs as $slug ) {
			$recipients = extrachill_users_entity_subscription_recipients(
				EXTRACHILL_EVENTS_FESTIVAL_NOTIFICATION_PRODUCER,
				$taxonomy,
				$taxonomy,
				$slug
			);
			if ( is_wp_error( $recipients ) ) {
				continue;
			}

			$recipient_ids = array_merge( $recipient_ids, $recipients );
			if ( 'artist' === $taxonomy ) {
				$artist_recipient_ids = array_merge( $artist_recipient_ids, $recipients );
			}
		}
	}

	$recipient_ids = array_values( array_unique( array_map( 'absint', $recipient_ids ) ) );
	if ( empty( $recipient_ids ) ) {
		return;
	}

	$artist_recipient_ids = array_values( array_unique( array_map( 'absint', $artist_recipient_ids ) ) );
	$nearby_recipient_ids = extrachill_events_get_nearby_artist_event_recipients( $post, $artist_recipient_ids );
	$general_recipient_ids = array_values( array_diff( $recipient_ids, $nearby_recipient_ids ) );

	if ( ! empty( $general_recipient_ids ) ) {
		ec_users_notify(
			$general_recipient_ids,
			array(
				'actor_id' => (int) $post->post_author,
				'type'     => 'festival_event_published',
				/* translators: %s: event title. */
				'title'    => sprintf( __( 'New event: %s', 'extrachill-events' ), get_the_title( $post ) ),
				'link'     => get_permalink( $post ),
				'item_id'  => (int) $post->ID,
			)
		);
	}

	if ( empty( $nearby_recipient_ids ) ) {
		return;
	}

	ec_users_notify(
		$nearby_recipient_ids,
		array(
			'actor_id' => (int) $post->post_author,
			'type'     => EXTRACHILL_EVENTS_NEARBY_ARTIST_EVENT_NOTIFICATION,
			/* translators: %s: event title. */
			'title'    => sprintf( __( 'Nearby show: %s', 'extrachill-events' ), get_the_title( $post ) ),
			'link'     => get_permalink( $post ),
			'item_id'  => (int) $post->ID,
		)
	);
}

/**
 * Get artist subscribers whose private Local Scene matches this event.
 *
 * Recipient IDs are resolved through Users' authorized private subscription
 * service before this runs. Local Scene values stay in-process and are used
 * only to select a truthful notification variant.
 *
 * @param \WP_Post $post          Published event.
 * @param int[]    $recipient_ids Artist subscription recipient IDs.
 * @return int[] Nearby artist subscription recipient IDs.
 */
function extrachill_events_get_nearby_artist_event_recipients( \WP_Post $post, array $recipient_ids ): array {
	if ( empty( $recipient_ids ) || ! function_exists( 'extrachill_users_get_local_scene' ) ) {
		return array();
	}

	$location_slugs = wp_get_post_terms( $post->ID, 'location', array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $location_slugs ) || empty( $location_slugs ) ) {
		return array();
	}

	$location_slugs = array_filter( array_map( 'sanitize_title', $location_slugs ) );
	if ( empty( $location_slugs ) ) {
		return array();
	}

	$nearby_recipient_ids = array();
	foreach ( $recipient_ids as $recipient_id ) {
		$scene = extrachill_users_get_local_scene( absint( $recipient_id ) );
		if ( is_wp_error( $scene ) || ! is_array( $scene ) ) {
			continue;
		}

		$scene_slug = sanitize_title( $scene['slug'] ?? '' );
		if ( '' !== $scene_slug && in_array( $scene_slug, $location_slugs, true ) ) {
			$nearby_recipient_ids[] = absint( $recipient_id );
		}
	}

	return array_values( array_unique( $nearby_recipient_ids ) );
}
