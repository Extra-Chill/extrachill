<?php
/**
 * My Shows — Concert History Page
 *
 * Nav integration for the My Shows page on the events site. The page
 * itself is a standard WordPress page with the extrachill/concert-stats
 * block inserted; that block renders the full React app for logged-in
 * users and a server-rendered public marketing surface for everyone
 * else (see `blocks/concert-stats/render.php`).
 *
 * #126: the old `template_redirect` auth-gate (which force-redirected
 * anonymous visitors to /login/?redirect_to=/my-shows/ with zero
 * context) was removed. The marketing surface lives inside the block
 * itself so /my-shows/ is one URL, two states.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
