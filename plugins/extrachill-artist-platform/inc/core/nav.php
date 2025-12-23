<?php
/**
 * Navigation Integration
 *
 * ExtraChill theme navigation hooks for the artist platform site.
 *
 * @package ExtraChillArtistPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add artist management links to secondary header
 *
 * @hook extrachill_secondary_header_items
 * @param array $items Current secondary header items
 * @return array Items with artist management links added
 */
function ec_artist_platform_secondary_header_items( $items ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return $items;
	}

	$user_artists  = ec_get_artists_for_user( $user_id );
	$artist_count  = count( $user_artists );

	// Artist Profile Link (priority 10)
	if ( $artist_count > 0 ) {
		$artist_label = $artist_count === 1
			? __( 'Manage Artist', 'extrachill-artist-platform' )
			: __( 'Manage Artists', 'extrachill-artist-platform' );

		$items[] = array(
			'url'      => home_url( '/manage-artist/' ),
			'label'    => $artist_label,
			'priority' => 10,
		);
	} elseif ( ec_can_create_artist_profiles( $user_id ) ) {
		$items[] = array(
			'url'      => home_url( '/create-artist/' ),
			'label'    => __( 'Create Artist Profile', 'extrachill-artist-platform' ),
			'priority' => 10,
		);
	}

	// Link Page Link (priority 20) - only if user has artists
	if ( $artist_count > 0 ) {
		$latest_artist_id = ec_get_latest_artist_for_user( $user_id );
		$link_page_count  = ec_get_link_page_count_for_user( $user_id );
		$link_page_url    = home_url( '/manage-link-page/' );

		if ( $link_page_count === 0 ) {
			$link_page_label = __( 'Create Link Page', 'extrachill-artist-platform' );
		} elseif ( $link_page_count === 1 ) {
			$link_page_label = __( 'Manage Link Page', 'extrachill-artist-platform' );
		} else {
			$link_page_label = __( 'Manage Link Pages', 'extrachill-artist-platform' );
		}

		$items[] = array(
			'url'      => $link_page_url,
			'label'    => $link_page_label,
			'priority' => 20,
		);

		// Analytics Link (priority 40) - only if user has link pages
		if ( $link_page_count > 0 ) {
			$items[] = array(
				'url'      => home_url( '/analytics/' ),
				'label'    => __( 'Analytics', 'extrachill-artist-platform' ),
				'priority' => 40,
			);
		}

	}

	// Shop Link (priority 30).
	$can_manage_shop = function_exists( 'ec_can_manage_shop' ) ? ec_can_manage_shop( $user_id ) : false;
	if ( $can_manage_shop ) {
		$product_count = function_exists( 'ec_get_shop_product_count_for_user' )
			? ec_get_shop_product_count_for_user( $user_id )
			: 0;

		$items[] = array(
			'url'      => home_url( '/manage-shop/' ),
			'label'    => $product_count > 0
				? __( 'Manage Shop', 'extrachill-artist-platform' )
				: __( 'Create Shop', 'extrachill-artist-platform' ),
			'priority' => 30,
		);
	}

	return $items;
}
add_filter( 'extrachill_secondary_header_items', 'ec_artist_platform_secondary_header_items' );
