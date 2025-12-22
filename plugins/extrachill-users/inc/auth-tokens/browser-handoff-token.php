<?php
/**
 * Browser handoff token service.
 *
 * Generates and validates one-time tokens used to bootstrap a WordPress
 * cookie session in a real browser after app authentication.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a single-use browser handoff token.
 *
 * @param int    $user_id      User ID.
 * @param string $redirect_url Destination URL after cookies are set.
 * @return string Token.
 */
function extrachill_users_create_browser_handoff_token( int $user_id, string $redirect_url ): string {
	$token = wp_generate_password( 64, false, false );
	$key   = 'ec_browser_handoff_' . $token;

	set_transient(
		$key,
		array(
			'user_id'       => $user_id,
			'redirect_url'  => $redirect_url,
			'created_at_ts' => time(),
		),
		60
	);

	return $token;
}

/**
 * Consume a browser handoff token.
 *
 * @param string $token Token string.
 * @return array|WP_Error Payload array.
 */
function extrachill_users_consume_browser_handoff_token( string $token ) {
	$token = trim( $token );
	if ( '' === $token ) {
		return new WP_Error( 'invalid_handoff_token', 'Invalid handoff token.', array( 'status' => 400 ) );
	}

	$key     = 'ec_browser_handoff_' . $token;
	$payload = get_transient( $key );
	delete_transient( $key );

	if ( ! is_array( $payload ) || empty( $payload['user_id'] ) || empty( $payload['redirect_url'] ) ) {
		return new WP_Error( 'invalid_handoff_token', 'Invalid or expired handoff token.', array( 'status' => 400 ) );
	}

	return $payload;
}
