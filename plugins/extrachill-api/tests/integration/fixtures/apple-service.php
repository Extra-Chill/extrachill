<?php
/**
 * Users service doubles, loaded after missing-dependency auth assertions.
 *
 * Network and Users helpers may already be loaded as validation dependencies,
 * so each double is only declared when the real function is absent.
 *
 * @package ExtraChill\API\Tests
 */

if ( ! function_exists( 'ec_is_apple_oauth_configured' ) ) {
	/** Whether the provider is configured for this test. */
	function ec_is_apple_oauth_configured() {
		return $GLOBALS['apple_configured'] ?? true;
	}
}

if ( ! function_exists( 'extrachill_users_is_uuid_v4' ) ) {
	/** Validate the UUID v4 contract supplied by Users. */
	function extrachill_users_is_uuid_v4( $device_id ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $device_id );
	}
}

if ( ! function_exists( 'ec_apple_login_with_tokens' ) ) {
	/** Capture delegation and return a Users-owned response or error. */
	function ec_apple_login_with_tokens( $identity_token, $device_id, $options ) {
		$GLOBALS['apple_service_call'] = array( $identity_token, $device_id, $options );
		return $GLOBALS['apple_service_result'] ?? array( 'access_token' => 'service-token' );
	}
}
