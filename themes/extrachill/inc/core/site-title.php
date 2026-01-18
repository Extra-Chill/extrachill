<?php
/**
 * Site Title Filter
 *
 * Provides filterable site title with WordPress default.
 *
 * @package ExtraChill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function extrachill_get_site_title() {
	return apply_filters( 'extrachill_site_title', get_bloginfo( 'name' ) );
}
