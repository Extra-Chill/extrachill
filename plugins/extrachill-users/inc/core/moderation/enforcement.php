<?php
/**
 * Moderation Enforcement
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_block_moderated_cookie_auth( $user, $username, $password ) {
	if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
		return $user;
	}

	if ( extrachill_users_is_blocked( (int) $user->ID ) ) {
		return new WP_Error(
			'extrachill_user_blocked',
			__( 'This account has been suspended. Please contact support if you believe this is a mistake.', 'extrachill-users' )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'extrachill_users_block_moderated_cookie_auth', 30, 3 );
