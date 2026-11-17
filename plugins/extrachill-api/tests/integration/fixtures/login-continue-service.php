<?php
/**
 * Users login service doubles for the challenged-login transport.
 *
 * Loaded after the missing-dependency assertions. Each double is only declared
 * when the real function is absent.
 *
 * @package ExtraChill\API\Tests
 */

if ( ! function_exists( 'extrachill_users_is_uuid_v4' ) ) {
	/** Validate the UUID v4 contract supplied by Users. */
	function extrachill_users_is_uuid_v4( $device_id ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $device_id );
	}
}

if ( ! function_exists( 'extrachill_users_continue_login_with_tokens' ) ) {
	/** Capture delegation and return a Users-owned response or error. */
	function extrachill_users_continue_login_with_tokens( $continuation_token, $device_id, $challenge_response ) {
		$GLOBALS['login_continue_service_call'] = array( $continuation_token, $device_id, $challenge_response );
		return $GLOBALS['login_continue_service_result'] ?? array( 'access_token' => 'service-token' );
	}
}

if ( ! function_exists( 'extrachill_users_login_with_tokens' ) ) {
	/** Return a Users-owned login response or error. */
	function extrachill_users_login_with_tokens( $identifier, $password, $device_id, $options ) {
		unset( $identifier, $password, $device_id, $options );
		return $GLOBALS['login_service_result'] ?? array( 'access_token' => 'service-token' );
	}
}
