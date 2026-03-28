<?php
/**
 * My Shows — Concert History Page
 *
 * Auth-gating and nav integration for the My Shows page on the events site.
 * The page itself is a standard WordPress page with the extrachill/concert-stats
 * block inserted. This file handles the auth redirect and secondary nav item.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect unauthenticated users to login when visiting /my-shows/.
 *
 * The concert-stats block renders nothing for logged-out users,
 * so redirect them to login with a return URL.
 */
function ec_events_my_shows_auth_gate() {
	if ( ! ec_is_events_site() ) {
		return;
	}

	if ( is_user_logged_in() ) {
		return;
	}

	if ( ! is_page( 'my-shows' ) ) {
		return;
	}

	$login_url = function_exists( 'ec_get_site_url' )
		? ec_get_site_url( 'events' ) . '/login/'
		: wp_login_url();

	wp_safe_redirect( $login_url . '?redirect_to=' . rawurlencode( home_url( '/my-shows/' ) ) );
	exit;
}
add_action( 'template_redirect', 'ec_events_my_shows_auth_gate' );

/**
 * Add My Shows link to the secondary header nav (logged-in users only).
 *
 * @param array $items Secondary header items.
 * @return array Modified items.
 */
function ec_events_my_shows_nav_item( $items ) {
	if ( ! ec_is_events_site() ) {
		return $items;
	}

	if ( ! is_user_logged_in() ) {
		return $items;
	}

	$items[] = array(
		'url'      => home_url( '/my-shows/' ),
		'label'    => __( 'My Shows', 'extrachill-events' ),
		'priority' => 5,
	);

	return $items;
}
add_filter( 'extrachill_secondary_header_items', 'ec_events_my_shows_nav_item' );
