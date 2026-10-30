<?php
/**
 * Footer Bottom Menu Links
 *
 * Provides EC-specific footer bottom menu links via filter.
 * Theme provides empty default; this plugin adds EC links.
 *
 * @package ExtraChill\Network
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add EC-specific footer bottom menu links.
 *
 * @param array $items Existing footer items.
 * @return array Modified items.
 */
function extrachill_network_footer_bottom_links( $items ) {
	$main_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : home_url();

	$items[] = array(
		'url'      => $main_site_url . '/affiliate-disclosure/',
		'label'    => 'Affiliate Disclosure',
		'priority' => 10,
	);

	$items[] = array(
		'url'      => $main_site_url . '/privacy-policy/',
		'label'    => 'Privacy Policy',
		'rel'      => 'privacy-policy',
		'priority' => 20,
	);

	return $items;
}
add_filter( 'extrachill_footer_bottom_menu_items', 'extrachill_network_footer_bottom_links' );
