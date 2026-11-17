<?php
/**
 * Test double for wp-native-auth's current-session helpers.
 *
 * Contract (wp-native-auth #120):
 * - wp_native_auth_get_current_session(): ?array { user_id, device_id,
 *   challenge_policy, challenge_verified_at, ... } for the bearer session.
 * - wp_native_auth_mark_current_session_verified( string $policy ): bool.
 *
 * Declared only when the real helpers are absent; tests check
 * EXTRACHILL_USERS_WP_NATIVE_SESSION_DOUBLE and skip otherwise.
 *
 * @package ExtraChill\Users\Tests
 */

if ( ! function_exists( 'wp_native_auth_get_current_session' ) && ! function_exists( 'wp_native_auth_mark_current_session_verified' ) ) {
	define( 'EXTRACHILL_USERS_WP_NATIVE_SESSION_DOUBLE', true );

	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting
	class Extrachill_Users_WP_Native_Session_Double {
		/** @var array<string,mixed>|null Current bearer session. */
		public static $session = null;

		/** @var list<string> Policies passed to mark_current_session_verified(). */
		public static $marked = array();

		public static function reset(): void {
			self::$session = null;
			self::$marked  = array();
		}
	}

	function wp_native_auth_get_current_session(): ?array {
		return Extrachill_Users_WP_Native_Session_Double::$session;
	}

	function wp_native_auth_mark_current_session_verified( string $policy ): bool {
		Extrachill_Users_WP_Native_Session_Double::$marked[] = $policy;
		return null !== Extrachill_Users_WP_Native_Session_Double::$session;
	}
	// phpcs:enable
}
