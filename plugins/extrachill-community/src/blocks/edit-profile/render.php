<?php
/**
 * Edit Profile block server render.
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

$class = 'wp-block-extrachill-edit-profile';

$sprite_url = get_template_directory_uri() . '/assets/fonts/extrachill.svg';

if ( defined( 'EXTRACHILL_THEME_VERSION' ) && EXTRACHILL_THEME_VERSION ) {
	$sprite_url .= '?v=' . urlencode( EXTRACHILL_THEME_VERSION );
}

$artist_site_url = function_exists( 'ec_get_site_url' )
	? ec_get_site_url( 'artist' )
	: 'https://artist.extrachill.com';

printf(
	'<div class="%1$s" data-sprite-url="%2$s" data-artist-site-url="%3$s"></div>',
	esc_attr( $class ),
	esc_url( $sprite_url ),
	esc_url( $artist_site_url )
);
