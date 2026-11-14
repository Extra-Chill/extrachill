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
 * Shop manager is the first consumer of the feature-rollout primitive. The
 * "admin-only until ready" logic is no longer a hardcoded manage_options check
 * here — it now comes from the `shop` feature ceiling (currently `admin`, see
 * ec_feature_ceilings()). Management additionally requires at least one seller
 * the user can manage: an artist they belong to, or a venue or promoter whose
 * finances Events says they manage.
 *
 *     can manage shop = ec_feature_available( 'shop' ) AND (manages >= 1 seller)
 *
 * Promoting shop to the team becomes a one-flag flip of the network option;
 * going public is a reviewed ceiling bump — no edits to this function.
 *
 * @param int|null $user_id User ID (defaults to current).
 * @return bool
 */
function ec_can_manage_shop( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

	if ( $user_id <= 0 ) {
		return false;
	}

	// Rollout gate: is the shop feature live for this user's tier yet?
	if ( ! function_exists( 'ec_feature_available' ) || ! ec_feature_available( 'shop', $user_id ) ) {
		return false;
	}

	// Base capability: the user manages at least one seller. Artist membership
	// is a local lookup, so check it before asking Events about venues.
	if ( function_exists( 'ec_get_artists_for_user' ) ) {
		$artist_ids = ec_get_artists_for_user( $user_id );
		if ( ! empty( $artist_ids ) ) {
			return true;
		}
	}

	return ec_user_has_shop_event_sellers( $user_id );
}

/**
 * Returns the total number of published shop products for the user's artists.
 *
 * Counts products on the shop blog whose typed seller
 * (`_extrachill_shop_seller_type` / `_extrachill_shop_seller_id`) is one of
 * the user's artist profiles.
 *
 * @param int|null $user_id User ID (defaults to current).
 * @return int
 */
function ec_get_shop_product_count_for_user( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	if ( $user_id <= 0 || ! function_exists( 'ec_get_artists_for_user' ) ) {
		return 0;
	}

	$user_artists = ec_get_artists_for_user( $user_id );
	$shop_blog_id = ec_get_blog_id( 'shop' );
	if ( empty( $user_artists ) || ! $shop_blog_id ) {
		return 0;
	}

	switch_to_blog( $shop_blog_id );
	try {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Seller identity is product meta.
					array(
						'key'   => '_extrachill_shop_seller_type',
						'value' => 'artist',
					),
					array(
						'key'     => '_extrachill_shop_seller_id',
						'value'   => array_map( 'absint', $user_artists ),
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return (int) $query->found_posts;
	} finally {
		restore_current_blog();
	}
}
