<?php
/**
 * Sign in with Apple token revocation.
 *
 * Apple requires apps offering Sign in with Apple to revoke the user's
 * tokens when the account is deleted (https://appleid.apple.com/auth/revoke).
 * Everything here is optional: the Apple sign-in integration may not be
 * installed, and revocation never blocks account deletion.
 *
 * The native sign-in flow verifies identity tokens only and stores no Apple
 * refresh token, so revocation runs only when a refresh token has been
 * stored under EXTRACHILL_USERS_APPLE_REFRESH_TOKEN_META_KEY.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_APPLE_USER_ID_META_KEY       = 'apple_user_id';
const EXTRACHILL_USERS_APPLE_REFRESH_TOKEN_META_KEY = 'apple_refresh_token';
const EXTRACHILL_USERS_APPLE_REVOKE_URL             = 'https://appleid.apple.com/auth/revoke';
const EXTRACHILL_USERS_APPLE_AUDIENCE               = 'https://appleid.apple.com';

/**
 * Whether Apple server credentials are configured for revocation.
 *
 * @return bool
 */
function extrachill_users_apple_revocation_configured(): bool {
	$configured = function_exists( 'ec_is_apple_server_credentials_configured' ) && ec_is_apple_server_credentials_configured();

	/**
	 * Filters whether Apple token revocation is configured.
	 *
	 * @param bool $configured Whether server credentials are configured.
	 */
	return (bool) apply_filters( 'extrachill_users_apple_revocation_configured', $configured );
}

/**
 * Revoke a user's Apple tokens before account deletion.
 *
 * @param int $user_id User ID.
 * @return string Outcome: not_linked, not_configured, no_refresh_token, revoked, or failed.
 */
function extrachill_users_revoke_apple_tokens_for_user( int $user_id ): string {
	$apple_user_id = (string) get_user_meta( $user_id, EXTRACHILL_USERS_APPLE_USER_ID_META_KEY, true );
	if ( '' === $apple_user_id ) {
		return 'not_linked';
	}

	if ( ! extrachill_users_apple_revocation_configured() ) {
		extrachill_users_log_apple_revocation( $user_id, 'Apple server credentials are not configured; skipping revocation.' );
		return 'not_configured';
	}

	$refresh_token = (string) get_user_meta( $user_id, EXTRACHILL_USERS_APPLE_REFRESH_TOKEN_META_KEY, true );
	if ( '' === $refresh_token ) {
		extrachill_users_log_apple_revocation( $user_id, 'No stored Apple refresh token (native identity-token sign-in); skipping revocation.' );
		return 'no_refresh_token';
	}

	$client_id     = (string) get_site_option( 'extrachill_apple_client_id', '' );
	$client_secret = extrachill_users_apple_client_secret( $client_id );
	if ( is_wp_error( $client_secret ) ) {
		extrachill_users_log_apple_revocation( $user_id, $client_secret->get_error_message() );
		return 'failed';
	}

	$response = wp_remote_post(
		EXTRACHILL_USERS_APPLE_REVOKE_URL,
		array(
			'timeout' => 10,
			'body'    => array(
				'client_id'       => $client_id,
				'client_secret'   => $client_secret,
				'token'           => $refresh_token,
				'token_type_hint' => 'refresh_token',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		extrachill_users_log_apple_revocation( $user_id, 'Request failed: ' . $response->get_error_message() );
		return 'failed';
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		extrachill_users_log_apple_revocation( $user_id, sprintf( 'Apple returned HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) ) );
		return 'failed';
	}

	return 'revoked';
}

/**
 * Build the ES256 client-secret JWT Apple expects.
 *
 * @param string $client_id Services/bundle identifier used as the JWT subject.
 * @return string|WP_Error Signed JWT.
 */
function extrachill_users_apple_client_secret( string $client_id ) {
	$team_id     = (string) get_site_option( 'extrachill_apple_team_id', '' );
	$key_id      = (string) get_site_option( 'extrachill_apple_key_id', '' );
	$private_key = str_replace( '\n', "\n", trim( (string) get_site_option( 'extrachill_apple_private_key', '' ) ) );

	if ( '' === $client_id || '' === $team_id || '' === $key_id || '' === $private_key ) {
		return new WP_Error( 'apple_credentials_missing', 'Apple client, team, key ID, or private key is missing.' );
	}

	$now     = time();
	$header  = array(
		'alg' => 'ES256',
		'kid' => $key_id,
	);
	$payload = array(
		'iss' => $team_id,
		'iat' => $now,
		'exp' => $now + 5 * MINUTE_IN_SECONDS,
		'aud' => EXTRACHILL_USERS_APPLE_AUDIENCE,
		'sub' => $client_id,
	);

	$signing_input = extrachill_users_base64url_encode( (string) wp_json_encode( $header ) ) . '.' . extrachill_users_base64url_encode( (string) wp_json_encode( $payload ) );

	$key = openssl_pkey_get_private( $private_key );
	if ( false === $key ) {
		return new WP_Error( 'apple_private_key_invalid', 'Apple private key could not be loaded.' );
	}

	$der_signature = '';
	if ( ! openssl_sign( $signing_input, $der_signature, $key, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'apple_signing_failed', 'Apple client secret could not be signed.' );
	}

	$signature = extrachill_users_ecdsa_der_to_raw( $der_signature );
	if ( is_wp_error( $signature ) ) {
		return $signature;
	}

	return $signing_input . '.' . extrachill_users_base64url_encode( $signature );
}

/**
 * Convert an OpenSSL DER ECDSA signature to the raw R||S form JWS requires.
 *
 * @param string $der DER-encoded signature.
 * @return string|WP_Error 64-byte raw signature for P-256.
 */
function extrachill_users_ecdsa_der_to_raw( string $der ) {
	$invalid = new WP_Error( 'apple_signature_invalid', 'Unexpected ECDSA signature encoding.' );
	$offset  = 0;
	$length  = strlen( $der );

	if ( $length < 8 || "\x30" !== $der[ $offset++ ] ) {
		return $invalid;
	}

	// Skip the sequence length (short or long form).
	$seq_len = ord( $der[ $offset++ ] );
	if ( $seq_len & 0x80 ) {
		$offset += $seq_len & 0x7f;
	}

	$parts = array();
	for ( $i = 0; $i < 2; $i++ ) {
		if ( $offset + 2 > $length || "\x02" !== $der[ $offset++ ] ) {
			return $invalid;
		}
		$int_len = ord( $der[ $offset++ ] );
		if ( $offset + $int_len > $length ) {
			return $invalid;
		}
		$int = ltrim( substr( $der, $offset, $int_len ), "\x00" );
		if ( strlen( $int ) > 32 ) {
			return $invalid;
		}
		$parts[] = str_pad( $int, 32, "\x00", STR_PAD_LEFT );
		$offset += $int_len;
	}

	return $parts[0] . $parts[1];
}

/**
 * Base64url-encode without padding.
 *
 * @param string $data Raw bytes.
 * @return string
 */
function extrachill_users_base64url_encode( string $data ): string {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding.
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Log an Apple revocation outcome. Never fatal.
 *
 * @param int    $user_id User ID.
 * @param string $message Message.
 */
function extrachill_users_log_apple_revocation( int $user_id, string $message ): void {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational visibility; deletion proceeds.
	error_log( sprintf( 'Account deletion: Apple token revocation for user %d: %s', $user_id, $message ) );

	/**
	 * Fires when Apple token revocation is skipped or fails during account deletion.
	 *
	 * @param int    $user_id User ID.
	 * @param string $message Outcome message.
	 */
	do_action( 'extrachill_users_apple_revocation_logged', $user_id, $message );
}
