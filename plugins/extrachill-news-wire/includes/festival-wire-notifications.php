<?php
/**
 * Festival Wire entity-subscription notifications.
 *
 * @package ExtraChillNewsWire
 */

defined( 'ABSPATH' ) || exit;

const EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_PRODUCER  = 'festival_wire';
const EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_SENT_META = '_ec_news_wire_festival_notification_sent';

/**
 * Authorize this plugin to resolve festival subscription recipients.
 *
 * @param bool   $authorized Whether a previous producer authorized the request.
 * @param string $producer   Producer identifier.
 * @return bool Whether the producer is authorized.
 */
function ec_news_wire_authorize_festival_notification_producer( $authorized, $producer ) {
	return $authorized || EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_PRODUCER === $producer;
}
add_filter( 'extrachill_users_entity_subscription_producer_authorized', 'ec_news_wire_authorize_festival_notification_producer', 10, 2 );

/**
 * Notify festival subscribers when a Festival Wire post first publishes.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Previous post status.
 * @param \WP_Post $post       Published post.
 * @return void
 */
function ec_news_wire_notify_festival_subscribers_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status || 'festival_wire' !== $post->post_type ) {
		return;
	}

	if ( get_post_meta( $post->ID, EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_SENT_META, true ) ) {
		return;
	}

	if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) || ! function_exists( 'ec_users_notify_with_receipts' ) ) {
		return;
	}

	$festival_slugs = wp_get_post_terms( $post->ID, 'festival', array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $festival_slugs ) || ! $festival_slugs ) {
		return;
	}

	// Claim before delivery so concurrent publication hooks cannot duplicate notices.
	if ( ! add_post_meta( $post->ID, EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_SENT_META, 1, true ) ) {
		return;
	}

	$recipient_ids = array();
	foreach ( $festival_slugs as $festival_slug ) {
		$recipients = extrachill_users_entity_subscription_recipients(
			EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_PRODUCER,
			'festival',
			'festival',
			$festival_slug
		);

		if ( ! is_wp_error( $recipients ) ) {
			$recipient_ids = array_merge( $recipient_ids, array_map( 'absint', $recipients ) );
		}
	}

	$recipient_ids = array_values( array_unique( array_filter( $recipient_ids ) ) );
	if ( ! $recipient_ids ) {
		return;
	}

	$receipt = ec_users_notify_with_receipts(
		$recipient_ids,
		array(
			'actor_id'        => (int) $post->post_author,
			'type'            => 'festival_wire_update',
			'title'           => sprintf(
				/* translators: %s: Festival Wire post title. */
				__( 'New Festival Wire update: %s', 'extrachill' ),
				get_the_title( $post->ID )
			),
			'link'            => get_permalink( $post ),
			'item_id'         => $post->ID,
			'producer'        => EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_PRODUCER,
			'idempotency_key' => 'post:' . (int) $post->ID,
		)
	);

	if ( 0 < $receipt['failed'] ) {
		delete_post_meta( $post->ID, EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_SENT_META );
	}
}
add_action( 'transition_post_status', 'ec_news_wire_notify_festival_subscribers_on_publish', 10, 3 );
