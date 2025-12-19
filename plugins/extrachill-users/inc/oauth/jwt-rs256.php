<?php
/**
 * RS256 JWT Verification
 *
 * Verifies RS256-signed JWTs using public keys from JWKS endpoints.
 * Used for Google and Apple ID token verification.
 *
 * @package ExtraChill\Users\OAuth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verify RS256 JWT using JWKS.
 *
 * @param string $token    JWT token.
 * @param string $jwks_url JWKS endpoint URL.
 * @param string $audience Expected audience (aud claim).
 * @param string $issuer   Expected issuer (iss claim), or comma-separated list.
 * @return array|WP_Error Decoded payload or error.
 */
function ec_verify_rs256_jwt( $token, $jwks_url, $audience, $issuer ) {
	$parts = explode( '.', $token );
	if ( count( $parts ) !== 3 ) {
		return new WP_Error( 'invalid_token', 'Invalid JWT format.' );
	}

	list( $header_b64, $payload_b64, $signature_b64 ) = $parts;

	$header = json_decode( ec_base64url_decode( $header_b64 ), true );
	if ( ! $header || ! isset( $header['alg'] ) || $header['alg'] !== 'RS256' ) {
		return new WP_Error( 'invalid_algorithm', 'Token must use RS256 algorithm.' );
	}

	$payload = json_decode( ec_base64url_decode( $payload_b64 ), true );
	if ( ! $payload ) {
		return new WP_Error( 'invalid_payload', 'Could not decode token payload.' );
	}

	// Validate expiration.
	if ( ! isset( $payload['exp'] ) || $payload['exp'] < time() ) {
		return new WP_Error( 'token_expired', 'Token has expired.' );
	}

	// Validate issued at (allow 5 minute clock skew).
	if ( isset( $payload['iat'] ) && $payload['iat'] > time() + 300 ) {
		return new WP_Error( 'token_not_valid_yet', 'Token issued in the future.' );
	}

	// Validate audience.
	$token_aud = isset( $payload['aud'] ) ? $payload['aud'] : '';
	if ( is_array( $token_aud ) ) {
		if ( ! in_array( $audience, $token_aud, true ) ) {
			return new WP_Error( 'invalid_audience', 'Token audience mismatch.' );
		}
	} elseif ( $token_aud !== $audience ) {
		return new WP_Error( 'invalid_audience', 'Token audience mismatch.' );
	}

	// Validate issuer (support comma-separated list for Google's two issuers).
	$valid_issuers = array_map( 'trim', explode( ',', $issuer ) );
	$token_iss     = isset( $payload['iss'] ) ? $payload['iss'] : '';
	if ( ! in_array( $token_iss, $valid_issuers, true ) ) {
		return new WP_Error( 'invalid_issuer', 'Token issuer mismatch.' );
	}

	// Fetch JWKS and find matching key.
	$jwks = ec_fetch_jwks( $jwks_url );
	if ( is_wp_error( $jwks ) ) {
		return $jwks;
	}

	$kid = isset( $header['kid'] ) ? $header['kid'] : null;
	$key = ec_find_jwk( $jwks, $kid );
	if ( ! $key ) {
		return new WP_Error( 'key_not_found', 'Could not find matching public key.' );
	}

	$pem = ec_jwk_to_pem( $key );
	if ( ! $pem ) {
		return new WP_Error( 'key_conversion_failed', 'Could not convert JWK to PEM.' );
	}

	// Verify signature.
	$signature    = ec_base64url_decode( $signature_b64 );
	$signing_data = $header_b64 . '.' . $payload_b64;

	$public_key = openssl_pkey_get_public( $pem );
	if ( ! $public_key ) {
		return new WP_Error( 'invalid_public_key', 'Could not load public key.' );
	}

	$valid = openssl_verify( $signing_data, $signature, $public_key, OPENSSL_ALGO_SHA256 );

	if ( $valid !== 1 ) {
		return new WP_Error( 'invalid_signature', 'Token signature verification failed.' );
	}

	return $payload;
}

/**
 * Fetch and cache JWKS from URL.
 * Cache TTL is parsed from the provider's Cache-Control header.
 *
 * @param string $jwks_url JWKS endpoint.
 * @return array|WP_Error Array of keys or error.
 */
function ec_fetch_jwks( $jwks_url ) {
	$cache_key = 'ec_jwks_' . md5( $jwks_url );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get( $jwks_url, array(
		'timeout' => 10,
	) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'jwks_fetch_failed', 'Could not fetch JWKS: ' . $response->get_error_message() );
	}

	$status = wp_remote_retrieve_response_code( $response );
	if ( $status !== 200 ) {
		return new WP_Error( 'jwks_fetch_failed', 'JWKS endpoint returned status ' . $status );
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! $data || ! isset( $data['keys'] ) || ! is_array( $data['keys'] ) ) {
		return new WP_Error( 'invalid_jwks', 'Invalid JWKS response.' );
	}

	// Parse cache TTL from Cache-Control header.
	$cache_control = wp_remote_retrieve_header( $response, 'cache-control' );
	$ttl           = ec_parse_cache_control_max_age( $cache_control );

	// Default to 1 hour if no max-age found, minimum 5 minutes.
	$ttl = max( 300, $ttl ?: 3600 );

	set_transient( $cache_key, $data['keys'], $ttl );

	return $data['keys'];
}

/**
 * Parse max-age value from Cache-Control header.
 *
 * @param string $header Cache-Control header value.
 * @return int|null Max-age in seconds or null if not found.
 */
function ec_parse_cache_control_max_age( $header ) {
	if ( empty( $header ) ) {
		return null;
	}

	if ( preg_match( '/max-age\s*=\s*(\d+)/i', $header, $matches ) ) {
		return (int) $matches[1];
	}

	return null;
}

/**
 * Find JWK by key ID.
 *
 * @param array       $keys JWKS keys array.
 * @param string|null $kid  Key ID to find.
 * @return array|null JWK or null if not found.
 */
function ec_find_jwk( $keys, $kid ) {
	foreach ( $keys as $key ) {
		// Must be RSA key for RS256.
		if ( ! isset( $key['kty'] ) || $key['kty'] !== 'RSA' ) {
			continue;
		}

		// If kid provided, must match.
		if ( $kid !== null ) {
			if ( isset( $key['kid'] ) && $key['kid'] === $kid ) {
				return $key;
			}
		} else {
			// No kid in token, return first RSA key.
			return $key;
		}
	}

	return null;
}

/**
 * Convert JWK RSA key to PEM format.
 *
 * @param array $jwk JWK key data with 'n' and 'e' components.
 * @return string|false PEM string or false on failure.
 */
function ec_jwk_to_pem( $jwk ) {
	if ( ! isset( $jwk['n'] ) || ! isset( $jwk['e'] ) ) {
		return false;
	}

	$modulus  = ec_base64url_decode( $jwk['n'] );
	$exponent = ec_base64url_decode( $jwk['e'] );

	if ( ! $modulus || ! $exponent ) {
		return false;
	}

	// Build ASN.1 DER structure for RSA public key.
	$modulus_der  = ec_asn1_integer( $modulus );
	$exponent_der = ec_asn1_integer( $exponent );

	$rsa_public_key = ec_asn1_sequence( $modulus_der . $exponent_der );

	// Wrap in SubjectPublicKeyInfo structure.
	$algorithm_id = pack(
		'H*',
		'300d06092a864886f70d0101010500' // RSA OID + NULL
	);

	$bit_string        = chr( 0x03 ) . ec_asn1_length( strlen( $rsa_public_key ) + 1 ) . chr( 0x00 ) . $rsa_public_key;
	$subject_public_key_info = ec_asn1_sequence( $algorithm_id . $bit_string );

	$pem = "-----BEGIN PUBLIC KEY-----\n";
	$pem .= chunk_split( base64_encode( $subject_public_key_info ), 64, "\n" );
	$pem .= "-----END PUBLIC KEY-----\n";

	return $pem;
}

/**
 * Base64URL decode.
 *
 * @param string $data Base64URL encoded data.
 * @return string Decoded data.
 */
function ec_base64url_decode( $data ) {
	$remainder = strlen( $data ) % 4;
	if ( $remainder ) {
		$data .= str_repeat( '=', 4 - $remainder );
	}
	return base64_decode( strtr( $data, '-_', '+/' ) );
}

/**
 * Create ASN.1 SEQUENCE.
 *
 * @param string $data Data to wrap.
 * @return string ASN.1 encoded sequence.
 */
function ec_asn1_sequence( $data ) {
	return chr( 0x30 ) . ec_asn1_length( strlen( $data ) ) . $data;
}

/**
 * Create ASN.1 INTEGER.
 *
 * @param string $data Integer data (binary string).
 * @return string ASN.1 encoded integer.
 */
function ec_asn1_integer( $data ) {
	// Ensure positive integer (add leading zero if high bit set).
	if ( ord( $data[0] ) > 0x7f ) {
		$data = chr( 0x00 ) . $data;
	}
	return chr( 0x02 ) . ec_asn1_length( strlen( $data ) ) . $data;
}

/**
 * Create ASN.1 length bytes.
 *
 * @param int $length Length value.
 * @return string Length encoding.
 */
function ec_asn1_length( $length ) {
	if ( $length < 0x80 ) {
		return chr( $length );
	}

	$bytes = '';
	$temp  = $length;
	while ( $temp > 0 ) {
		$bytes = chr( $temp & 0xff ) . $bytes;
		$temp  = $temp >> 8;
	}

	return chr( 0x80 | strlen( $bytes ) ) . $bytes;
}
