<?php
/**
 * Social Links Data
 *
 * Provides EC-specific social media links via filter.
 * Theme provides empty default; this plugin adds EC links.
 *
 * @package ExtraChill\Network
 * @since 1.4.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add EC-specific social media links.
 *
 * @param array $links Existing social links.
 * @return array Modified links.
 */
function extrachill_network_social_links_data( $links ) {
	$links[] = array(
		'url'   => 'https://facebook.com/extrachill',
		'icon'  => 'facebook',
		'label' => 'Facebook',
	);

	$links[] = array(
		'url'   => 'https://twitter.com/extra_chill',
		'icon'  => 'x-twitter',
		'label' => 'Twitter',
	);

	$links[] = array(
		'url'   => 'https://instagram.com/extrachill',
		'icon'  => 'instagram',
		'label' => 'Instagram',
	);

	$links[] = array(
		'url'   => 'https://youtube.com/@extra-chill',
		'icon'  => 'youtube',
		'label' => 'YouTube',
	);

	$links[] = array(
		'url'   => 'https://pinterest.com/extrachill',
		'icon'  => 'pinterest',
		'label' => 'Pinterest',
	);

	$links[] = array(
		'url'   => 'https://github.com/Extra-Chill',
		'icon'  => 'github',
		'label' => 'GitHub',
	);

	return $links;
}
add_filter( 'extrachill_social_links_data', 'extrachill_network_social_links_data' );
