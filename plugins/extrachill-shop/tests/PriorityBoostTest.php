<?php
/**
 * Tests for owner-bound commerce state.
 *
 * @package ExtraChillShop
 */

// phpcs:disable WordPress.Files.FileName
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use PHPUnit\Framework\TestCase;

final class PriorityBoostTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_orders']         = array();
		$GLOBALS['test_options']        = array();
		$GLOBALS['test_owner_calls']    = array();
		$GLOBALS['test_assertions']     = array();
		$GLOBALS['test_user_id']        = 0;
		$GLOBALS['test_owner_callback'] = static function () {
			return priority_boost_owner_response();
		};
	}

	public function test_purchase_uses_events_owner_ability_and_stores_receipt(): void {
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertCount( 1, $GLOBALS['test_owner_calls'] );
		$call = $GLOBALS['test_owner_calls'][0];
		$this->assertSame( 'events', $call['site'] );
		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/grant-event-priority-boost/run', $call['route'] );
		$this->assertSame( 0, $call['args']['user_id'] );
		$this->assertSame(
			array(
				'service_id' => 'extrachill.events.priority-boost',
				'scope'      => 'extrachill/events:priority-boost',
			),
			$call['args']['service_assertion']
		);
		$this->assertSame(
			array(
				'event'              => 'event-slug',
				'external_reference' => 'shop-order:44',
				'idempotency_key'    => 'shop-order:44:item:10',
			),
			$call['args']['body']['input']
		);
		$this->assertSame( priority_boost_owner_response()['receipt'], $order->meta['_extrachill_priority_boost_receipt_10'] );
		$this->assertSame( array( 'Priority boost granted for event: Event title' ), $order->notes );
	}

	public function test_paid_fulfillment_as_user_zero_requests_service_authority(): void {
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertSame( 0, $GLOBALS['test_user_id'] );
		$this->assertSame( array( 'assertion-1' ), $GLOBALS['test_assertions'] );
	}

	/** @dataProvider untrustedCallerProvider */
	public function test_unpaid_anonymous_and_customer_contexts_cannot_request_authority( $user_id ): void {
		$GLOBALS['test_user_id'] = $user_id;
		$order                    = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ), false );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
		$this->assertSame( array(), $GLOBALS['test_assertions'] );
		$this->assertSame( array( 'Priority boost pending: Events owner returned priority_boost_order_not_paid.' ), $order->notes );
	}

	public function untrustedCallerProvider(): array {
		return array( 'anonymous' => array( 0 ), 'customer' => array( 42 ) );
	}

	public function test_exact_replay_does_not_call_owner_again(): void {
		$order = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$order->meta['_extrachill_priority_boost_receipt_10'] = array( 'operation_id' => 'existing' );
		$GLOBALS['test_orders'][44]                           = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
		$this->assertSame( array(), $order->notes );
	}

	public function test_conflicting_replay_remains_pending(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return new WP_Error( 'priority_boost_idempotency_conflict', 'Conflict.' );
		};
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 102, 'other-event', 'Other event' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertArrayNotHasKey( '_extrachill_priority_boost_receipt_10', $order->meta );
		$this->assertSame( array( 'Priority boost pending: Events owner returned priority_boost_idempotency_conflict.' ), $order->notes );
	}

	public function test_missing_owner_ability_remains_pending(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return new WP_Error( 'rest_ability_not_found', 'Ability not found.' );
		};
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertArrayNotHasKey( '_extrachill_priority_boost_receipt_10', $order->meta );
		$this->assertSame( array( 'Priority boost pending: Events owner returned rest_ability_not_found.' ), $order->notes );
	}

	public function test_owner_error_does_not_leak_message_into_order_note(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return new WP_Error( 'events_internal_error', 'Sensitive owner detail.' );
		};
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertStringNotContainsString( 'Sensitive', implode( ' ', $order->notes ) );
		$this->assertStringContainsString( 'events_internal_error', $order->notes[0] );
	}

	public function test_transport_assertion_failure_retries_with_fresh_assertion(): void {
		$attempt = 0;
		$GLOBALS['test_owner_callback'] = static function () use ( &$attempt ) {
			++$attempt;
			return 1 === $attempt
				? new WP_Error( 'ec_service_assertion_unavailable', 'Assertion provider detail.' )
				: priority_boost_owner_response();
		};
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );
		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertSame( array( 'assertion-1', 'assertion-2' ), $GLOBALS['test_assertions'] );
		$this->assertCount( 2, $GLOBALS['test_owner_calls'] );
		$this->assertArrayHasKey( '_extrachill_priority_boost_receipt_10', $order->meta );
		$this->assertStringNotContainsString( 'provider detail', implode( ' ', $order->notes ) );
	}

	public function test_fresh_transport_attempts_preserve_events_business_idempotency(): void {
		$attempt = 0;
		$GLOBALS['test_owner_callback'] = static function () use ( &$attempt ) {
			return priority_boost_owner_response( 0 < $attempt++ );
		};
		$order = new PriorityBoostTestOrder( 44, array() );
		$item  = new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' );

		$first  = extrachill_shop_grant_event_priority_boost( $order, $item );
		$second = extrachill_shop_grant_event_priority_boost( $order, $item );

		$this->assertFalse( $first['replayed'] );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( array( 'assertion-1', 'assertion-2' ), $GLOBALS['test_assertions'] );
		$this->assertSame( $GLOBALS['test_owner_calls'][0]['args']['body'], $GLOBALS['test_owner_calls'][1]['args']['body'] );
	}

	public function test_invalid_owner_response_remains_pending_without_leaking_fields(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			$response                   = priority_boost_owner_response();
			$response['payment_secret'] = 'must-not-leak';
			return $response;
		};
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertArrayNotHasKey( '_extrachill_priority_boost_receipt_10', $order->meta );
		$this->assertSame( array( 'Priority boost pending: Events owner returned priority_boost_owner_response_invalid.' ), $order->notes );
		$this->assertStringNotContainsString( 'must-not-leak', serialize( $order ) );
	}

	public function test_mismatched_owner_event_response_remains_pending(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			$response                  = priority_boost_owner_response();
			$response['event']['slug'] = 'different-event';
			return $response;
		};
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertArrayNotHasKey( '_extrachill_priority_boost_receipt_10', $order->meta );
		$this->assertSame( array( 'Priority boost pending: Events owner returned priority_boost_owner_response_invalid.' ), $order->notes );
	}

	public function test_provider_unavailable_remains_retryable(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return new WP_Error( 'owner_runtime_unavailable', 'Provider unavailable.' );
		};
		$order                      = new PriorityBoostTestOrder( 44, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertArrayNotHasKey( '_extrachill_priority_boost_receipt_10', $order->meta );
		$this->assertSame( array( 'Priority boost pending: Events owner returned owner_runtime_unavailable.' ), $order->notes );
	}
}
