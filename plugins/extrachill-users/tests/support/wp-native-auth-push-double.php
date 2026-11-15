<?php
/**
 * Test double for the optional wp-native-auth push primitive.
 *
 * Loaded lazily (require_once) by tests that need the primitive present, so a
 * test that runs earlier can still observe the "wp-native-auth absent" state.
 * Declared only when function_exists() allows; when the real wp-native-auth is
 * active, tests use the real function and record via its own surface instead.
 *
 * Contract: wp_native_auth_send_push( int $user_id, array $message ): array.
 *
 * @package ExtraChill\Users
 */

if ( ! function_exists( 'wp_native_auth_send_push' ) ) {
	/**
	 * Record a push instead of delivering it.
	 *
	 * @param int   $user_id Recipient user ID.
	 * @param array $message Push message.
	 * @return array
	 */
	function wp_native_auth_send_push( int $user_id, array $message ): array {
		if ( ! isset( $GLOBALS['ec_users_push_test_outbox'] ) || ! is_array( $GLOBALS['ec_users_push_test_outbox'] ) ) {
			$GLOBALS['ec_users_push_test_outbox'] = array();
		}

		$GLOBALS['ec_users_push_test_outbox'][] = array(
			'user_id' => $user_id,
			'message' => $message,
		);

		return array(
			'queued' => true,
		);
	}

	define( 'EC_USERS_TEST_PUSH_DOUBLE', true );
}
