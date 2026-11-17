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

/*
 * Two-step sign-in. extrachill-users answers whether Two Factor's management
 * routes are registered; the section talks to those routes directly. When
 * they are missing the Security tab shows nothing about two-step sign-in and
 * never links to a WordPress or Two Factor screen.
 */
$two_factor_settings = function_exists( 'extrachill_users_two_factor_settings_available' )
	&& extrachill_users_two_factor_settings_available();

// Reuse the QR library Two Factor registers so desktop members can scan the setup code.
if ( $two_factor_settings && wp_script_is( 'two-factor-qr-code-generator', 'registered' ) ) {
	wp_enqueue_script( 'two-factor-qr-code-generator' );
}

/*
 * Result of a front-end email change confirmation link. extrachill-users owns
 * the confirmation and the notice copy; this only renders it.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status flag.
$email_change_status = isset( $_GET['ec_email_change'] ) ? sanitize_key( wp_unslash( $_GET['ec_email_change'] ) ) : '';
$email_change_notice = ( '' !== $email_change_status && function_exists( 'extrachill_users_email_change_notice' ) )
	? extrachill_users_email_change_notice( $email_change_status )
	: null;
if ( $email_change_notice ) {
	printf(
		'<div class="notice notice-%1$s" role="%2$s"><p>%3$s</p></div>',
		esc_attr( $email_change_notice['type'] ),
		'error' === $email_change_notice['type'] ? 'alert' : 'status',
		esc_html( $email_change_notice['message'] )
	);
}

printf(
	'<div class="%1$s" data-artist-site-url="%2$s" data-has-artists="%3$s" data-can-create-artists="%4$s" data-user-id="%5$d" data-account-deletion="%6$s" data-home-url="%7$s" data-two-factor="%8$s"></div>',
	esc_attr( $class ),
	esc_url( $artist_site_url ),
	$has_artists ? '1' : '0',
	$can_create_artists ? '1' : '0',
	(int) $current_user_id,
	esc_attr( $account_deletion ),
	esc_url( home_url( '/' ) ),
	$two_factor_settings ? '1' : '0'
);
