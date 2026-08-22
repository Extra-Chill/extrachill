<?php
/**
 * Auth token service functions (Extra Chill policy layer).
 *
 * As of the eu#76 auth-stack consolidation, the duplicated token PRIMITIVES
 * (signed-JWT access tokens, opaque refresh generation/hashing, the
 * {base_prefix}extrachill_refresh_tokens table, and the duplicate
 * determine_current_user bearer filter) have been DELETED. wp-native-auth now
 * owns the single token stack:
 *   - wp_native_auth_generate_access_token() / wp_native_auth_validate_access_token()
 *   - wp_native_auth_issue_refresh_token() / wp_native_auth_refresh_tokens()
 *   - wp_native_auth_revoke_refresh_token()
 *   - the {base_prefix}wp_native_auth_refresh_tokens table
 *   - the single determine_current_user @20 bearer filter
 *
 * This file keeps the Extra Chill SERVICE + POLICY layer that wp-native-auth
 * deliberately does not implement (2FA redirect, community-blog membership,
 * user blocking, invite redemption, onboarding response fields, cookie
 * issuance). Client-facing response shapes are UNCHANGED — every function
 * below returns exactly the same array shape it returned before the cutover.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth-tokens/tokens.php';

/**
 * Issue an access + refresh token pair via the wp-native-auth primitives.
 *
 * Internal helper that centralizes the delegation so the login/register/google
 * flows all mint tokens the same way. Returns the raw token pair the EC
 * response shapes wrap.
 *
 * @param int    $user_id     User ID.
 * @param string $device_id   Device ID (UUID v4).
 * @param string $device_name Optional device name.
 * @return array{access:array{token:string,expires_at:int}, refresh:array{token:string,expires_at:int}}
 */
function extrachill_users_mint_token_pair( int $user_id, string $device_id, string $device_name = '' ): array {
	$access  = wp_native_auth_generate_access_token( $user_id, $device_id );
	$refresh = wp_native_auth_issue_refresh_token( $user_id, $device_id, $device_name );

	return array(
		'access'  => $access,
		'refresh' => $refresh,
	);
}

/**
 * Build the Extra Chill user payload shape for auth responses.
 *
 * This is the EC client contract — id, username, display_name, avatar_url,
 * profile_url — and it must NOT change. (wp-native-auth's own payload adds
 * email/roles/registered_at, which is a different shape; we do not use it for
 * these EC routes.)
 *
 * @param WP_User $user User.
 * @return array<string,mixed>
 */
function extrachill_users_token_user_payload( WP_User $user ): array {
	return array(
		'id'           => (int) $user->ID,
		'username'     => $user->user_login,
		'display_name' => $user->display_name,
		'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
		'profile_url'  => function_exists( 'extrachill_get_user_profile_url' )
			? extrachill_get_user_profile_url( $user->ID, $user->user_email )
			: '',
	);
}

/**
 * Refresh service: rotate refresh token, extend expiry, return a new access token.
 *
 * Delegates the rotation + validation + rate-limit to the single wp-native-auth
 * token stack (wp_native_auth_refresh_tokens), which owns the refresh table.
 * The wp-native flow runs the wp_native_auth_pre_login filter, on which the EC
 * bridge enforces community-blog membership and user blocking — so EC policy is
 * preserved. We then RE-SHAPE the response to the EC client contract and apply
 * EC-only behavior (optional cookie issuance), so the client-facing shape is
 * identical to the pre-cutover response.
 *
 * @param string $refresh_token Refresh token.
 * @param string $device_id     Device ID (UUIDv4).
 * @param array  $options       Optional. { 'remember' => bool, 'set_cookie' => bool }.
 * @return array|WP_Error
 */
function extrachill_users_refresh_tokens( string $refresh_token, string $device_id, array $options = array() ) {
	$result = wp_native_auth_refresh_tokens( $refresh_token, $device_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$user = get_user_by( 'id', (int) ( $result['user']['id'] ?? 0 ) );
	if ( ! $user ) {
		return new WP_Error(
			'invalid_user',
			'User not found.',
			array( 'status' => 500 )
		);
	}

	$remember   = ! empty( $options['remember'] );
	$set_cookie = ! empty( $options['set_cookie'] );
	if ( $set_cookie ) {
		wp_set_current_user( (int) $user->ID, $user->user_login );
		wp_set_auth_cookie( (int) $user->ID, $remember );
	}

	return array(
		'access_token'       => $result['access_token'],
		'access_expires_at'  => $result['access_expires_at'],
		'refresh_token'      => $result['refresh_token'],
		'refresh_expires_at' => $result['refresh_expires_at'],
		'user'               => extrachill_users_token_user_payload( $user ),
	);
}

/**
 * Revoke a refresh token for a user/device.
 *
 * Delegates to the single wp-native-auth token stack.
 *
 * @param int    $user_id   User ID.
 * @param string $device_id Device ID (UUIDv4).
 * @return bool True if revoked, false if not found.
 */
function extrachill_users_revoke_refresh_token( int $user_id, string $device_id ): bool {
	return wp_native_auth_revoke_refresh_token( $user_id, $device_id );
}

/**
 * Check if a user has Two-Factor Authentication enabled and handle the redirect.
 *
 * Resolves the user by identifier, validates their password, and if they have
 * 2FA enabled, creates a Two Factor login nonce and returns a response that
 * instructs the frontend to redirect to the wp-login.php validate_2fa page.
 *
 * wp-native-auth has no 2FA path, so this EC-specific behavior is preserved
 * here and runs BEFORE token minting in the login flow below.
 *
 * @param string $identifier Username or email.
 * @param string $password   Password.
 * @param bool   $remember   Whether to remember the user.
 * @param string $redirect_to Optional. URL to redirect to after 2FA. Defaults to home_url().
 * @return array|WP_Error|null Array with requires_2fa on 2FA redirect, WP_Error on bad credentials, null to continue normal flow.
 */
function extrachill_users_maybe_handle_two_factor( string $identifier, string $password, bool $remember = false, string $redirect_to = '' ) {
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return null;
	}

	// Resolve the user by username or email.
	$user = get_user_by( 'login', $identifier );
	if ( ! $user ) {
		$user = get_user_by( 'email', $identifier );
	}
	if ( ! $user ) {
		return null; // Let wp_authenticate() handle the "user not found" error.
	}

	if ( ! Two_Factor_Core::is_user_using_two_factor( $user->ID ) ) {
		return null; // Not a 2FA user, continue normal flow.
	}

	// 2FA user detected — validate password before creating nonce.
	if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
		return new WP_Error(
			'invalid_credentials',
			'Invalid username or password.',
			array( 'status' => 401 )
		);
	}

	// Password valid, 2FA required. Create the login nonce.
	$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );
	if ( ! $login_nonce ) {
		return new WP_Error(
			'two_factor_nonce_failed',
			'Unable to initiate two-factor authentication. Please try again.',
			array( 'status' => 500 )
		);
	}

	// Build the validate_2fa URL with the same parameters Two Factor expects.
	$redirect_url = add_query_arg(
		array(
			'action'        => 'validate_2fa',
			'wp-auth-id'    => $user->ID,
			'wp-auth-nonce' => $login_nonce['key'],
			'rememberme'    => $remember ? 1 : 0,
			'redirect_to'   => $redirect_to ? $redirect_to : home_url(),
		),
		site_url( 'wp-login.php' )
	);

	return array(
		'requires_2fa' => true,
		'redirect_url' => $redirect_url,
	);
}

/**
 * Login service: authenticates, optionally sets cookies, and returns tokens.
 *
 * EC policy (2FA, community membership, user blocking) is enforced here; token
 * minting is delegated to the wp-native-auth primitives. Response shape is
 * unchanged.
 *
 * @param string $identifier Username or email.
 * @param string $password Password.
 * @param string $device_id Device ID (UUIDv4).
 * @param array  $options Optional. { 'device_name' => string, 'remember' => bool, 'set_cookie' => bool }.
 * @return array|WP_Error
 */
function extrachill_users_login_with_tokens( string $identifier, string $password, string $device_id, array $options = array() ) {
	$device_name      = isset( $options['device_name'] ) ? (string) $options['device_name'] : '';
	$remember         = ! empty( $options['remember'] );
	$set_cookie       = ! empty( $options['set_cookie'] );
	$redirect_to      = isset( $options['redirect_to'] ) ? (string) $options['redirect_to'] : '';
	$safe_redirect_to = function_exists( 'ec_users_is_valid_return_to_url' )
		&& ec_users_is_valid_return_to_url( $redirect_to )
		? esc_url_raw( $redirect_to )
		: home_url();

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

	/*
	 * Two-Factor Authentication compatibility.
	 *
	 * The Two Factor plugin's filter_authenticate() (priority 31) blocks REST API
	 * login for 2FA users by returning WP_Error('invalid_application_credentials').
	 * Its wp_login action handler also calls exit after rendering HTML.
	 *
	 * To avoid both interception points, we detect 2FA users before calling
	 * wp_authenticate(), validate the password manually, create a Two Factor
	 * login nonce, and return a redirect URL to the existing validate_2fa page.
	 */
	$two_factor_redirect = extrachill_users_maybe_handle_two_factor( $identifier, $password, $remember, $safe_redirect_to );
	if ( null !== $two_factor_redirect ) {
		return $two_factor_redirect;
	}

	$user = wp_authenticate( $identifier, $password );
	if ( is_wp_error( $user ) ) {
		return new WP_Error(
			'invalid_credentials',
			'Invalid username or password.',
			array( 'status' => 401 )
		);
	}

	if ( function_exists( 'extrachill_users_is_blocked' ) && extrachill_users_is_blocked( (int) $user->ID ) ) {
		return new WP_Error(
			'extrachill_user_blocked',
			'This account has been suspended. Please contact support if you believe this is a mistake.',
			array( 'status' => 403 )
		);
	}

	if ( ! is_user_member_of_blog( $user->ID, $community_blog_id ) ) {
		return new WP_Error(
			'extrachill_not_a_member',
			'User is not a member of the community site.',
			array( 'status' => 403 )
		);
	}

	if ( $set_cookie ) {
		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID, $remember );
		do_action( 'wp_login', $user->user_login, $user );
	}

	$tokens = extrachill_users_mint_token_pair( (int) $user->ID, $device_id, $device_name );

	return array(
		'access_token'       => $tokens['access']['token'],
		'access_expires_at'  => gmdate( 'c', (int) $tokens['access']['expires_at'] ),
		'refresh_token'      => $tokens['refresh']['token'],
		'refresh_expires_at' => gmdate( 'c', (int) $tokens['refresh']['expires_at'] ),
		'redirect_url'       => $safe_redirect_to,
		'user'               => extrachill_users_token_user_payload( $user ),
	);
}

/**
 * Register service: validates, creates user, optionally sets cookies, and returns tokens.
 *
 * User is created with an auto-generated username. Ordinary registrations can
 * participate immediately and customize their profile later; /join still
 * requires artist/professional onboarding.
 *
 * EC policy (Turnstile, invite redemption, onboarding redirect fields,
 * community provisioning) is enforced here; token minting is delegated to the
 * wp-native-auth primitives. Response shape is unchanged.
 *
 * @param array $payload Registration payload from REST route.
 * @return array|WP_Error
 */
function extrachill_users_register_with_tokens( array $payload ) {
	$email            = isset( $payload['email'] ) ? sanitize_email( (string) $payload['email'] ) : '';
	$password         = isset( $payload['password'] ) ? (string) $payload['password'] : '';
	$password_confirm = isset( $payload['password_confirm'] ) ? (string) $payload['password_confirm'] : '';
	$turnstile_token  = isset( $payload['turnstile_response'] ) ? (string) $payload['turnstile_response'] : '';
	$device_id        = isset( $payload['device_id'] ) ? (string) $payload['device_id'] : '';

	$device_name          = isset( $payload['device_name'] ) ? (string) $payload['device_name'] : '';
	$remember             = ! empty( $payload['remember'] );
	$set_cookie           = ! empty( $payload['set_cookie'] );
	$from_join            = ! empty( $payload['from_join'] );
	$invite_token         = isset( $payload['invite_token'] ) ? sanitize_text_field( (string) $payload['invite_token'] ) : '';
	$invite_artist_id     = isset( $payload['invite_artist_id'] ) ? absint( $payload['invite_artist_id'] ) : 0;
	$registration_page    = isset( $payload['registration_page'] ) ? esc_url_raw( (string) $payload['registration_page'] ) : '';
	$registration_source  = isset( $payload['registration_source'] ) ? sanitize_text_field( (string) $payload['registration_source'] ) : '';
	$registration_method  = isset( $payload['registration_method'] ) ? sanitize_text_field( (string) $payload['registration_method'] ) : '';
	$success_redirect_url = isset( $payload['success_redirect_url'] ) ? (string) $payload['success_redirect_url'] : '';
	if ( ! function_exists( 'ec_users_is_valid_return_to_url' )
		|| ! ec_users_is_valid_return_to_url( $success_redirect_url ) ) {
		$success_redirect_url = '';
	} else {
		$success_redirect_url = esc_url_raw( $success_redirect_url );
	}
	$referrer = isset( $payload['referrer'] ) ? esc_url_raw( (string) $payload['referrer'] ) : '';
	$utm      = ( isset( $payload['utm'] ) && function_exists( 'extrachill_users_sanitize_utm' ) )
		? extrachill_users_sanitize_utm( $payload['utm'] )
		: array();

	$check = extrachill_users_validate_password_registration( $email, $turnstile_token );
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	if ( empty( $email ) || empty( $password ) || empty( $password_confirm ) ) {
		return new WP_Error(
			'missing_fields',
			'email, password, and password_confirm are required.',
			array( 'status' => 400 )
		);
	}

	if ( ! is_email( $email ) ) {
		return new WP_Error(
			'invalid_email',
			'Email address is not valid.',
			array( 'status' => 400 )
		);
	}

	if ( $password !== $password_confirm ) {
		return new WP_Error(
			'password_mismatch',
			'Passwords do not match.',
			array( 'status' => 400 )
		);
	}

	if ( strlen( $password ) < 8 ) {
		return new WP_Error(
			'password_too_short',
			'Password must be at least 8 characters.',
			array( 'status' => 400 )
		);
	}

	if ( email_exists( $email ) ) {
		// Use generic message to prevent email enumeration.
		return new WP_Error(
			'registration_failed',
			'Registration could not be completed. Please try again or contact support.',
			array( 'status' => 400 )
		);
	}

	if ( empty( $device_id ) || ! extrachill_users_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			'device_id must be a UUID v4.',
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'ec_get_blog_id() is required for token registration.',
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

	$username = function_exists( 'ec_generate_username_from_email' )
		? ec_generate_username_from_email( $email )
		: 'user' . wp_rand( 10000, 99999 );

	$registration_data = array(
		'username'            => $username,
		'password'            => $password,
		'email'               => $email,
		'from_join'           => $from_join,
		'registration_source' => $registration_source,
		'registration_method' => $registration_method,
	);

	if ( ! empty( $registration_page ) ) {
		$registration_data['registration_page'] = $registration_page;
	}

	// Source attribution (last-touch): forward the external referrer and any
	// UTM parameters to the create-user ability, which persists them and folds
	// them into the user_registration analytics event.
	if ( ! empty( $referrer ) ) {
		$registration_data['referrer'] = $referrer;
	}

	if ( ! empty( $utm ) ) {
		$registration_data['utm'] = $utm;
	}

	$has_artist_invitation = false;
	if ( $invite_token || $invite_artist_id ) {
		$validation = ec_users_request_artist_invitation( $email, $invite_token, $invite_artist_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$has_artist_invitation = true;
	}

	$user_id = apply_filters( 'extrachill_create_community_user', false, $registration_data );
	if ( is_wp_error( $user_id ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Expected operational logging for failed auth/registration flows.
		error_log( 'User registration failed: ' . $user_id->get_error_code() . ' - ' . implode( ', ', $user_id->get_error_messages() ) );
		return new WP_Error(
			'registration_failed',
			'Registration could not be completed. Please try again or contact support.',
			array( 'status' => 500 )
		);
	}

	if ( empty( $user_id ) ) {
		return new WP_Error(
			'registration_failed',
			'User registration failed.',
			array( 'status' => 500 )
		);
	}

	update_user_meta( (int) $user_id, 'registration_timestamp', current_time( 'mysql' ) );

	if ( $from_join && ! empty( $success_redirect_url ) ) {
		update_user_meta( (int) $user_id, 'onboarding_redirect_url', $success_redirect_url );
	}

	if ( function_exists( 'extrachill_network_subscribe' ) ) {
		$sync_result = extrachill_network_subscribe( $email, 'registration' );
		if ( isset( $sync_result['success'] ) && ! $sync_result['success'] ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Expected operational logging for newsletter sync failures.
			error_log( 'Registration newsletter subscription failed: ' . ( isset( $sync_result['message'] ) ? $sync_result['message'] : '' ) );
		}
	}

	$processed_invite_artist_id = null;
	$invitation_outcome         = array();
	if ( $has_artist_invitation ) {
		$acceptance = ec_users_request_artist_invitation( $email, $invite_token, $invite_artist_id, (int) $user_id );
		if ( is_wp_error( $acceptance ) ) {
			$invitation_outcome = ec_users_classify_artist_invitation_error( $acceptance );
		} else {
			$processed_invite_artist_id = $invite_artist_id;
		}
	}

	$user = get_user_by( 'id', (int) $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'invalid_user',
			'User not found after registration.',
			array( 'status' => 500 )
		);
	}

	if ( ! is_user_member_of_blog( (int) $user_id, $community_blog_id ) ) {
		return new WP_Error(
			'extrachill_not_a_member',
			'User is not a member of the community site.',
			array( 'status' => 403 )
		);
	}

	if ( $set_cookie ) {
		wp_set_current_user( (int) $user_id, $user->user_login );
		wp_set_auth_cookie( (int) $user_id, $remember );
		do_action( 'wp_login', $user->user_login, $user );
	}

	$tokens = extrachill_users_mint_token_pair( (int) $user_id, $device_id, $device_name );

	$account_created_token = $set_cookie ? ec_users_create_account_created_token( (int) $user_id ) : '';
	$redirect_url          = ec_users_post_registration_redirect_url( $from_join, false, $success_redirect_url, $account_created_token );

	$response = array(
		'access_token'         => $tokens['access']['token'],
		'access_expires_at'    => gmdate( 'c', (int) $tokens['access']['expires_at'] ),
		'refresh_token'        => $tokens['refresh']['token'],
		'refresh_expires_at'   => gmdate( 'c', (int) $tokens['refresh']['expires_at'] ),
		'onboarding_completed' => false,
		'redirect_url'         => $redirect_url,
		'user'                 => extrachill_users_token_user_payload( $user ),
	);

	if ( $processed_invite_artist_id ) {
		$response['invite_artist_id'] = (int) $processed_invite_artist_id;
	}
	if ( $has_artist_invitation ) {
		$response['artist_invitation_status'] = $invitation_outcome ? $invitation_outcome['status'] : 'applied';
		if ( $invitation_outcome ) {
			$response['artist_invitation_retryable'] = $invitation_outcome['retryable'];
			$response['artist_invitation_error']     = $invitation_outcome['error'];
		}
	}

	return $response;
}
