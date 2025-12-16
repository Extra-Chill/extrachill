<?php
/**
 * Token helpers.
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_ACCESS_TOKEN_TTL = 15 * MINUTE_IN_SECONDS;
const EXTRACHILL_USERS_REFRESH_TOKEN_TTL = 30 * DAY_IN_SECONDS;

function extrachill_users_base64url_encode( string $data ): string {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function extrachill_users_base64url_decode( string $data ): string {
	$remainder = strlen( $data ) % 4;
	if ( 0 !== $remainder ) {
		$data .= str_repeat( '=', 4 - $remainder );
	}

	return base64_decode( strtr( $data, '-_', '+/' ) );
}

/**
 * Validate UUIDv4.
 */
function extrachill_users_is_uuid_v4( string $uuid ): bool {
	return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid );
}

/**
 * Generate signed access token.
 */
function extrachill_users_generate_access_token( int $user_id, string $device_id ): array {
	$issued_at = time();
	$expires   = $issued_at + EXTRACHILL_USERS_ACCESS_TOKEN_TTL;

	$header  = array(
		'alg' => 'HS256',
		'typ' => 'JWT',
	);
	$payload = array(
		'user_id'   => $user_id,
		'device_id' => $device_id,
		'iat'       => $issued_at,
		'exp'       => $expires,
	);

	$header_b64  = extrachill_users_base64url_encode( wp_json_encode( $header ) );
	$payload_b64 = extrachill_users_base64url_encode( wp_json_encode( $payload ) );

	$signature_raw = hash_hmac( 'sha256', "{$header_b64}.{$payload_b64}", wp_salt( 'auth' ), true );
	$signature_b64 = extrachill_users_base64url_encode( $signature_raw );

	return array(
		'token'      => "{$header_b64}.{$payload_b64}.{$signature_b64}",
		'expires_at' => $expires,
	);
}

/**
 * Hash refresh token for storage.
 */
function extrachill_users_hash_refresh_token( string $refresh_token ): string {
	return hash_hmac( 'sha256', $refresh_token, wp_salt( 'auth' ) );
}
