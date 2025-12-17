<?php
/**
 * Bearer token authentication via determine_current_user filter.
 *
 * Validates JWT access tokens from Authorization header and authenticates the user.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'determine_current_user', 'extrachill_users_authenticate_bearer_token', 20 );

/**
 * Authenticates user from Bearer token in Authorization header.
 *
 * @param int|false $user_id Current user ID or false.
 * @return int|false User ID if token valid, otherwise passthrough.
 */
function extrachill_users_authenticate_bearer_token( $user_id ) {
	if ( $user_id ) {
		return $user_id;
	}

	$token = extrachill_users_get_bearer_token();
	if ( ! $token ) {
		return $user_id;
	}

	$payload = extrachill_users_validate_access_token( $token );
	if ( ! $payload ) {
		return $user_id;
	}

	return (int) $payload['user_id'];
}

/**
 * Extracts Bearer token from Authorization header.
 *
 * @return string|null Token string or null if not present.
 */
function extrachill_users_get_bearer_token(): ?string {
	$auth_header = null;

	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
	} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
	} elseif ( function_exists( 'getallheaders' ) ) {
		$headers = getallheaders();
		if ( isset( $headers['Authorization'] ) ) {
			$auth_header = sanitize_text_field( $headers['Authorization'] );
		} elseif ( isset( $headers['authorization'] ) ) {
			$auth_header = sanitize_text_field( $headers['authorization'] );
		}
	}

	if ( ! $auth_header || 0 !== strpos( $auth_header, 'Bearer ' ) ) {
		return null;
	}

	return substr( $auth_header, 7 );
}

/**
 * Validates JWT access token and returns payload if valid.
 *
 * @param string $token JWT token string.
 * @return array|null Payload array or null if invalid.
 */
function extrachill_users_validate_access_token( string $token ): ?array {
	$parts = explode( '.', $token );
	if ( 3 !== count( $parts ) ) {
		return null;
	}

	list( $header_b64, $payload_b64, $signature_b64 ) = $parts;

	$expected_signature = hash_hmac( 'sha256', "{$header_b64}.{$payload_b64}", wp_salt( 'auth' ), true );
	$expected_signature_b64 = extrachill_users_base64url_encode( $expected_signature );

	if ( ! hash_equals( $expected_signature_b64, $signature_b64 ) ) {
		return null;
	}

	$payload_json = extrachill_users_base64url_decode( $payload_b64 );
	$payload = json_decode( $payload_json, true );

	if ( ! is_array( $payload ) ) {
		return null;
	}

	if ( empty( $payload['user_id'] ) || empty( $payload['exp'] ) ) {
		return null;
	}

	if ( (int) $payload['exp'] < time() ) {
		return null;
	}

	$user = get_user_by( 'id', (int) $payload['user_id'] );
	if ( ! $user ) {
		return null;
	}

	return $payload;
}
