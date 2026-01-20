<?php
/**
 * Avatar Menu Items
 *
 * Canonical menu-item builder for the web avatar menu.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the canonical avatar menu items for a user.
 *
 * @param int $user_id User ID.
 * @return array
 */
function extrachill_users_get_avatar_menu_items( $user_id ) {
	$user_id = (int) $user_id;

	if ( $user_id <= 0 ) {
		return array();
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return array();
	}

	$items = array(
		array(
			'id'       => 'view_profile',
			'label'    => __( 'View Profile', 'extrachill-users' ),
			'url'      => ec_get_site_url( 'community' ) . '/u/' . $user->user_login . '/',
			'priority' => 10,
			'danger'   => false,
		),
		array(
			'id'       => 'edit_profile',
			'label'    => __( 'Edit Profile', 'extrachill-users' ),
			'url'      => ec_get_site_url( 'community' ) . '/u/' . $user->user_login . '/edit/',
			'priority' => 20,
			'danger'   => false,
		),
	);

	$user_artist_ids = ec_get_artists_for_user( $user_id );
	$artist_count    = count( $user_artist_ids );

	$can_manage_shop = function_exists( 'ec_can_manage_shop' ) ? ec_can_manage_shop( $user_id ) : false;
	if ( $can_manage_shop ) {
		$product_count = function_exists( 'ec_get_shop_product_count_for_user' )
			? ec_get_shop_product_count_for_user( $user_id )
			: 0;

		$items[] = array(
			'id'       => 'manage_shop',
			'label'    => $product_count > 0
				? __( 'Manage Shop', 'extrachill-users' )
				: __( 'Create Shop', 'extrachill-users' ),
			'url'      => ec_get_site_url( 'artist' ) . '/manage-shop/',
			'priority' => 50,
			'danger'   => false,
		);
	}

	if ( $artist_count > 0 ) {
		$artist_label = 1 === $artist_count
			? __( 'Manage Artist', 'extrachill-users' )
			: __( 'Manage Artists', 'extrachill-users' );

		$items[] = array(
			'id'       => 'manage_artists',
			'label'    => $artist_label,
			'url'      => ec_get_site_url( 'artist' ) . '/manage-artist/',
			'priority' => 30,
			'danger'   => false,
		);

		$link_page_count = ec_get_link_page_count_for_user( $user_id );

		if ( 0 === $link_page_count ) {
			$link_page_label = __( 'Create Link Page', 'extrachill-users' );
		} elseif ( 1 === $link_page_count ) {
			$link_page_label = __( 'Manage Link Page', 'extrachill-users' );
		} else {
			$link_page_label = __( 'Manage Link Pages', 'extrachill-users' );
		}

		$items[] = array(
			'id'       => 'manage_link_pages',
			'label'    => $link_page_label,
			'url'      => ec_get_site_url( 'artist' ) . '/manage-link-page/',
			'priority' => 40,
			'danger'   => false,
		);

	} elseif ( function_exists( 'ec_can_create_artist_profiles' ) && ec_can_create_artist_profiles( $user_id ) ) {
		$items[] = array(
			'id'       => 'create_artist',
			'label'    => __( 'Create Artist Profile', 'extrachill-users' ),
			'url'      => ec_get_site_url( 'artist' ) . '/create-artist/',
			'priority' => 30,
			'danger'   => false,
		);
	}

	$custom_items = apply_filters( 'ec_avatar_menu_items', array(), $user_id );
	if ( ! empty( $custom_items ) && is_array( $custom_items ) ) {
		usort(
			$custom_items,
			function ( $a, $b ) {
				$priority_a = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
				$priority_b = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
				return $priority_a <=> $priority_b;
			}
		);

		foreach ( $custom_items as $custom_item ) {
			if ( empty( $custom_item['label'] ) || empty( $custom_item['url'] ) ) {
				continue;
			}

			$items[] = array(
				'id'       => isset( $custom_item['id'] ) ? (string) $custom_item['id'] : 'custom_' . md5( (string) $custom_item['url'] ),
				'label'    => (string) $custom_item['label'],
				'url'      => (string) $custom_item['url'],
				'priority' => isset( $custom_item['priority'] ) ? (int) $custom_item['priority'] : 10,
				'danger'   => false,
			);
		}
	}

	$items[] = array(
		'id'       => 'settings',
		'label'    => __( 'Settings', 'extrachill-users' ),
		'url'      => ec_get_site_url( 'community' ) . '/settings/',
		'priority' => 90,
		'danger'   => false,
	);

	$items[] = array(
		'id'       => 'logout',
		'label'    => __( 'Log Out', 'extrachill-users' ),
		'url'      => wp_logout_url( home_url() ),
		'priority' => 100,
		'danger'   => true,
	);

	return $items;
}
