<?php
/**
 * PHPUnit bootstrap for focused plugin unit tests.
 *
 * @package ExtraChillShop
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function add_action() {}
function add_filter() {}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function absint( $value ) {
	return abs( (int) $value );
}
function __( $text ) {
	return $text;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function sanitize_title( $value ) {
	return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ), '-' );
}
function get_current_user_id() {
	return $GLOBALS['test_user_id'] ?? 1;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['test_options'][ $key ] ?? $default;
}
function update_option( $key, $value ) {
	$GLOBALS['test_options'][ $key ] = $value;
	return true;
}
function extrachill_shop_get_priority_boost_product_id() {
	return 99;
}
function wc_get_order( $order_id ) {
	return $GLOBALS['test_orders'][ $order_id ] ?? null;
}
function ec_cross_site_rest_request_http( $site, $method, $route, $args ) {
	$GLOBALS['test_owner_calls'][] = compact( 'site', 'method', 'route', 'args' );
	if ( isset( $GLOBALS['test_owner_callback'] ) ) {
		return $GLOBALS['test_owner_callback']( $site, $method, $route, $args );
	}
	return new WP_Error( 'rest_ability_not_found', 'Ability not found.' );
}
function extrachill_shop_get_artist_stripe_account( $artist_id ) {
	$record = extrachill_shop_get_stripe_account_record( $artist_id );
	return $record ? $record['account_id'] : false;
}
function extrachill_shop_get_account_status() {
	return $GLOBALS['test_stripe_status'] ?? array( 'success' => false );
}

require_once dirname( __DIR__ ) . '/inc/core/commerce-state.php';
require_once dirname( __DIR__ ) . '/inc/products/priority-boost.php';
require_once dirname( __DIR__ ) . '/inc/core/owned-state-migration.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-stripe-status.php';

final class PriorityBoostTestItem {
	private $id;
	private $event_id;
	private $event_reference;
	private $event_title;

	public function __construct( $id, $event_id, $event_reference, $event_title ) {
		$this->id              = $id;
		$this->event_id        = $event_id;
		$this->event_reference = $event_reference;
		$this->event_title     = $event_title;
	}

	public function get_product_id() {
		return 99;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key ) {
		$values = array(
			'priority_boost_event_id'        => $this->event_id,
			'priority_boost_event_reference' => $this->event_reference,
			'priority_boost_event_title'     => $this->event_title,
		);
		return $values[ $key ] ?? '';
	}
}

final class PriorityBoostTestOrder {
	private $id;
	private $items;
	public $meta       = array();
	public $notes      = array();
	public $save_count = 0;

	public function __construct( $id, array $items ) {
		$this->id    = $id;
		$this->items = $items;
	}

	public function get_id() {
		return $this->id;
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
