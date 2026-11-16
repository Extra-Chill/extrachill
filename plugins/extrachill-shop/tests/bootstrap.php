<?php
/**
 * PHPUnit bootstrap for focused plugin unit tests.
 *
 * @package ExtraChillShop
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['test_filters'] = array();

require_once __DIR__ . '/fixtures/class-wp-error.php';

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['test_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['test_filters'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['test_filters'][ $hook ] ?? array() as $registered ) {
		$value = call_user_func_array(
			$registered['callback'],
			array_merge( array( $value ), array_slice( $args, 0, $registered['accepted_args'] - 1 ) )
		);
	}
	return $value;
}
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
function is_user_logged_in() {
	return 0 < get_current_user_id();
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['test_abilities'][ $name ] = $args;
}
function get_current_blog_id() {
	return 3;
}
function ec_get_blog_id( $site_key ) {
	return array(
		'shop'   => 3,
		'events' => 7,
		'artist' => 4,
	)[ $site_key ] ?? null;
}
function ec_get_site_url( $site_key ) {
	return array(
		'events' => 'https://events.extrachill.com',
		'artist' => 'https://artist.extrachill.com',
		'shop'   => 'https://shop.extrachill.com',
	)[ $site_key ] ?? null;
}
function add_query_arg( $key, $value, $url = '' ) {
	if ( is_array( $key ) ) {
		$url = $value;
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $key );
	}
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
}
function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}
// The network-wide manager URL builder lives in Users.
require_once dirname( __DIR__, 2 ) . '/extrachill-users/inc/shop-sellers.php';
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Stub of wp_parse_url().
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
function get_site_option( $key, $default_value = false ) {
	// Network options are shared by every blog.
	return $GLOBALS['test_site_options'][ $key ] ?? $default_value;
}
function add_site_option( $key, $value ) {
	if ( isset( $GLOBALS['test_site_options'] ) && array_key_exists( $key, $GLOBALS['test_site_options'] ) ) {
		return false;
	}
	$GLOBALS['test_site_options'][ $key ] = $value;
	return true;
}
function update_site_option( $key, $value ) {
	$GLOBALS['test_site_options'][ $key ] = $value;
	return true;
}
function as_get_scheduled_actions( $query = array(), $return_format = 'OBJECT' ) {
	$ids = array();
	foreach ( $GLOBALS['test_scheduled_actions'] ?? array() as $id => $action ) {
		if ( 'pending' === $query['status'] && $query['hook'] === $action['hook'] && $query['args'] === $action['args'] && $query['group'] === $action['group'] ) {
			$ids[] = $id + 1;
		}
	}
	return 'ids' === $return_format ? $ids : array();
}
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	$GLOBALS['test_scheduled_actions'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
		'group'     => $group,
	);
	return count( $GLOBALS['test_scheduled_actions'] );
}
function get_query_var( $key, $default_value = '' ) {
	return $GLOBALS['test_query_vars'][ $key ] ?? $default_value;
}
function home_url( $path = '' ) {
	return 'https://shop.extrachill.com' . $path;
}
function extrachill_get_artist_profile_by_slug( $slug ) {
	return $GLOBALS['test_artist_slugs'][ $slug ] ?? false;
}
function get_site_transient( $key ) {
	return $GLOBALS['test_site_transients'][ $key ] ?? false;
}
function set_site_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['test_site_transients'][ $key ]            = $value;
	$GLOBALS['test_site_transient_expirations'][ $key ] = $expiration;
	return true;
}
function get_transient( $key ) {
	return $GLOBALS['test_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Mirrors the core signature.
	$GLOBALS['test_transients'][ $key ] = $value;
	return true;
}
function get_option( $key, $default_value = false ) {
	return $GLOBALS['test_options'][ $key ] ?? $default_value;
}
function update_option( $key, $value ) {
	$GLOBALS['test_options'][ $key ] = $value;
	return true;
}
function get_blog_option( $blog_id, $key, $default_value = false ) {
	return $GLOBALS['test_blog_options'][ $blog_id ][ $key ] ?? $default_value;
}
// Fee rates are a network setting owned by extrachill-network.
require_once dirname( __DIR__, 2 ) . '/extrachill-network/inc/commerce/fee-settings.php';
require_once dirname( __DIR__ ) . '/inc/core/commerce-fees.php';
require_once dirname( __DIR__ ) . '/inc/shipping/shipping-recovery.php';
require_once dirname( __DIR__ ) . '/inc/shipping/label-parcel.php';
function extrachill_shop_get_priority_boost_product_id() {
	return 99;
}
function wc_get_order( $order_id ) {
	return $GLOBALS['test_orders'][ $order_id ] ?? null;
}
function ec_cross_site_rest_request_http( $site, $method, $route, $args ) {
	if ( isset( $args['service_assertion'] ) ) {
		$GLOBALS['test_assertions'][] = 'assertion-' . ( count( $GLOBALS['test_assertions'] ?? array() ) + 1 );
	}
	$GLOBALS['test_owner_calls'][] = compact( 'site', 'method', 'route', 'args' );
	if ( isset( $GLOBALS['test_owner_callback'] ) ) {
		return $GLOBALS['test_owner_callback']( $site, $method, $route, $args );
	}
	return new WP_Error( 'rest_ability_not_found', 'Ability not found.' );
}
function priority_boost_owner_response( $replayed = false ) {
	return array(
		'success'                     => true,
		'replayed'                    => (bool) $replayed,
		'existing_priority_preserved' => false,
		'event'                       => array(
			'post_id'  => 101,
			'title'    => 'Event title',
			'slug'     => 'event-slug',
			'priority' => true,
		),
		'receipt'                     => array(
			'operation_id' => 'safe-receipt',
			'actor_id'     => 0,
			'granted_at'   => '2026-08-08T12:00:00+00:00',
		),
	);
}
function extrachill_shop_get_account_status( $account_id = '' ) {
	$GLOBALS['test_stripe_status_calls'][] = $account_id;
	return $GLOBALS['test_stripe_status'] ?? array( 'success' => false );
}

function get_post( $id ) {
	if ( array_key_exists( $id, $GLOBALS['test_posts'] ?? array() ) ) {
		return $GLOBALS['test_posts'][ $id ];
	}
	return (object) array(
		'ID'         => $id,
		'post_title' => 'Artist',
		'post_type'  => 'product',
	);
}
function user_can( $user_id, $capability ) {
	if ( in_array( $capability, $GLOBALS['test_user_caps'][ (int) $user_id ] ?? array(), true ) ) {
		return true;
	}
	return in_array( (int) $user_id, $GLOBALS['test_admins'] ?? array(), true ) && 'manage_options' === $capability;
}
function ec_feature_available( $feature, $user_id = null ) {
	$GLOBALS['test_feature_checks'][] = array( $feature, $user_id );
	return ! in_array( (int) $user_id, $GLOBALS['test_feature_denied'] ?? array(), true );
}
function ec_can_manage_artist( $user_id, $artist_id ) {
	$GLOBALS['test_artist_checks'][] = array( (int) $user_id, (int) $artist_id );
	return in_array( (int) $artist_id, $GLOBALS['test_artist_members'][ (int) $user_id ] ?? array(), true );
}

// Real Network keyring so grant resolution is exercised without constants.
require_once dirname( __DIR__, 2 ) . '/extrachill-network/inc/core/service-assertions.php';
require_once dirname( __DIR__ ) . '/inc/core/commerce-state.php';
require_once dirname( __DIR__ ) . '/inc/core/seller-permissions.php';
require_once dirname( __DIR__ ) . '/inc/core/priority-boost-service-authority.php';
require_once dirname( __DIR__ ) . '/inc/products/priority-boost.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-stripe-status.php';

require_once __DIR__ . '/fixtures/class-priority-boost-test-item.php';
require_once __DIR__ . '/fixtures/class-priority-boost-test-order.php';

function switch_to_blog( $id ) {
	$GLOBALS['test_blog_stack'][] = $id;
}
function restore_current_blog() {
	array_pop( $GLOBALS['test_blog_stack'] );
}
