<?php
/**
 * Token helpers.
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_ACCESS_TOKEN_TTL  = 15 * MINUTE_IN_SECONDS;
const EXTRACHILL_USERS_REFRESH_TOKEN_TTL = 30 * DAY_IN_SECONDS;

function extrachill_users_base64url_encode( string $data ): string {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function extrachill_users_base64url_decode( string $data ): string {
	$remainder = strlen( $data ) % 4;
	if ( 0 !== $remainder ) {
		$data .= str_repeat( '=', 4 - $remainder );
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for JWT decoding, not obfuscation.
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

	// wp_json_encode() returns string|false. Both inputs are simple
	// associative arrays of scalars so encoding cannot realistically fail,
	// but PHPStan can't see that and base64url_encode() requires string.
	$header_b64  = extrachill_users_base64url_encode( (string) wp_json_encode( $header ) );
	$payload_b64 = extrachill_users_base64url_encode( (string) wp_json_encode( $payload ) );

	$signature_raw = hash_hmac( 'sha256', "{$header_b64}.{$payload_b64}", wp_salt( 'auth' ), true );
	$signature_b64 = extrachill_users_base64url_encode( $signature_raw );

	return array(
		'token'      => "{$header_b64}.{$payload_b64}.{$signature_b64}",
		'expires_at' => $expires,
	);
}

/**
 * Generate an opaque refresh token.
 *
 * 256 random bits, base64url-encoded (no padding) = 43 chars from the
 * `[A-Za-z0-9_-]` alphabet (RFC 7235 bearer token alphabet). It survives
 * HTTP headers, URL params, JSON bodies, command-line args, and shell
 * escaping without any character class needing escaping.
 *
 * Deliberately NOT `wp_generate_password( 64, true, true )`: its
 * "extra special chars" alphabet adds `<`, `>`, `&`, `"`, `'`, and a
 * literal space — all hostile to HTTP transport. `sanitize_text_field()`
 * HTML-encodes the first five (mangling the token in transit), and a
 * literal space gets corrupted by `trim()` on the receiving side. This
 * caused ~45% silent auth failures in extrachill.com production before
 * the sibling wp-native-auth plugin switched to this generator. We match
 * wp-native-auth/inc/tokens.php exactly to avoid a second divergence.
 *
 * Entropy: 32 bytes of CSPRNG (`random_bytes`) = 256 bits, well above
 * the 128-bit threshold for opaque token unguessability.
 *
 * @return string Base64url-encoded random token (43 chars).
 */
function extrachill_users_generate_refresh_token(): string {
	return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for HTTP-safe API auth tokens, not obfuscation.
}

/**
 * Hash refresh token for storage.
 */
function extrachill_users_hash_refresh_token( string $refresh_token ): string {
	return hash_hmac( 'sha256', $refresh_token, wp_salt( 'auth' ) );
}
