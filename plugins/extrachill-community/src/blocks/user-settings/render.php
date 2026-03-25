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

$current_user_id = get_current_user_id();

$has_artists = function_exists( 'ec_get_artists_for_user' )
	? ! empty( ec_get_artists_for_user( $current_user_id ) )
	: false;

$can_create_artists = function_exists( 'ec_can_create_artist_profiles' )
	? ec_can_create_artist_profiles( $current_user_id )
	: false;

printf(
	'<div class="%1$s ec-block-shell" data-artist-site-url="%2$s" data-has-artists="%3$s" data-can-create-artists="%4$s"></div>',
	esc_attr( $class ),
	esc_url( $artist_site_url ),
	$has_artists ? '1' : '0',
	$can_create_artists ? '1' : '0'
);
