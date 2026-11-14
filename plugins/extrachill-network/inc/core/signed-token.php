<?php
/**
 * Purpose-bound signed tokens for non-secret continuation claims.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

// Temporary compatibility window: delete every legacy verification branch after this date.
if ( ! defined( 'EXTRACHILL_SIGN_LEGACY_UNTIL' ) ) {
	define( 'EXTRACHILL_SIGN_LEGACY_UNTIL', 1797724800 ); // 2026-12-20 00:00:00 UTC.
}

// Token envelope owned by this primitive. Consumers that describe a token in a
// schema must derive from these (see extrachill_signed_token_schema()) rather
// than re-describe the format, or the next envelope change breaks them (#1352).
if ( ! defined( 'EXTRACHILL_SIGNED_TOKEN_MAX_LENGTH' ) ) {
	define( 'EXTRACHILL_SIGNED_TOKEN_MAX_LENGTH', 8192 );
}
if ( ! defined( 'EXTRACHILL_SIGNED_TOKEN_PATTERN_BODY' ) ) {
	define( 'EXTRACHILL_SIGNED_TOKEN_PATTERN_BODY', '[A-Za-z0-9_-]+\\.[a-f0-9]{64}' );
}

/**
 * JSON Schema for a value carrying an extrachill_sign() token.
 *
 * Use this in ability input/output schemas instead of hand-writing a length
 * or pattern, so schemas track the primitive's envelope.
 *
 * @param bool $allow_empty Whether an empty string (no token issued) is valid.
 * @return array<string, mixed> JSON Schema fragment.
 */
function extrachill_signed_token_schema( bool $allow_empty = false ): array {
	return array(
		'type'      => 'string',
		'pattern'   => $allow_empty
			? '^(?:' . EXTRACHILL_SIGNED_TOKEN_PATTERN_BODY . ')?$'
			: '^' . EXTRACHILL_SIGNED_TOKEN_PATTERN_BODY . '$',
		'maxLength' => EXTRACHILL_SIGNED_TOKEN_MAX_LENGTH,
	);
}

/**
 * Sign claims. Invalid purposes, TTLs or unencodable claims return an empty token.
 *
 * @param string $purpose Stable domain identifier.
 * @param array  $claims  Non-secret claims.
 * @param int    $ttl     Lifetime in seconds; explicitly use zero for no expiry.
 * @return string URL-safe token, or empty string on invalid input.
 */
function extrachill_sign( string $purpose, array $claims, int $ttl ): string {
	if ( 1 !== preg_match( '/^[a-z0-9_.-]{1,64}$/D', $purpose ) || 0 > $ttl || PHP_INT_MAX - time() < $ttl ) {
		return '';
	}

	try {
		$json = wp_json_encode(
			array(
				'p' => $purpose,
				'c' => $claims,
				'e' => 0 === $ttl ? 0 : time() + $ttl,
			)
		);
		if ( false === $json ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport for signed, non-secret claims.
		$payload = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		$key     = hash_hmac( 'sha256', 'extrachill-sign:' . $purpose, wp_salt( 'auth' ) );
		$token   = $payload . '.' . hash_hmac( 'sha256', $payload, $key );
		return EXTRACHILL_SIGNED_TOKEN_MAX_LENGTH >= strlen( $token ) ? $token : '';
	} catch ( Throwable $error ) {
		return '';
	}
}

/**
 * Verify a purpose-bound token without throwing on malformed input.
 *
 * @param string   $purpose Expected domain identifier.
 * @param string   $token   Signed token (at most 8 KB).
 * @param int|null $now     Optional current Unix time for deterministic checks.
 * @return array|null Verified claims, or null.
 */
function extrachill_verify( string $purpose, string $token, ?int $now = null ): ?array {
	if ( EXTRACHILL_SIGNED_TOKEN_MAX_LENGTH < strlen( $token ) || 1 !== preg_match( '/^[a-z0-9_.-]{1,64}$/D', $purpose ) ) {
		return null;
	}

	try {
		if ( 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts ) ) { // Mirrors EXTRACHILL_SIGNED_TOKEN_PATTERN_BODY with capture groups.
			return null;
		}
		$key = hash_hmac( 'sha256', 'extrachill-sign:' . $purpose, wp_salt( 'auth' ) );
		if ( ! hash_equals( hash_hmac( 'sha256', $parts[1], $key ), $parts[2] ) ) {
			return null;
		}
		$base64 = strtr( $parts[1], '-_', '+/' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the authenticated URL-safe transport.
		$json = base64_decode( $base64 . str_repeat( '=', ( 4 - strlen( $base64 ) % 4 ) % 4 ), true );
		if ( false === $json ) {
			return null;
		}
		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) || array( 'p', 'c', 'e' ) !== array_keys( $payload )
			|| $purpose !== $payload['p'] || ! is_array( $payload['c'] ) || ! is_int( $payload['e'] )
			|| 0 > $payload['e'] || ( 0 !== $payload['e'] && $payload['e'] <= ( $now ?? time() ) ) ) {
			return null;
		}
		return $payload['c'];
	} catch ( Throwable $error ) {
		return null;
	}
}
