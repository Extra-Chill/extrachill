<?php
/**
 * Shared password policy.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate a password accepted by Extra Chill account entry points.
 *
 * @param mixed $password Password value to validate.
 * @return true|WP_Error True when valid, otherwise a REST-safe error.
 */
function extrachill_users_validate_password( $password ) {
	if ( ! is_string( $password ) ) {
		return new WP_Error(
			'invalid_password',
			__( 'Password must be a string.', 'extrachill-users' ),
			array( 'status' => 400 )
		);
	}

	if ( strlen( $password ) < 8 ) {
		return new WP_Error(
			'password_too_short',
			__( 'Password must be at least 8 characters.', 'extrachill-users' ),
			array( 'status' => 400 )
		);
	}

	if ( str_contains( $password, '\\' ) ) {
		return new WP_Error(
			'invalid_password',
			__( 'Passwords cannot contain the "\\" character.', 'extrachill-users' ),
			array( 'status' => 400 )
		);
	}

	return true;
}
