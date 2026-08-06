<?php
/**
 * PHPUnit bootstrap for focused plugin unit tests.
 *
 * @package ExtraChillShop
 */

// Test doubles intentionally mirror WordPress functions without duplicating core docblocks.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing

define( 'ABSPATH', __DIR__ . '/' );

function add_action() {}
function add_filter() {}
function absint( $value ) {
	return abs( (int) $value );
}
function __( $text ) {
	return $text;
}
function extrachill_shop_get_priority_boost_product_id() {
	return 99;
}
function ec_get_blog_id( $site ) {
	return 'events' === $site ? 7 : 0;
}
function wc_get_order( $order_id ) {
	return $GLOBALS['test_orders'][ $order_id ] ?? null;
}
function switch_to_blog( $blog_id ) {
	$GLOBALS['test_blog_stack'][] = $GLOBALS['test_current_blog'];
	$GLOBALS['test_current_blog'] = $blog_id;
}
function restore_current_blog() {
	if ( empty( $GLOBALS['test_blog_stack'] ) ) {
		return false;
	}

	$GLOBALS['test_current_blog'] = array_pop( $GLOBALS['test_blog_stack'] );
	return true;
}
function get_post( $post_id ) {
	return $GLOBALS['test_posts'][ $GLOBALS['test_current_blog'] ][ $post_id ] ?? null;
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['test_post_meta_updates'][] = array( $post_id, $key, $value );
}
function wp_cache_delete( $key, $group ) {
	$GLOBALS['test_cache_deletes'][] = array( $key, $group );
}

require_once dirname( __DIR__ ) . '/inc/products/priority-boost.php';
