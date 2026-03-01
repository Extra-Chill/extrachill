<?php
/**
 * Onboarding Utilities
 *
 * Username generation, status checks, and onboarding meta helpers.
 * Orchestration logic (completing onboarding, validating usernames) lives
 * in abilities — see inc/core/abilities/onboarding.php.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generate username from email address.
 *
 * Extracts the local part of the email, sanitizes it, and handles collisions.
 * Example: john.smith@gmail.com → johnsmith, johnsmith1, johnsmith2, etc.
 *
 * @param string $email Email address.
 * @return string Generated username.
 */
function ec_generate_username_from_email( $email ) {
	$local_part = strstr( $email, '@', true );
	if ( ! $local_part ) {
		$local_part = 'user';
	}

	$base_username = ec_sanitize_username_base( $local_part );

	return ec_get_unique_username( $base_username );
}

/**
 * Generate username from display name.
 *
 * Sanitizes the display name and handles collisions.
 * Example: John Smith → johnsmith, johnsmith1, johnsmith2, etc.
 *
 * @param string $display_name Display name (e.g., from Google OAuth).
 * @return string Generated username.
 */
function ec_generate_username_from_name( $display_name ) {
	$base_username = ec_sanitize_username_base( $display_name );

	if ( empty( $base_username ) ) {
		$base_username = 'user';
	}

	return ec_get_unique_username( $base_username );
}

/**
 * Sanitize a string into a valid username base.
 *
 * Removes special characters, converts to lowercase, collapses multiple
 * separators, and ensures minimum length.
 *
 * @param string $input Raw input string.
 * @return string Sanitized username base.
 */
function ec_sanitize_username_base( $input ) {
	$username = strtolower( trim( $input ) );
	$username = preg_replace( '/[^a-z0-9]/', '', $username );
	$username = substr( $username, 0, 50 );

	if ( strlen( $username ) < 3 ) {
		$username = 'user';
	}

	return $username;
}

/**
 * Get a unique username by appending numbers if necessary.
 *
 * @param string $base_username Base username to check.
 * @return string Unique username.
 */
function ec_get_unique_username( $base_username ) {
	$username = $base_username;
	$counter  = 1;

	while ( username_exists( $username ) ) {
		$username = $base_username . $counter;
		++$counter;

		if ( $counter > 9999 ) {
			$username = $base_username . wp_generate_password( 4, false, false );
			break;
		}
	}

	return $username;
}

/**
 * Check if user has completed onboarding.
 *
 * Grandfathered users (registered before onboarding system) have no meta
 * and are treated as completed.
 *
 * @param int $user_id User ID.
 * @return bool True if onboarding is complete or user is grandfathered.
 */
function ec_is_onboarding_complete( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return true;
	}

	$meta_value = get_user_meta( $user_id, 'onboarding_completed', true );

	if ( '' === $meta_value ) {
		return true;
	}

	return '1' === $meta_value;
}

/**
 * Check if user came from join flow during registration.
 *
 * @param int $user_id User ID.
 * @return bool True if user registered via /join flow.
 */
function ec_is_onboarding_from_join( $user_id ) {
	return '1' === get_user_meta( absint( $user_id ), 'onboarding_from_join', true );
}

/**
 * Get onboarding status for a user.
 *
 * @param int $user_id User ID.
 * @return array {completed: bool, from_join: bool, fields: array}
 */
function ec_get_onboarding_status( $user_id ) {
	$user_id = absint( $user_id );
	$user    = get_userdata( $user_id );

	if ( ! $user ) {
		return array(
			'completed' => true,
			'from_join' => false,
			'fields'    => array(),
		);
	}

	$completed = ec_is_onboarding_complete( $user_id );
	$from_join = ec_is_onboarding_from_join( $user_id );

	return array(
		'completed' => $completed,
		'from_join' => $from_join,
		'fields'    => array(
			'username'             => $user->user_login,
			'user_is_artist'       => '1' === get_user_meta( $user_id, 'user_is_artist', true ),
			'user_is_professional' => '1' === get_user_meta( $user_id, 'user_is_professional', true ),
		),
	);
}

/**
 * Complete onboarding for a user.
 *
 * Delegates to the extrachill/complete-onboarding ability.
 *
 * @param int   $user_id User ID.
 * @param array $data    {username: string, user_is_artist: bool, user_is_professional: bool}.
 * @return array|WP_Error Success array or error.
 */
function ec_complete_onboarding( $user_id, $data ) {
	$ability = wp_get_ability( 'extrachill/complete-onboarding' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill/complete-onboarding ability is not registered.' );
	}

	return $ability->execute(
		array(
			'user_id'              => absint( $user_id ),
			'username'             => isset( $data['username'] ) ? $data['username'] : '',
			'user_is_artist'       => ! empty( $data['user_is_artist'] ),
			'user_is_professional' => ! empty( $data['user_is_professional'] ),
		)
	);
}

/**
 * Validate username for onboarding.
 *
 * Delegates to the extrachill/validate-username ability.
 *
 * @param string $username New username.
 * @param int    $user_id  Current user ID (to allow keeping same username).
 * @return true|WP_Error True if valid, WP_Error otherwise.
 */
function ec_validate_onboarding_username( $username, $user_id ) {
	$ability = wp_get_ability( 'extrachill/validate-username' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill/validate-username ability is not registered.' );
	}

	return $ability->execute(
		array(
			'username' => $username,
			'user_id'  => absint( $user_id ),
		)
	);
}

/**
 * Mark a new user for onboarding.
 *
 * Called during user creation to set initial onboarding state.
 *
 * @param int    $user_id   User ID.
 * @param bool   $from_join Whether user registered via /join flow.
 * @param string $redirect_url URL to redirect after onboarding completion.
 */
function ec_mark_user_for_onboarding( $user_id, $from_join = false, $redirect_url = '' ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return;
	}

	update_user_meta( $user_id, 'onboarding_completed', '0' );
	update_user_meta( $user_id, 'onboarding_from_join', $from_join ? '1' : '0' );

	if ( ! empty( $redirect_url ) ) {
		update_user_meta( $user_id, 'onboarding_redirect_url', esc_url_raw( $redirect_url ) );
	}
}
