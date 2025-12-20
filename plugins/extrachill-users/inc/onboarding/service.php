<?php
/**
 * Onboarding Service
 *
 * Handles user onboarding: username generation, status tracking, and completion.
 * New users get auto-generated usernames and must complete onboarding to set
 * their final username and artist/professional flags.
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
 * Validate username for onboarding.
 *
 * @param string $username New username.
 * @param int    $user_id  Current user ID (to allow keeping same username).
 * @return true|WP_Error True if valid, WP_Error otherwise.
 */
function ec_validate_onboarding_username( $username, $user_id ) {
	$username = sanitize_user( $username, true );

	if ( strlen( $username ) < 3 ) {
		return new WP_Error(
			'username_too_short',
			__( 'Username must be at least 3 characters.', 'extrachill-users' )
		);
	}

	if ( strlen( $username ) > 60 ) {
		return new WP_Error(
			'username_too_long',
			__( 'Username must be 60 characters or less.', 'extrachill-users' )
		);
	}

	if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $username ) ) {
		return new WP_Error(
			'username_invalid_chars',
			__( 'Username can only contain letters, numbers, hyphens, and underscores.', 'extrachill-users' )
		);
	}

	$existing_user = get_user_by( 'login', $username );
	if ( $existing_user && $existing_user->ID !== $user_id ) {
		return new WP_Error(
			'username_exists',
			__( 'This username is already taken.', 'extrachill-users' )
		);
	}

	$reserved_usernames = array(
		'admin',
		'administrator',
		'extrachill',
		'support',
		'help',
		'info',
		'contact',
		'webmaster',
		'root',
		'system',
		'moderator',
		'mod',
	);

	if ( in_array( strtolower( $username ), $reserved_usernames, true ) ) {
		return new WP_Error(
			'username_reserved',
			__( 'This username is reserved.', 'extrachill-users' )
		);
	}

	return true;
}

/**
 * Complete onboarding for a user.
 *
 * Updates username, sets artist/professional flags, and marks onboarding complete.
 *
 * @param int   $user_id User ID.
 * @param array $data    {username: string, user_is_artist: bool, user_is_professional: bool}.
 * @return array|WP_Error Success array or error.
 */
function ec_complete_onboarding( $user_id, $data ) {
	$user_id = absint( $user_id );
	$user    = get_userdata( $user_id );

	if ( ! $user ) {
		return new WP_Error( 'invalid_user', __( 'Invalid user.', 'extrachill-users' ) );
	}

	if ( ec_is_onboarding_complete( $user_id ) ) {
		return new WP_Error( 'already_completed', __( 'Onboarding already completed.', 'extrachill-users' ) );
	}

	$username             = isset( $data['username'] ) ? sanitize_user( $data['username'], true ) : '';
	$user_is_artist       = ! empty( $data['user_is_artist'] );
	$user_is_professional = ! empty( $data['user_is_professional'] );

	$username_valid = ec_validate_onboarding_username( $username, $user_id );
	if ( is_wp_error( $username_valid ) ) {
		return $username_valid;
	}

	$from_join = ec_is_onboarding_from_join( $user_id );
	if ( $from_join && ! $user_is_artist && ! $user_is_professional ) {
		return new WP_Error(
			'artist_or_professional_required',
			__( 'Please select "I am a musician" or "I work in the music industry" to continue.', 'extrachill-users' )
		);
	}

	global $wpdb;

	$result = $wpdb->update(
		$wpdb->users,
		array(
			'user_login'    => $username,
			'user_nicename' => sanitize_title( $username ),
			'display_name'  => $username,
		),
		array( 'ID' => $user_id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);

	if ( false === $result ) {
		return new WP_Error( 'update_failed', __( 'Failed to update username.', 'extrachill-users' ) );
	}

	update_user_meta( $user_id, 'user_is_artist', $user_is_artist ? '1' : '0' );
	update_user_meta( $user_id, 'user_is_professional', $user_is_professional ? '1' : '0' );
	update_user_meta( $user_id, 'onboarding_completed', '1' );
	update_user_meta( $user_id, 'onboarding_completed_at', time() );

	clean_user_cache( $user_id );

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );

	do_action( 'ec_onboarding_completed', $user_id, $data );

	$redirect_url = get_user_meta( $user_id, 'onboarding_redirect_url', true );
	if ( empty( $redirect_url ) ) {
		$redirect_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url();
	}

	if ( $from_join && function_exists( 'ec_get_site_url' ) ) {
		$redirect_url = ec_get_site_url( 'artist' ) . '/create-artist/';
	}

	return array(
		'success'      => true,
		'user'         => array(
			'id'                   => $user_id,
			'username'             => $username,
			'user_is_artist'       => $user_is_artist,
			'user_is_professional' => $user_is_professional,
		),
		'redirect_url' => $redirect_url,
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
