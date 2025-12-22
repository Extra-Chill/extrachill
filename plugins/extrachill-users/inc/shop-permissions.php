<?php
/**
 * Shop permissions.
 *
 * Canonical helpers for determining whether a user can manage a shop and
 * whether they have any shop products.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns whether the user can manage the shop.
 *
 * @param int|null $user_id User ID (defaults to current).
 * @return bool
 */
function ec_can_manage_shop( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

	if ( $user_id <= 0 ) {
		return false;
	}

	if ( function_exists( 'ec_get_artists_for_user' ) ) {
		$artist_ids = ec_get_artists_for_user( $user_id );
		if ( is_array( $artist_ids ) && ! empty( $artist_ids ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns the total number of shop products for the user.
 *
 * Existence is defined by having at least one WooCommerce product tied to any
 * artist the user can manage.
 *
 * @param int|null $user_id User ID (defaults to current).
 * @return int
 */
function ec_get_shop_product_count_for_user( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

	if ( $user_id <= 0 ) {
		return 0;
	}

	if ( ! function_exists( 'extrachill_shop_get_product_count_for_user' ) ) {
		return 0;
	}

	return (int) extrachill_shop_get_product_count_for_user( $user_id );
}
