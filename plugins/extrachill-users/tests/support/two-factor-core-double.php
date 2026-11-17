<?php
/**
 * Test double for the Two Factor plugin, including its non-rendering login API.
 *
 * Mirrors Two_Factor_Core from WordPress/two-factor#1045:
 * verify_authentication_code( WP_User, provider_key, { code, resend } ) and
 * get_login_challenge( WP_User ). Declared only when the real plugin is absent,
 * because a class can be declared once per process. Tests that need it check
 * EXTRACHILL_USERS_TWO_FACTOR_DOUBLE and skip against the real plugin.
 *
 * Off by default (no user uses 2FA), so tests that run later are unaffected.
 *
 * @package ExtraChill\Users\Tests
 */

if ( ! class_exists( 'Two_Factor_Core' ) ) {
	define( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE', true );

	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting
	class Two_Factor_Core {
		const USER_RATE_LIMIT_KEY            = '_two_factor_last_login_failure';
		const USER_FAILED_LOGIN_ATTEMPTS_KEY = '_two_factor_failed_login_attempts';

		/** @var bool Whether every user uses 2FA. */
		public static $using = false;

		/** @var array{provider:string, providers:list<array{key:string,label:string}>} Set by reset(). */
		public static $challenge = array();

		/** @var true|WP_Error|array<string,string>|null Next verify result; null derives one from the code. */
		public static $verify_result = null;

		/** @var list<array{user_id:int, provider:string, args:array<string,mixed>}> */
		public static $verify_calls = array();

		/** @var int|false 2FA time of the current cookie session. */
		public static $session_time = false;

		public static function reset(): void {
			self::$using         = false;
			self::$verify_result = null;
			self::$verify_calls  = array();
			self::$session_time  = false;
			self::$challenge     = self::default_challenge();
		}

		private static function default_challenge(): array {
			return array(
				'provider'  => 'Two_Factor_Totp',
				'providers' => array(
					array(
						'key'   => 'Two_Factor_Totp',
						'label' => 'Authenticator app',
					),
					array(
						'key'   => 'Two_Factor_Email',
						'label' => 'Email',
					),
				),
			);
		}

		public static function is_user_using_two_factor( $user_id ) {
			unset( $user_id );
			return self::$using;
		}

		public static function create_login_nonce( $user_id ) {
			unset( $user_id );
			return array(
				'key'        => 'test-2fa-nonce',
				'expiration' => time() + 600,
			);
		}

		public static function get_login_challenge( $user ) {
			unset( $user );
			if ( array() === self::$challenge ) {
				self::$challenge = self::default_challenge();
			}
			return self::$challenge;
		}

		public static function verify_authentication_code( $user, $provider_key, $args = array() ) {
			self::$verify_calls[] = array(
				'user_id'  => (int) $user->ID,
				'provider' => (string) $provider_key,
				'args'     => (array) $args,
			);

			if ( null !== self::$verify_result ) {
				return self::$verify_result;
			}

			if ( ! empty( $args['resend'] ) ) {
				return array( 'status' => 'code_sent' );
			}

			return '123456' === ( $args['code'] ?? '' )
				? true
				: new WP_Error( 'two_factor_invalid', 'Invalid code.', array( 'status' => 401 ) );
		}

		public static function is_current_user_session_two_factor() {
			return self::$session_time;
		}

		public static function wp_login( $user_login, $user ) {
			unset( $user_login, $user );
		}

		/** Present in WordPress/two-factor#935; routes are registered by the test that needs them. */
		public static function register_rest_routes() {}

		/** Present in WordPress/two-factor#1045. */
		public static function register_login_rest_routes() {}
	}
	// phpcs:enable
}
