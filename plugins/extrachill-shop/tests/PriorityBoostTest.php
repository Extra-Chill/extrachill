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
		$GLOBALS['test_owner_callback'] = static function () {
			return array(
				'success' => true,
				'replayed' => false,
				'receipt'  => array( 'operation_id' => 'safe-receipt' ),
			);
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
		$this->assertSame(
			array(
				'event'              => 'event-slug',
				'external_reference' => 'shop-order:44',
				'idempotency_key'    => 'shop-order:44:item:10',
			),
			$call['args']['body']['input']
		);
		$this->assertSame( array( 'operation_id' => 'safe-receipt' ), $order->meta['_extrachill_priority_boost_receipt_10'] );
		$this->assertSame( array( 'Priority boost granted for event: Event title' ), $order->notes );
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
}
