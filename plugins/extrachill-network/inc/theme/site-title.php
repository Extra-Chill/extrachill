<?php
/**
 * Extra Chill Site Title Override
 *
 * Forces "Extra Chill" as site title across the network.
 *
 * @package ExtraChill\Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'extrachill_site_title', 'extrachill_network_site_title' );

function extrachill_network_site_title( $title ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract; the incoming value is intentionally overridden.
	return 'Extra Chill';
}
