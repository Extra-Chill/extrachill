<?php
/**
 * Artist Dashboard Endpoints
 *
 * Registers WooCommerce My Account endpoints for artist product and order management.
 * Artists access their dashboard at shop.extrachill.com/my-account/artist-products/
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register custom endpoint rewrites.
 */
function extrachill_shop_artist_dashboard_endpoints() {
	add_rewrite_endpoint( 'artist-products', EP_ROOT | EP_PAGES );
	add_rewrite_endpoint( 'artist-orders', EP_ROOT | EP_PAGES );
	add_rewrite_endpoint( 'artist-settings', EP_ROOT | EP_PAGES );
}
add_action( 'init', 'extrachill_shop_artist_dashboard_endpoints' );

/**
 * Add query vars for endpoints.
 *
 * @param array $vars Existing query vars.
 * @return array Modified query vars.
 */
function extrachill_shop_artist_dashboard_query_vars( $vars ) {
	$vars[] = 'artist-products';
	$vars[] = 'artist-orders';
	$vars[] = 'artist-settings';
	return $vars;
}
add_filter( 'query_vars', 'extrachill_shop_artist_dashboard_query_vars' );

/**
 * Add menu items to My Account navigation.
 *
 * @param array $items Existing menu items.
 * @return array Modified menu items.
 */
function extrachill_shop_artist_dashboard_menu_items( $items ) {
	if ( ! extrachill_shop_user_is_artist() ) {
		return $items;
	}

	$new_items = array();

	foreach ( $items as $key => $label ) {
		$new_items[ $key ] = $label;

		if ( 'orders' === $key ) {
			$new_items['artist-products'] = __( 'My Products', 'extrachill-shop' );
			$new_items['artist-orders']   = __( 'Artist Orders', 'extrachill-shop' );
			$new_items['artist-settings'] = __( 'Artist Settings', 'extrachill-shop' );
		}
	}

	return $new_items;
}
add_filter( 'woocommerce_account_menu_items', 'extrachill_shop_artist_dashboard_menu_items' );

/**
 * Artist Products endpoint content.
 */
function extrachill_shop_artist_products_endpoint_content() {
	$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';

	switch ( $action ) {
		case 'new':
			extrachill_shop_render_product_form();
			break;
		case 'edit':
			$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
			extrachill_shop_render_product_form( $product_id );
			break;
		default:
			extrachill_shop_render_products_list();
			break;
	}
}
add_action( 'woocommerce_account_artist-products_endpoint', 'extrachill_shop_artist_products_endpoint_content' );

/**
 * Artist Orders endpoint content.
 */
function extrachill_shop_artist_orders_endpoint_content() {
	extrachill_shop_render_artist_orders();
}
add_action( 'woocommerce_account_artist-orders_endpoint', 'extrachill_shop_artist_orders_endpoint_content' );

/**
 * Artist Settings endpoint content.
 */
function extrachill_shop_artist_settings_endpoint_content() {
	extrachill_shop_render_artist_settings();
}
add_action( 'woocommerce_account_artist-settings_endpoint', 'extrachill_shop_artist_settings_endpoint_content' );

/**
 * Check if current user is an artist with products capability.
 *
 * Uses extrachill-users plugin function if available, otherwise checks
 * for manage_options capability (admin gate for MVP).
 *
 * @param int $user_id Optional. User ID to check. Defaults to current user.
 * @return bool True if user is an artist.
 */
function extrachill_shop_user_is_artist( $user_id = null ) {
	if ( null === $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	if ( function_exists( 'ec_get_artists_for_user' ) ) {
		$artists = ec_get_artists_for_user( $user_id );
		return ! empty( $artists );
	}

	return true;
}

/**
 * Get artist profiles for the current user.
 *
 * @param int $user_id Optional. User ID. Defaults to current user.
 * @return array Array of artist profile data.
 */
function extrachill_shop_get_user_artists( $user_id = null ) {
	if ( null === $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return array();
	}

	return ec_get_artists_for_user( $user_id );
}

/**
 * Get all products for the current user's artist profiles.
 *
 * @param array $args Optional. Additional query arguments.
 * @return array Array of product posts.
 */
function extrachill_shop_get_user_artist_products( $args = array() ) {
	$user_artists = extrachill_shop_get_user_artists();

	if ( empty( $user_artists ) ) {
		return array();
	}

	$artist_ids = wp_list_pluck( $user_artists, 'ID' );

	$default_args = array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'pending', 'draft' ),
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'     => '_artist_profile_id',
				'value'   => $artist_ids,
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			),
		),
	);

	$query_args = wp_parse_args( $args, $default_args );
	$query      = new WP_Query( $query_args );

	return $query->posts;
}

/**
 * Check if user can manage a specific product.
 *
 * @param int $product_id Product ID.
 * @param int $user_id Optional. User ID. Defaults to current user.
 * @return bool True if user can manage product.
 */
function extrachill_shop_user_can_manage_product( $product_id, $user_id = null ) {
	if ( null === $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	$product_artist_id = extrachill_shop_get_product_artist_id( $product_id );
	if ( ! $product_artist_id ) {
		return false;
	}

	$user_artists = extrachill_shop_get_user_artists( $user_id );
	$artist_ids   = wp_list_pluck( $user_artists, 'ID' );

	return in_array( $product_artist_id, $artist_ids, true );
}

/**
 * Flush rewrite rules on plugin activation.
 */
function extrachill_shop_artist_dashboard_flush_rewrites() {
	extrachill_shop_artist_dashboard_endpoints();
	flush_rewrite_rules();
}
register_activation_hook( EXTRACHILL_SHOP_PLUGIN_FILE, 'extrachill_shop_artist_dashboard_flush_rewrites' );
