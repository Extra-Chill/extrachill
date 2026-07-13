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

	$recipient_ids = array();
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
		}
	}

	$recipient_ids = array_values( array_unique( array_map( 'absint', $recipient_ids ) ) );
	if ( empty( $recipient_ids ) ) {
		return;
	}

	ec_users_notify(
		$recipient_ids,
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
