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

// Script modules cannot declare classic script dependencies.
foreach ( array( 'react', 'react-jsx-runtime', 'wp-api-fetch', 'wp-components', 'wp-element' ) as $handle ) {
	wp_enqueue_script( $handle );
}

$class = 'wp-block-extrachill-user-settings';

$artist_site_url = ec_get_site_url( 'artist' );

$current_user_id = get_current_user_id();

$has_artists = function_exists( 'ec_get_artists_for_user' )
	? ! empty( ec_get_artists_for_user( $current_user_id ) )
	: false;

$can_create_artists = function_exists( 'ec_can_create_artist_profiles' )
	? ec_can_create_artist_profiles( $current_user_id )
	: false;

/*
 * Self-serve account deletion. The extrachill-users ability owns the rule
 * (self-only, staff refused); this only decides what the section shows:
 * 'allowed' renders the Delete account form, 'staff' renders a contact-the-team
 * note, and anything else (ability missing, other refusal) renders nothing.
 */
$account_deletion = 'unavailable';
if ( function_exists( 'extrachill_users_can_delete_own_account' ) ) {
	$can_delete_account = extrachill_users_can_delete_own_account();
	if ( true === $can_delete_account ) {
		$account_deletion = 'allowed';
	} elseif ( is_wp_error( $can_delete_account ) && 'account_deletion_refused' === $can_delete_account->get_error_code() ) {
		$account_deletion = 'staff';
	}
}

printf(
	'<div class="%1$s" data-artist-site-url="%2$s" data-has-artists="%3$s" data-can-create-artists="%4$s" data-user-id="%5$d" data-account-deletion="%6$s" data-home-url="%7$s"></div>',
	esc_attr( $class ),
	esc_url( $artist_site_url ),
	$has_artists ? '1' : '0',
	$can_create_artists ? '1' : '0',
	(int) $current_user_id,
	esc_attr( $account_deletion ),
	esc_url( home_url( '/' ) )
);
