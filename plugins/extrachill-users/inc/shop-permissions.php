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
 * Returns the total number of shop products for the user.
 *
 * Performs cross-site query to shop blog (Blog ID 3) to count products
 * associated with the user's artist profiles via _artist_profile_id meta.
 *
 * @param int|null $user_id User ID (defaults to current).
 * @return int
 */
function ec_get_shop_product_count_for_user( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

	if ( $user_id <= 0 ) {
		return 0;
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return 0;
	}

	$user_artists = ec_get_artists_for_user( $user_id );
	if ( empty( $user_artists ) ) {
		return 0;
	}

	$shop_blog_id = ec_get_blog_id( 'shop' );
	if ( ! $shop_blog_id ) {
		return 0;
	}

	$current_blog = get_current_blog_id();
	$needs_switch = $current_blog !== $shop_blog_id;
	$total_count  = 0;

	if ( $needs_switch ) {
		if ( function_exists( 'switch_to_blog' ) ) {
			global $current_user;

			if ( isset( $current_user ) && $current_user instanceof WP_User ) {
				switch_to_blog( $shop_blog_id );
				try {
					$query = new WP_Query(
						array(
							'post_type'      => 'product',
							'post_status'    => 'publish',
							'posts_per_page' => 1,
							'fields'         => 'ids',
							'meta_query'     => array(
								array(
									'key'     => '_artist_profile_id',
									'value'   => array_map( 'absint', $user_artists ),
									'compare' => 'IN',
									'type'    => 'NUMERIC',
								),
							),
						)
					);

					$total_count = $query->found_posts;
				} finally {
					restore_current_blog();
				}
			}
		}
	} else {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_artist_profile_id',
						'value'   => array_map( 'absint', $user_artists ),
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$total_count = $query->found_posts;
	}

	return $total_count;
}
