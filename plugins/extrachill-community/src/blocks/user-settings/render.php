<?php
/**
 * User Settings block server render.
 *
 * Outputs a container div that the React app hydrates into.
 * Passes configuration via data attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Redirect to login if not authenticated.
if ( ! is_user_logged_in() ) {
	auth_redirect();
	return;
}

$class = 'wp-block-extrachill-user-settings';

$artist_site_url = function_exists( 'ec_get_site_url' )
	? ec_get_site_url( 'artist' )
	: 'https://artist.extrachill.com';

printf(
	'<div class="%1$s" data-artist-site-url="%2$s"></div>',
	esc_attr( $class ),
	esc_url( $artist_site_url )
);
