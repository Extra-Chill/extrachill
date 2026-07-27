<?php
/**
 * Runtime stubs for venue update subscription tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress test doubles.

if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) ) {
	/** Record private recipient resolution and return configured fixtures. */
	function extrachill_users_entity_subscription_recipients( $producer, $entity_type, $taxonomy, $slug, $delivery = 'notification' ) {
		$GLOBALS['festival_notification_resolutions'][] = compact( 'producer', 'entity_type', 'taxonomy', 'slug', 'delivery' );
		return $GLOBALS['festival_notification_recipients'][ $slug ] ?? array();
	}
}

if ( ! function_exists( 'ec_users_notify_with_receipts' ) ) {
	/** Record notification delivery and return its configured result. */
	function ec_users_notify_with_receipts( $user_ids, array $data ) {
		$GLOBALS['festival_notification_calls'][] = array(
			'user_ids' => $user_ids,
			'data'     => $data,
		);
		$inserted = array_shift( $GLOBALS['festival_notification_delivery_results'] ) ?? count( $user_ids );
		return array(
			'requested'  => count( $user_ids ),
			'inserted'   => $inserted,
			'existing'   => 0,
			'failed'     => count( $user_ids ) - $inserted,
			'recipients' => array(),
		);
	}
}
