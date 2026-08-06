<?php
/**
 * Tests for priority boost order handling.
 *
 * @package ExtraChillShop
 */

// Keep the small test doubles beside their only consumer.
// phpcs:disable WordPress.Files.FileName
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use PHPUnit\Framework\TestCase;

final class PriorityBoostTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_orders']            = array();
		$GLOBALS['test_posts']             = array( 7 => array() );
		$GLOBALS['test_post_meta_updates'] = array();
		$GLOBALS['test_cache_deletes']     = array();
		$GLOBALS['test_current_blog']      = 1;
		$GLOBALS['test_blog_stack']        = array();
	}

	public function test_valid_event_restores_caller_context_and_processes_items_in_order(): void {
		$order                         = new PriorityBoostTestOrder(
			array(
				new PriorityBoostTestItem( 10, 101, 'First event' ),
				new PriorityBoostTestItem( 11, 102, 'Second event' ),
			)
		);
		$GLOBALS['test_orders'][44]    = $order;
		$GLOBALS['test_posts'][7][101] = (object) array( 'post_type' => 'data_machine_events' );
		$GLOBALS['test_posts'][7][102] = (object) array( 'post_type' => 'data_machine_events' );

		switch_to_blog( 2 );
		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertSame( 2, $GLOBALS['test_current_blog'] );
		$this->assertSame( array( 1 ), $GLOBALS['test_blog_stack'] );
		$this->assertSame(
			array(
				'Priority boost granted for event: First event',
				'Priority boost granted for event: Second event',
			),
			$order->notes
		);
		$this->assertSame( 1, $order->meta['_extrachill_priority_boost_processed_10'] );
		$this->assertSame( 1, $order->meta['_extrachill_priority_boost_processed_11'] );
		$this->assertSame( 1, $order->save_count );
	}

	public function test_invalid_event_restores_only_handler_switch_and_remains_unprocessed(): void {
		$order                      = new PriorityBoostTestOrder( array( new PriorityBoostTestItem( 10, 404, 'Missing event' ) ) );
		$GLOBALS['test_orders'][44] = $order;

		switch_to_blog( 2 );
		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertSame( 2, $GLOBALS['test_current_blog'] );
		$this->assertSame( array( 1 ), $GLOBALS['test_blog_stack'] );
		$this->assertArrayNotHasKey( '_extrachill_priority_boost_processed_10', $order->meta );
		$this->assertSame( array(), $order->notes );
		$this->assertSame( array(), $GLOBALS['test_post_meta_updates'] );
		$this->assertSame( 1, $order->save_count );
	}

	public function test_processed_item_remains_idempotent(): void {
		$order = new PriorityBoostTestOrder( array( new PriorityBoostTestItem( 10, 101, 'Existing event' ) ) );
		$order->meta['_extrachill_priority_boost_processed_10'] = 1;
		$GLOBALS['test_orders'][44]                             = $order;
		$GLOBALS['test_posts'][7][101]                          = (object) array( 'post_type' => 'data_machine_events' );

		extrachill_shop_handle_priority_boost_purchase( 44 );

		$this->assertSame( array(), $GLOBALS['test_post_meta_updates'] );
		$this->assertSame( array(), $order->notes );
		$this->assertSame( 1, $order->save_count );
	}
}

final class PriorityBoostTestItem {
	private $id;
	private $event_id;
	private $event_title;

	public function __construct( $id, $event_id, $event_title ) {
		$this->id          = $id;
		$this->event_id    = $event_id;
		$this->event_title = $event_title;
	}

	public function get_product_id() {
		return 99;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key ) {
		if ( 'priority_boost_event_id' === $key ) {
			return $this->event_id;
		}

		return 'priority_boost_event_title' === $key ? $this->event_title : '';
	}
}

final class PriorityBoostTestOrder {
	private $items;
	public $meta       = array();
	public $notes      = array();
	public $save_count = 0;

	public function __construct( array $items ) {
		$this->items = $items;
	}

	public function get_items() {
		return $this->items;
	}

	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function save() {
		++$this->save_count;
	}
}
