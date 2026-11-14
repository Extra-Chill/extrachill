<?php
/**
 * Seller permission primitive.
 *
 * One answer to "may this user manage this seller's shop?", shared by every
 * seller-scoped Shop ability, the shop manager, and the storefront. Nothing
 * else in Shop, or in the extrachill-api shop routes, decides seller access.
 *
 * Order of checks:
 *
 * 1. Network admins (`manage_options`) may manage any seller.
 * 2. The `shop` feature must be live for the user's tier
 *    (`ec_feature_available( 'shop' )`).
 * 3. Membership, decided by the seller's owner:
 *    - artist: the Artist membership relationship (`ec_can_manage_artist()`,
 *      which also requires a published artist profile);
 *    - venue / promoter: finance authority reported by Events
 *      (`extrachill/get-commerce-seller` → `can_manage_finances`), asked as
 *      the user being checked.
 *
 * Results are cached per request by user and seller, so an ability's
 * permission callback and its execute callback ask the owner once.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-request permission cache, keyed `user_id|type:id`.
 *
 * @return array<string, true|WP_Error>
 */
function &extrachill_shop_seller_permission_cache(): array {
	static $cache = array();
	return $cache;
}

/**
 * Forget cached seller permissions (tests, or after membership changes).
 */
function extrachill_shop_reset_seller_permission_cache(): void {
	$cache = &extrachill_shop_seller_permission_cache();
	$cache = array();
}

/**
 * Whether a user may manage a seller's shop.
 *
 * @param int   $user_id User ID.
 * @param mixed $seller  `{type, id}` seller reference.
 * @return true|WP_Error True when allowed; an error explaining the refusal otherwise.
 */
function extrachill_shop_can_manage_seller( $user_id, $seller ) {
	$user_id = (int) $user_id;
	$seller  = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	if ( $user_id < 1 ) {
		return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in to manage a shop.', 'extrachill-shop' ), array( 'status' => 401 ) );
	}

	$cache = &extrachill_shop_seller_permission_cache();
	$key   = $user_id . '|' . $seller['type'] . ':' . $seller['id'];
	if ( ! array_key_exists( $key, $cache ) ) {
		$cache[ $key ] = extrachill_shop_resolve_seller_permission( $user_id, $seller );
	}
	return $cache[ $key ];
}

/**
 * Uncached permission decision. Use `extrachill_shop_can_manage_seller()`.
 *
 * @param int   $user_id User ID (positive).
 * @param array $seller  Validated seller reference.
 * @return true|WP_Error
 */
function extrachill_shop_resolve_seller_permission( int $user_id, array $seller ) {
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}

	if ( ! function_exists( 'ec_feature_available' ) || ! ec_feature_available( 'shop', $user_id ) ) {
		return new WP_Error( 'shop_unavailable', __( 'Shop management is not available for your account yet.', 'extrachill-shop' ), array( 'status' => 403 ) );
	}

	$denied = new WP_Error( 'cannot_manage_seller', __( 'You do not have access to this seller.', 'extrachill-shop' ), array( 'status' => 403 ) );

	if ( 'artist' === $seller['type'] ) {
		return function_exists( 'ec_can_manage_artist' ) && ec_can_manage_artist( $user_id, $seller['id'] ) ? true : $denied;
	}

	$result = extrachill_shop_execute_owner_ability( 'events', 'extrachill/get-commerce-seller', 'GET', $seller, $user_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ( $result['type'] ?? '' ) !== $seller['type'] || absint( $result['id'] ?? 0 ) !== $seller['id'] ) {
		return new WP_Error( 'seller_not_found', __( 'Seller not found.', 'extrachill-shop' ), array( 'status' => 404 ) );
	}
	return true === ( $result['can_manage_finances'] ?? false ) ? true : $denied;
}

/**
 * Permission callback for abilities whose input names a `seller`.
 *
 * @param array $input Ability input with `seller`.
 * @return true|WP_Error
 */
function extrachill_shop_authorize_seller_input( array $input ) {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	return extrachill_shop_can_manage_seller( get_current_user_id(), $seller );
}

/**
 * Permission callback for abilities that act on an existing product (`id`).
 *
 * Authority follows the product's seller. Products without a seller are
 * platform products, managed in wp-admin rather than through seller
 * abilities. When the input also names a `seller` (moving the product), the
 * user must manage that seller too.
 *
 * @param array $input Ability input with product `id` and optional `seller`.
 * @return true|WP_Error
 */
function extrachill_shop_authorize_product_input( array $input ) {
	$product_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$post       = $product_id > 0 ? get_post( $product_id ) : null;
	if ( ! $post || 'product' !== $post->post_type ) {
		return new WP_Error( 'product_not_found', __( 'Product not found.', 'extrachill-shop' ), array( 'status' => 404 ) );
	}

	$seller = extrachill_shop_get_product_seller( $product_id );
	if ( ! $seller ) {
		return new WP_Error( 'product_has_no_seller', __( 'This product does not belong to a seller.', 'extrachill-shop' ), array( 'status' => 403 ) );
	}

	$allowed = extrachill_shop_can_manage_seller( get_current_user_id(), $seller );
	if ( true !== $allowed || ! isset( $input['seller'] ) ) {
		return $allowed;
	}
	return extrachill_shop_authorize_seller_input( $input );
}
