<?php
/**
 * Test double for wp-native-auth's device sessions and refresh rotation.
 *
 * Contract (wp-native-auth 0.6.0):
 * - wp_native_auth_get_session( int $user_id, string $device_id ): ?array
 *   { user_id, device_id, device_name, oauth_client_id, created_at,
 *     challenge_policy, challenge_verified_at } for an active session.
 * - wp_native_auth_refresh_tokens( string $refresh_token, string $device_id )
 *   validates the token, runs `wp_native_auth_pre_login` with reason
 *   `refresh` BEFORE rotating, then rotates and returns the new pair.
 *
 * Declared only when the real functions are absent; tests check
 * EXTRACHILL_USERS_WP_NATIVE_REFRESH_DOUBLE and skip otherwise.
 *
 * @package ExtraChill\Users\Tests
 */

if ( ! function_exists( 'wp_native_auth_get_session' ) && ! function_exists( 'wp_native_auth_refresh_tokens' ) ) {
	define( 'EXTRACHILL_USERS_WP_NATIVE_REFRESH_DOUBLE', true );

	// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Squiz.Commenting -- the double's state class and the helpers it backs belong together.
	class Extrachill_Users_WP_Native_Refresh_Double {
		/** @var array<string,array<string,mixed>> Active sessions by device ID, each with its current refresh_token. */
		public static $sessions = array();

		/** @var int Successful rotations. */
		public static $rotations = 0;

		public static function reset(): void {
			self::$sessions  = array();
			self::$rotations = 0;
		}

		/**
		 * Add an active session and return its refresh token.
		 */
		public static function add( int $user_id, string $device_id, ?string $policy, ?int $verified_at, ?string $oauth_client_id = null ): string {
			$token                        = 'rt-' . wp_generate_password( 20, false );
			self::$sessions[ $device_id ] = array(
				'user_id'               => $user_id,
				'device_id'             => $device_id,
				'device_name'           => 'Test device',
				'oauth_client_id'       => $oauth_client_id,
				'created_at'            => time() - 3600,
				'challenge_policy'      => $policy,
				'challenge_verified_at' => $verified_at,
				'refresh_token'         => $token,
			);

			return $token;
		}
	}

	function wp_native_auth_get_session( int $user_id, string $device_id ): ?array {
		$session = Extrachill_Users_WP_Native_Refresh_Double::$sessions[ $device_id ] ?? null;
		if ( null === $session || $session['user_id'] !== $user_id ) {
			return null;
		}

		unset( $session['refresh_token'] );
		return $session;
	}

	function wp_native_auth_refresh_tokens( string $refresh_token, string $device_id ) {
		$session = Extrachill_Users_WP_Native_Refresh_Double::$sessions[ $device_id ] ?? null;
		if ( null === $session || ! hash_equals( (string) $session['refresh_token'], $refresh_token ) ) {
			return new WP_Error( 'invalid_refresh_token', 'Invalid refresh token.', array( 'status' => 401 ) );
		}

		$user    = get_user_by( 'id', (int) $session['user_id'] );
		$blocked = apply_filters(
			'wp_native_auth_pre_login',
			null,
			$user,
			array(
				'device_id' => $device_id,
				'reason'    => 'refresh',
			)
		);
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}

		$new_token = 'rt-' . wp_generate_password( 20, false );
		Extrachill_Users_WP_Native_Refresh_Double::$sessions[ $device_id ]['refresh_token'] = $new_token;
		++Extrachill_Users_WP_Native_Refresh_Double::$rotations;

		return array(
			'access_token'       => 'at-' . wp_generate_password( 20, false ),
			'access_expires_at'  => gmdate( 'c', time() + 900 ),
			'refresh_token'      => $new_token,
			'refresh_expires_at' => gmdate( 'c', time() + 30 * DAY_IN_SECONDS ),
			'user'               => array( 'id' => (int) $session['user_id'] ),
		);
	}
	// phpcs:enable
}
