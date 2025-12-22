<?php
/**
 * Google OAuth Service
 *
 * Handles Google ID token verification and user creation/linking.
 * Uses auto-linking: if email matches existing account, Google is linked automatically.
 *
 * @package ExtraChill\Users\OAuth
 */

defined( 'ABSPATH' ) || exit;

define( 'EC_GOOGLE_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs' );
define( 'EC_GOOGLE_ISSUER', 'https://accounts.google.com,accounts.google.com' );

/**
 * Verify Google ID token and extract user info.
 *
 * @param string $id_token Google ID token from frontend.
 * @return array|WP_Error {email, name, google_id, picture} or error.
 */
function ec_verify_google_token( $id_token ) {
	if ( empty( $id_token ) ) {
		return new WP_Error( 'missing_token', 'ID token is required.', array( 'status' => 400 ) );
	}

	$client_id = get_site_option( 'extrachill_google_client_id', '' );
	if ( empty( $client_id ) ) {
		return new WP_Error(
			'google_not_configured',
			'Google Sign-In is not configured.',
			array( 'status' => 500 )
		);
	}

	$payload = ec_verify_rs256_jwt( $id_token, EC_GOOGLE_JWKS_URL, $client_id, EC_GOOGLE_ISSUER );
	if ( is_wp_error( $payload ) ) {
		return new WP_Error(
			'invalid_google_token',
			'Google token verification failed: ' . $payload->get_error_message(),
			array( 'status' => 401 )
		);
	}

	if ( empty( $payload['sub'] ) ) {
		return new WP_Error( 'missing_sub', 'Token missing subject identifier.', array( 'status' => 401 ) );
	}

	if ( empty( $payload['email'] ) ) {
		return new WP_Error( 'missing_email', 'Token missing email address.', array( 'status' => 401 ) );
	}

	if ( empty( $payload['email_verified'] ) || $payload['email_verified'] !== true ) {
		return new WP_Error( 'email_not_verified', 'Google email is not verified.', array( 'status' => 401 ) );
	}

	return array(
		'google_id' => $payload['sub'],
		'email'     => $payload['email'],
		'name'      => isset( $payload['name'] ) ? $payload['name'] : '',
		'picture'   => isset( $payload['picture'] ) ? $payload['picture'] : '',
	);
}

/**
 * Find or create user from Google OAuth.
 * Auto-links if email matches existing account.
 *
 * @param array $google_user {email, name, google_id, picture}
 * @param bool  $from_join   Whether user came from /join flow.
 * @return array|WP_Error {user_id: int, is_new: bool, user: WP_User}
 */
function ec_oauth_google_user( $google_user, $from_join = false, $registration_data = array() ) {
	$google_id = isset( $google_user['google_id'] ) ? sanitize_text_field( $google_user['google_id'] ) : '';
	$email     = isset( $google_user['email'] ) ? sanitize_email( $google_user['email'] ) : '';
	$name      = isset( $google_user['name'] ) ? sanitize_text_field( $google_user['name'] ) : '';

	if ( empty( $google_id ) || empty( $email ) ) {
		return new WP_Error( 'missing_google_data', 'Missing Google user data.', array( 'status' => 400 ) );
	}

	// Check if user already linked with this Google ID.
	$existing_by_google = ec_get_user_by_google_id( $google_id );
	if ( $existing_by_google ) {
		return array(
			'user_id' => $existing_by_google->ID,
			'is_new'  => false,
			'user'    => $existing_by_google,
		);
	}

	// Check if user exists with this email (auto-link).
	$existing_by_email = get_user_by( 'email', $email );
	if ( $existing_by_email ) {
		ec_link_google_account( $existing_by_email->ID, $google_id );

		return array(
			'user_id' => $existing_by_email->ID,
			'is_new'  => false,
			'user'    => $existing_by_email,
		);
	}

	// Create new user.
	$username = function_exists( 'ec_generate_username_from_name' ) && ! empty( $name )
		? ec_generate_username_from_name( $name )
		: ( function_exists( 'ec_generate_username_from_email' )
			? ec_generate_username_from_email( $email )
			: 'user' . wp_rand( 10000, 99999 ) );

	$registration_data = array_merge(
		array(
			'username'  => $username,
			'password'  => wp_generate_password( 24, true, true ),
			'email'     => $email,
			'from_join' => $from_join,
		),
		array_filter( $registration_data )
	);

	$user_id = apply_filters( 'extrachill_create_community_user', false, $registration_data );
	if ( is_wp_error( $user_id ) ) {
		return new WP_Error(
			'user_creation_failed',
			'Failed to create user account.',
			array(
				'status' => 500,
				'reason' => $user_id->get_error_message(),
			)
		);
	}

	if ( empty( $user_id ) ) {
		return new WP_Error(
			'user_creation_failed',
			'Failed to create user account.',
			array( 'status' => 500 )
		);
	}

	// Link Google account.
	ec_link_google_account( (int) $user_id, $google_id );

	// Update display name from Google.
	if ( ! empty( $name ) ) {
		wp_update_user( array(
			'ID'           => (int) $user_id,
			'display_name' => $name,
		) );
	}

	$user = get_user_by( 'id', (int) $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found after creation.', array( 'status' => 500 ) );
	}

	return array(
		'user_id' => (int) $user_id,
		'is_new'  => true,
		'user'    => $user,
	);
}

/**
 * Link Google account to existing user.
 *
 * @param int    $user_id   WordPress user ID.
 * @param string $google_id Google sub claim.
 * @return bool Success.
 */
function ec_link_google_account( $user_id, $google_id ) {
	$user_id   = absint( $user_id );
	$google_id = sanitize_text_field( $google_id );

	if ( ! $user_id || empty( $google_id ) ) {
		return false;
	}

	update_user_meta( $user_id, 'google_user_id', $google_id );
	update_user_meta( $user_id, 'oauth_linked_at', time() );

	return true;
}

/**
 * Find user by Google ID.
 *
 * @param string $google_id Google sub claim.
 * @return WP_User|false User object or false.
 */
function ec_get_user_by_google_id( $google_id ) {
	$google_id = sanitize_text_field( $google_id );
	if ( empty( $google_id ) ) {
		return false;
	}

	$users = get_users( array(
		'meta_key'   => 'google_user_id',
		'meta_value' => $google_id,
		'number'     => 1,
	) );

	return ! empty( $users ) ? $users[0] : false;
}

/**
 * Google OAuth login service.
 * Authenticates via Google, optionally sets cookies, and returns tokens.
 *
 * @param string $id_token  Google ID token.
 * @param string $device_id Device ID (UUIDv4).
 * @param array  $options   { 'device_name' => string, 'remember' => bool, 'set_cookie' => bool, 'from_join' => bool, 'success_redirect_url' => string }.
 * @return array|WP_Error Token response or error.
 */
function ec_google_login_with_tokens( $id_token, $device_id, $options = array() ) {
	$device_name          = isset( $options['device_name'] ) ? (string) $options['device_name'] : '';
	$remember             = ! empty( $options['remember'] );
	$set_cookie           = ! empty( $options['set_cookie'] );
	$from_join            = ! empty( $options['from_join'] );
	$success_redirect_url = isset( $options['success_redirect_url'] ) ? (string) $options['success_redirect_url'] : '';
	$registration_source  = isset( $options['registration_source'] ) ? sanitize_text_field( (string) $options['registration_source'] ) : '';

	if ( empty( $registration_source ) ) {
		$registration_source = 'web';
	}

	if ( empty( $options['registration_method'] ) ) {
		$options['registration_method'] = 'google';
	}

	$options['registration_method'] = sanitize_text_field( (string) $options['registration_method'] );

	// Validate device_id.
	if ( empty( $device_id ) || ! extrachill_users_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			'device_id must be a UUID v4.',
			array( 'status' => 400 )
		);
	}

	// Verify Google token.
	$google_user = ec_verify_google_token( $id_token );
	if ( is_wp_error( $google_user ) ) {
		return $google_user;
	}

	// Find or create user.
	$registration_data = array(
		'registration_page'   => isset( $options['registration_page'] ) ? esc_url_raw( (string) $options['registration_page'] ) : '',
		'registration_source' => $registration_source,
		'registration_method' => sanitize_text_field( (string) $options['registration_method'] ),
	);

	$result = ec_oauth_google_user( $google_user, $from_join, $registration_data );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$user_id = $result['user_id'];
	$is_new  = $result['is_new'];
	$user    = $result['user'];

	// Verify community membership.
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'ec_get_blog_id() is required for token authentication.',
			array( 'status' => 500 )
		);
	}

	$community_blog_id = ec_get_blog_id( 'community' );
	if ( empty( $community_blog_id ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'Community blog ID is not available.',
			array( 'status' => 500 )
		);
	}

	if ( ! is_user_member_of_blog( $user_id, $community_blog_id ) ) {
		return new WP_Error(
			'extrachill_not_a_member',
			'User is not a member of the community site.',
			array( 'status' => 403 )
		);
	}

	// Set cookies if requested.
	if ( $set_cookie ) {
		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id, $remember );
		do_action( 'wp_login', $user->user_login, $user );
	}

	// Generate tokens.
	$access  = extrachill_users_generate_access_token( $user_id, $device_id );
	$refresh = extrachill_users_issue_refresh_token( $user_id, $device_id, $device_name );

	// Determine redirect URL.
	$onboarding_completed = function_exists( 'ec_is_onboarding_complete' )
		? ec_is_onboarding_complete( $user_id )
		: true;

	if ( $is_new || ! $onboarding_completed ) {
		// Store where user should return after onboarding.
		if ( ! empty( $success_redirect_url ) ) {
			update_user_meta( $user_id, 'onboarding_redirect_url', $success_redirect_url );
		}

		$redirect_url = function_exists( 'ec_get_site_url' )
			? ec_get_site_url( 'community' ) . '/onboarding/'
			: home_url( '/onboarding/' );
	} else {
		// Existing user with completed onboarding - redirect to success URL or community home.
		if ( ! empty( $success_redirect_url ) ) {
			$redirect_url = $success_redirect_url;
		} else {
			$redirect_url = function_exists( 'ec_get_site_url' )
				? ec_get_site_url( 'community' )
				: home_url();
		}
	}

	return array(
		'success'              => true,
		'access_token'         => $access['token'],
		'access_expires_at'    => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'        => $refresh['token'],
		'refresh_expires_at'   => gmdate( 'c', (int) $refresh['expires_at'] ),
		'onboarding_completed' => $onboarding_completed,
		'redirect_url'         => $redirect_url,
		'user'                 => array(
			'id'           => (int) $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'profile_url'  => function_exists( 'ec_get_user_profile_url' )
				? ec_get_user_profile_url( $user->ID, $user->user_email )
				: '',
		),
	);
}
