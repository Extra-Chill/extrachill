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
 * Sanitization: we deliberately do NOT use sanitize_text_field() on
 * the Authorization header. sanitize_text_field() HTML-encodes
 * characters like `<`, `>`, `&`, `"`, and `'`, which is destructive
 * for opaque bearer tokens that may contain any of those characters
 * (see chubes4/wp-native#44 for a sibling bug where this caused ~45%
 * of opaque tokens to silently fail authentication).
 *
 * Extra Chill's own JWT tokens are base64url-encoded by construction
 * and never contain HTML-special characters, so the previous code
 * happened to work — but the same anti-pattern is a latent risk for
 * any future bearer token format we accept here. The fix is to use
 * a narrow header sanitizer that strips only CR/LF (HTTP header
 * injection protection) and null bytes, then trims outer whitespace.
 *
 * @return string|null Token string or null if not present.
 */
function extrachill_users_get_bearer_token(): ?string {
	$auth_header = null;

	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$auth_header = extrachill_users_sanitize_authorization_header( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
	} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$auth_header = extrachill_users_sanitize_authorization_header( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
	} elseif ( function_exists( 'getallheaders' ) ) {
		$headers = getallheaders();
		if ( isset( $headers['Authorization'] ) ) {
			$auth_header = extrachill_users_sanitize_authorization_header( $headers['Authorization'] );
		} elseif ( isset( $headers['authorization'] ) ) {
			$auth_header = extrachill_users_sanitize_authorization_header( $headers['authorization'] );
		}
	}

	if ( ! $auth_header || 0 !== strpos( $auth_header, 'Bearer ' ) ) {
		return null;
	}

	return substr( $auth_header, 7 );
}

/**
 * Narrow sanitizer for the Authorization header.
 *
 * Strips CR/LF (HTTP header-injection protection) and null bytes,
 * then trims outer whitespace. Does NOT call sanitize_text_field()
 * — see the extraction docblock above for why that's destructive
 * for opaque bearer tokens.
 *
 * @param mixed $value Raw Authorization header value.
 * @return string Sanitized header string.
 */
function extrachill_users_sanitize_authorization_header( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}

	return trim( str_replace( array( "\r", "\n", "\0" ), '', $value ) );
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

	$expected_signature     = hash_hmac( 'sha256', "{$header_b64}.{$payload_b64}", wp_salt( 'auth' ), true );
	$expected_signature_b64 = extrachill_users_base64url_encode( $expected_signature );

	if ( ! hash_equals( $expected_signature_b64, $signature_b64 ) ) {
		return null;
	}

	$payload_json = extrachill_users_base64url_decode( $payload_b64 );
	$payload      = json_decode( $payload_json, true );

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

	if ( function_exists( 'extrachill_users_is_blocked' ) && extrachill_users_is_blocked( (int) $user->ID ) ) {
		return null;
	}

	return $payload;
}
