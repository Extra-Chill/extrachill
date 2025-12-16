<?php
/**
 * Auth token service functions.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth-tokens/db.php';
require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth-tokens/tokens.php';

/**
 * Converts a UNIX timestamp to a GMT MySQL datetime string.
 *
 * @param int $timestamp UNIX timestamp.
 * @return string
 */
function extrachill_users_mysql_gmt_from_ts( int $timestamp ): string {
	return gmdate( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Issues a refresh token for a user/device.
 *
 * @param int    $user_id User ID.
 * @param string $device_id Device ID (UUIDv4).
 * @param string $device_name Device name.
 * @return array{token:string,expires_at:int}
 */
function extrachill_users_issue_refresh_token( int $user_id, string $device_id, string $device_name = '' ): array {
	global $wpdb;

	$table_name = extrachill_users_refresh_token_table_name();

	$now_ts     = time();
	$now        = extrachill_users_mysql_gmt_from_ts( $now_ts );
	$expires_ts = $now_ts + EXTRACHILL_USERS_REFRESH_TOKEN_TTL;
	$expires_at = extrachill_users_mysql_gmt_from_ts( $expires_ts );

	$refresh_token = wp_generate_password( 64, false, false );
	$token_hash    = extrachill_users_hash_refresh_token( $refresh_token );

	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE user_id = %d AND device_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant from extrachill_users_refresh_token_table_name().
			$user_id,
			$device_id
		)
	);

	$data = array(
		'user_id'            => $user_id,
		'device_id'          => $device_id,
		'device_name'        => $device_name ? $device_name : null,
		'refresh_token_hash' => $token_hash,
		'last_used_at'       => $now,
		'expires_at'         => $expires_at,
		'revoked_at'         => null,
	);

	if ( $existing_id ) {
		$wpdb->update(
			$table_name,
			$data,
			array( 'id' => (int) $existing_id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	} else {
		$data['created_at'] = $now;
		$wpdb->insert(
			$table_name,
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	return array(
		'token'      => $refresh_token,
		'expires_at' => $expires_ts,
	);
}

/**
 * Refresh service: rotates refresh token, extends expiry, and returns a new access token.
 *
 * @param string $refresh_token Refresh token.
 * @param string $device_id Device ID (UUIDv4).
 * @param array  $options Optional. { 'remember' => bool, 'set_cookie' => bool }.
 * @return array|WP_Error
 */
function extrachill_users_refresh_tokens( string $refresh_token, string $device_id, array $options = array() ) {
	global $wpdb;

	$table_name = extrachill_users_refresh_token_table_name();
	$token_hash = extrachill_users_hash_refresh_token( $refresh_token );

	$session = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE device_id = %s AND refresh_token_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant from extrachill_users_refresh_token_table_name().
			$device_id,
			$token_hash
		),
		ARRAY_A
	);

	if ( empty( $session ) ) {
		return new WP_Error(
			'invalid_refresh_token',
			'Invalid refresh token.',
			array( 'status' => 401 )
		);
	}

	if ( ! empty( $session['revoked_at'] ) ) {
		return new WP_Error(
			'invalid_refresh_token',
			'Refresh token has been revoked.',
			array( 'status' => 401 )
		);
	}

	$now_ts = time();
	$now    = extrachill_users_mysql_gmt_from_ts( $now_ts );

	$expires_at_ts = strtotime( (string) $session['expires_at'] );
	if ( $expires_at_ts && $expires_at_ts < $now_ts ) {
		return new WP_Error(
			'refresh_token_expired',
			'Refresh token has expired.',
			array( 'status' => 401 )
		);
	}

	$user_id = (int) $session['user_id'];
	$user    = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'invalid_user',
			'User not found.',
			array( 'status' => 500 )
		);
	}

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

	$new_refresh_token = wp_generate_password( 64, false, false );
	$new_token_hash    = extrachill_users_hash_refresh_token( $new_refresh_token );
	$new_expires_ts    = $now_ts + EXTRACHILL_USERS_REFRESH_TOKEN_TTL;
	$new_expires_at    = extrachill_users_mysql_gmt_from_ts( $new_expires_ts );

	$updated = $wpdb->update(
		$table_name,
		array(
			'refresh_token_hash' => $new_token_hash,
			'last_used_at'       => $now,
			'expires_at'         => $new_expires_at,
			'revoked_at'         => null,
		),
		array( 'id' => (int) $session['id'] ),
		array( '%s', '%s', '%s', '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		return new WP_Error(
			'refresh_update_failed',
			'Failed to rotate refresh token.',
			array( 'status' => 500 )
		);
	}

	$remember   = ! empty( $options['remember'] );
	$set_cookie = ! empty( $options['set_cookie'] );
	if ( $set_cookie ) {
		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id, $remember );
	}

	$access = extrachill_users_generate_access_token( $user_id, $device_id );

	return array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $new_refresh_token,
		'refresh_expires_at' => gmdate( 'c', (int) $new_expires_ts ),
		'user'               => array(
			'id'           => (int) $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'profile_url'  => function_exists( 'ec_get_user_profile_url' )
				? ec_get_user_profile_url( $user->ID, $user->user_email )
				: '',
		),
	);
}

/**
 * Login service: authenticates, optionally sets cookies, and returns tokens.
 *
 * @param string $identifier Username or email.
 * @param string $password Password.
 * @param string $device_id Device ID (UUIDv4).
 * @param array  $options Optional. { 'device_name' => string, 'remember' => bool, 'set_cookie' => bool }.
 * @return array|WP_Error
 */
function extrachill_users_login_with_tokens( string $identifier, string $password, string $device_id, array $options = array() ) {
	$device_name = isset( $options['device_name'] ) ? (string) $options['device_name'] : '';
	$remember    = ! empty( $options['remember'] );
	$set_cookie  = ! empty( $options['set_cookie'] );

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

	$user = wp_authenticate( $identifier, $password );
	if ( is_wp_error( $user ) ) {
		return new WP_Error(
			'invalid_credentials',
			'Invalid username or password.',
			array( 'status' => 401 )
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

	$access  = extrachill_users_generate_access_token( $user->ID, $device_id );
	$refresh = extrachill_users_issue_refresh_token( $user->ID, $device_id, $device_name );

	return array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $refresh['token'],
		'refresh_expires_at' => gmdate( 'c', (int) $refresh['expires_at'] ),
		'user'               => array(
			'id'           => (int) $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'profile_url'  => function_exists( 'ec_get_user_profile_url' )
				? ec_get_user_profile_url( $user->ID, $user->user_email )
				: '',
		),
	);
}

/**
 * Register service: validates, creates user, optionally sets cookies, and returns tokens.
 *
 * @param array $payload Registration payload from REST route.
 * @return array|WP_Error
 */
function extrachill_users_register_with_tokens( array $payload ) {
	$username         = isset( $payload['username'] ) ? sanitize_user( (string) $payload['username'] ) : '';
	$email            = isset( $payload['email'] ) ? sanitize_email( (string) $payload['email'] ) : '';
	$password         = isset( $payload['password'] ) ? (string) $payload['password'] : '';
	$password_confirm = isset( $payload['password_confirm'] ) ? (string) $payload['password_confirm'] : '';
	$turnstile_token  = isset( $payload['turnstile_response'] ) ? (string) $payload['turnstile_response'] : '';
	$device_id        = isset( $payload['device_id'] ) ? (string) $payload['device_id'] : '';

	$device_name          = isset( $payload['device_name'] ) ? (string) $payload['device_name'] : '';
	$remember             = ! empty( $payload['remember'] );
	$set_cookie           = ! empty( $payload['set_cookie'] );
	$user_is_artist       = ! empty( $payload['user_is_artist'] );
	$user_is_professional = ! empty( $payload['user_is_professional'] );
	$invite_token         = isset( $payload['invite_token'] ) ? sanitize_text_field( (string) $payload['invite_token'] ) : '';
	$invite_artist_id     = isset( $payload['invite_artist_id'] ) ? absint( $payload['invite_artist_id'] ) : 0;
	$registration_page    = isset( $payload['registration_page'] ) ? esc_url_raw( (string) $payload['registration_page'] ) : '';
	$success_redirect_url = isset( $payload['success_redirect_url'] ) ? esc_url_raw( (string) $payload['success_redirect_url'] ) : '';

	if ( empty( $turnstile_token ) ) {
		return new WP_Error(
			'turnstile_required',
			'Captcha verification required. Please complete the challenge and try again.',
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'Cloudflare Turnstile verification is required for registration.',
			array( 'status' => 500 )
		);
	}

	if ( ! ec_verify_turnstile_response( $turnstile_token ) ) {
		return new WP_Error(
			'turnstile_failed',
			'Captcha verification failed. Please try again.',
			array( 'status' => 400 )
		);
	}

	if ( empty( $username ) || empty( $email ) || empty( $password ) || empty( $password_confirm ) ) {
		return new WP_Error(
			'missing_fields',
			'username, email, password, and password_confirm are required.',
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

	if ( username_exists( $username ) || email_exists( $email ) ) {
		return new WP_Error(
			'user_exists',
			'An account already exists with this username or email.',
			array( 'status' => 409 )
		);
	}

	$from_join = isset( $payload['from_join'] ) ? sanitize_text_field( (string) $payload['from_join'] ) : '';
	if ( 'true' === $from_join && ! $user_is_artist && ! $user_is_professional ) {
		return new WP_Error(
			'join_flow_selection_required',
			'To create your extrachill.link page, please select "I am a musician" or "I work in the music industry".',
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

	$registration_data = array(
		'username'             => $username,
		'password'             => $password,
		'email'                => $email,
		'user_is_artist'       => $user_is_artist,
		'user_is_professional' => $user_is_professional,
	);

	if ( ! empty( $registration_page ) ) {
		$registration_data['registration_page'] = $registration_page;
	}

	$user_id = apply_filters( 'extrachill_create_community_user', false, $registration_data );
	if ( is_wp_error( $user_id ) ) {
		return new WP_Error(
			'registration_failed',
			'User registration failed.',
			array(
				'status'  => 500,
				'errors'  => $user_id->get_error_messages(),
				'reason'  => $user_id->get_error_code(),
				'details' => $user_id->get_error_data(),
			)
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

	if ( function_exists( 'extrachill_multisite_subscribe' ) ) {
		$sync_result = extrachill_multisite_subscribe( $email, 'registration' );
		if ( isset( $sync_result['success'] ) && ! $sync_result['success'] ) {
			error_log( 'Registration newsletter subscription failed: ' . ( isset( $sync_result['message'] ) ? $sync_result['message'] : '' ) );
		}
	}

	$processed_invite_artist_id = null;
	if ( $invite_token && $invite_artist_id && function_exists( 'bp_get_pending_invitations' ) && function_exists( 'bp_add_artist_membership' ) && function_exists( 'bp_remove_pending_invitation' ) ) {
		$pending_invitations         = bp_get_pending_invitations( $invite_artist_id );
		$valid_invite_id_for_removal = null;

		foreach ( $pending_invitations as $invite ) {
			if ( isset( $invite['token'] ) && $invite['token'] === $invite_token &&
				isset( $invite['email'] ) && strtolower( $invite['email'] ) === strtolower( $email ) &&
				isset( $invite['status'] ) && 'invited_new_user' === $invite['status'] ) {
				$valid_invite_id_for_removal = isset( $invite['id'] ) ? $invite['id'] : null;
				break;
			}
		}

		if ( $valid_invite_id_for_removal ) {
			if ( bp_add_artist_membership( (int) $user_id, $invite_artist_id ) ) {
				bp_remove_pending_invitation( $invite_artist_id, $valid_invite_id_for_removal );
				$processed_invite_artist_id = $invite_artist_id;
			}
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

	$access  = extrachill_users_generate_access_token( (int) $user_id, $device_id );
	$refresh = extrachill_users_issue_refresh_token( (int) $user_id, $device_id, $device_name );

	$response = array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $refresh['token'],
		'refresh_expires_at' => gmdate( 'c', (int) $refresh['expires_at'] ),
		'user'               => array(
			'id'           => (int) $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'profile_url'  => function_exists( 'ec_get_user_profile_url' ) ? ec_get_user_profile_url( $user->ID, $user->user_email ) : '',
		),
	);

	if ( $processed_invite_artist_id ) {
		$response['invite_artist_id'] = (int) $processed_invite_artist_id;
	}

	if ( ! empty( $success_redirect_url ) ) {
		$response['redirect_url'] = $success_redirect_url;
	}

	return $response;
}
