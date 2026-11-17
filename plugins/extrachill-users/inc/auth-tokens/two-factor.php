<?php
/**
 * Two Factor glue for the wp-native-auth challenge policy loop.
 *
 * This is the only place Extra Chill talks to the Two Factor plugin's login
 * API. Every login entry point (password, Google, Apple, the web login block,
 * and the OAuth authorize login that lands on that block) asks wp-native-auth
 * to run its challenge policies before a session is minted. The `two-factor`
 * policy registered here challenges members who use 2FA, so the code step
 * happens in the app or in the login block, never on wp-login.php.
 *
 * The non-rendering Two Factor API (verify_authentication_code() and
 * get_login_challenge()) has not shipped in a Two Factor release yet. Until it
 * does, the policy is not registered and the password login keeps handing 2FA
 * users to the validate_2fa page (extrachill_users_maybe_handle_two_factor()).
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/** Challenge policy ID registered with wp-native-auth. */
const EXTRACHILL_USERS_TWO_FACTOR_POLICY = 'two-factor';

/**
 * Whether the installed Two Factor plugin exposes the non-rendering login API.
 */
function extrachill_users_two_factor_api_available(): bool {
	return class_exists( 'Two_Factor_Core' )
		&& is_callable( array( 'Two_Factor_Core', 'is_user_using_two_factor' ) )
		&& is_callable( array( 'Two_Factor_Core', 'verify_authentication_code' ) )
		&& is_callable( array( 'Two_Factor_Core', 'get_login_challenge' ) );
}

/**
 * Whether wp-native-auth can run its challenge policy loop for any authenticator.
 */
function extrachill_users_challenge_loop_available(): bool {
	return function_exists( 'wp_native_auth_register_challenge_policy' )
		&& function_exists( 'wp_native_auth_challenge_login' )
		&& function_exists( 'wp_native_auth_continue_login' );
}

/**
 * Whether 2FA logins complete in the app and the login block.
 *
 * When false, a Two Factor user signing in with a password is handed to the
 * validate_2fa page as before.
 */
function extrachill_users_two_factor_policy_active(): bool {
	return extrachill_users_two_factor_api_available() && extrachill_users_challenge_loop_available();
}

/**
 * Register the `two-factor` challenge policy.
 */
function extrachill_users_register_two_factor_policy(): void {
	if ( ! extrachill_users_two_factor_policy_active() ) {
		return;
	}

	wp_native_auth_register_challenge_policy(
		EXTRACHILL_USERS_TWO_FACTOR_POLICY,
		'extrachill_users_two_factor_challenge',
		'extrachill_users_two_factor_verify'
	);
}
add_action( 'wp_native_auth_register_challenge_policies', 'extrachill_users_register_two_factor_policy' );

/**
 * Build the public challenge descriptor for a user.
 *
 * @param WP_User $user User whose primary credential was verified.
 * @return array{type:string, provider:string, providers:list<array{key:string,label:string,resend:bool}>, status?:string}|null Null when the user has no usable provider.
 */
function extrachill_users_two_factor_descriptor( WP_User $user ): ?array {
	$challenge = Two_Factor_Core::get_login_challenge( $user );
	$providers = array();

	foreach ( (array) ( $challenge['providers'] ?? array() ) as $provider ) {
		if ( ! is_array( $provider ) || empty( $provider['key'] ) ) {
			continue;
		}

		$providers[] = array(
			'key'   => (string) $provider['key'],
			'label' => isset( $provider['label'] ) ? (string) $provider['label'] : (string) $provider['key'],
		);
	}

	if ( empty( $providers ) ) {
		return null;
	}

	$primary = isset( $challenge['provider'] ) ? (string) $challenge['provider'] : '';
	if ( '' === $primary || ! in_array( $primary, wp_list_pluck( $providers, 'key' ), true ) ) {
		$primary = $providers[0]['key'];
	}

	foreach ( $providers as $index => $provider ) {
		$providers[ $index ]['resend'] = extrachill_users_two_factor_provider_sends_codes( $provider['key'] );
	}

	return array(
		'type'      => 'two_factor',
		'provider'  => $primary,
		'providers' => $providers,
	);
}

/**
 * Challenge callback: ask Two Factor users for a code.
 *
 * @param WP_User             $user    User whose primary credential was verified.
 * @param array<string,mixed> $context Login context from wp-native-auth.
 * @return array<string,mixed>|null
 */
function extrachill_users_two_factor_challenge( WP_User $user, array $context ): ?array {
	unset( $context );

	// The primary credential is verified; the scoped API-login allowance used
	// to reach this point must not outlive it.
	extrachill_users_two_factor_allow_api_primary_login( false );

	if ( ! extrachill_users_two_factor_api_available() || ! Two_Factor_Core::is_user_using_two_factor( $user->ID ) ) {
		return null;
	}

	$descriptor = extrachill_users_two_factor_descriptor( $user );

	// Like Two Factor's own form, prompting for an emailed code sends one.
	// A rate-limited send still prompts: the member may already have a code.
	if ( null !== $descriptor && extrachill_users_two_factor_provider_sends_codes( $descriptor['provider'] ) ) {
		$sent = Two_Factor_Core::verify_authentication_code( $user, $descriptor['provider'], array( 'resend' => true ) );
		if ( is_array( $sent ) ) {
			$descriptor['status'] = 'code_sent';
		}
	}

	return $descriptor;
}

/**
 * Whether a provider delivers codes on request (and so supports resend).
 *
 * @param string $provider_key Two Factor provider key.
 */
function extrachill_users_two_factor_provider_sends_codes( string $provider_key ): bool {
	return 'Two_Factor_Email' === $provider_key;
}

/**
 * Verify callback: check a code, or re-send one.
 *
 * `$response` is `{ provider, code }` or `{ provider, resend: true }`. An
 * omitted provider means the user's primary provider.
 *
 * @param WP_User             $user     User bound to the continuation.
 * @param array<string,mixed> $response Untrusted challenge response.
 * @param array<string,mixed> $context  Continuation context from wp-native-auth.
 * @return true|array<string,mixed>|WP_Error True to complete the login, a re-challenge after a resend, or an error.
 */
function extrachill_users_two_factor_verify( WP_User $user, array $response, array $context ) {
	unset( $context );

	if ( ! extrachill_users_two_factor_api_available() ) {
		return new WP_Error(
			'challenge_policy_unavailable',
			__( 'Two-factor authentication is unavailable. Please try again later.', 'extrachill-users' ),
			array( 'status' => 503 )
		);
	}

	$descriptor = extrachill_users_two_factor_descriptor( $user );
	if ( null === $descriptor ) {
		return new WP_Error(
			'two_factor_provider_not_enabled',
			__( 'Two-factor authentication is not set up for this account.', 'extrachill-users' ),
			array( 'status' => 400 )
		);
	}

	// Provider keys are class names (Two_Factor_Totp), so they are matched
	// against the user's own providers rather than sanitized.
	$provider = isset( $response['provider'] ) && is_string( $response['provider'] ) ? trim( $response['provider'] ) : '';
	if ( '' === $provider ) {
		$provider = $descriptor['provider'];
	} elseif ( ! in_array( $provider, wp_list_pluck( $descriptor['providers'], 'key' ), true ) ) {
		return new WP_Error(
			'two_factor_provider_not_enabled',
			__( 'The requested two-factor provider is not enabled for this account.', 'extrachill-users' ),
			array( 'status' => 400 )
		);
	}

	$code   = isset( $response['code'] ) && is_scalar( $response['code'] ) ? trim( (string) $response['code'] ) : '';
	$resend = ! empty( $response['resend'] ) && 'false' !== $response['resend'];

	$result = Two_Factor_Core::verify_authentication_code(
		$user,
		$provider,
		array(
			'code'   => $code,
			'resend' => $resend,
		)
	);

	if ( is_wp_error( $result ) ) {
		// The password was reset, so this pending login must never complete.
		if ( 'two_factor_password_reset_required' === $result->get_error_code() && function_exists( 'wp_native_auth_delete_user_continuations' ) ) {
			wp_native_auth_delete_user_continuations( (int) $user->ID );
		}

		return $result;
	}

	if ( is_array( $result ) ) {
		$descriptor['provider'] = $provider;
		$descriptor['status']   = isset( $result['status'] ) ? (string) $result['status'] : 'code_sent';

		return $descriptor;
	}

	if ( true !== $result ) {
		return new WP_Error(
			'two_factor_invalid',
			__( 'Invalid verification code.', 'extrachill-users' ),
			array( 'status' => 401 )
		);
	}

	extrachill_users_two_factor_prepare_completion( $user, $provider );

	return true;
}

/**
 * Let wp-native-auth finish a login whose second factor was just verified.
 *
 * Mirrors what Two Factor does after its own form succeeds: clear the failure
 * counters and stamp the cookie session with the provider and time, so the
 * browser session counts as 2FA-verified for revalidation grace. Two Factor's
 * wp_login handler is detached for the rest of this request because it would
 * otherwise render its form and exit when the login sets a cookie.
 *
 * @param WP_User $user         Verified user.
 * @param string  $provider_key Provider that verified the code.
 */
function extrachill_users_two_factor_prepare_completion( WP_User $user, string $provider_key ): void {
	remove_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ), PHP_INT_MAX );
	remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );

	foreach ( array( 'Two_Factor_Core::USER_RATE_LIMIT_KEY', 'Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY' ) as $constant ) {
		if ( defined( $constant ) ) {
			delete_user_meta( (int) $user->ID, (string) constant( $constant ) );
		}
	}

	$user_id = (int) $user->ID;
	add_filter(
		'attach_session_information',
		static function ( $session, $session_user_id ) use ( $user_id, $provider_key ) {
			if ( (int) $session_user_id === $user_id && is_array( $session ) ) {
				$session['two-factor-login']    = time();
				$session['two-factor-provider'] = $provider_key;
			}

			return $session;
		},
		10,
		2
	);
}

/**
 * Toggle the scoped allowance that lets a Two Factor user's password reach the policy loop.
 *
 * Two Factor rejects REST password logins for its users outright. With the
 * policy active the password check is only the first step, so the login
 * services raise this flag around wp_authenticate() and the challenge
 * callback lowers it again.
 *
 * @param bool|null $allow True or false to set, null to read.
 */
function extrachill_users_two_factor_allow_api_primary_login( ?bool $allow = null ): bool {
	static $allowed = false;

	if ( null !== $allow ) {
		$allowed = $allow && extrachill_users_two_factor_policy_active();
	}

	return $allowed;
}

/**
 * Filter `two_factor_user_api_login_enable` while the scoped allowance is raised.
 *
 * @param bool $enabled Whether Two Factor permits API login for the user.
 */
function extrachill_users_two_factor_api_login_enable( $enabled ): bool {
	return (bool) $enabled || extrachill_users_two_factor_allow_api_primary_login();
}
add_filter( 'two_factor_user_api_login_enable', 'extrachill_users_two_factor_api_login_enable' );

/**
 * Point Two Factor's "settings changed" email at the member Settings page.
 *
 * Two Factor (0.18.0+) tells the member to review their security settings at
 * wp-admin/profile.php. Members without wp-admin access are bounced from
 * there, so their copy links to Settings instead.
 *
 * @param array<string,mixed> $email Email args for wp_mail(): to, subject, message, headers.
 * @param WP_User|null        $user  Member whose 2FA settings changed.
 * @return array<string,mixed>
 */
function extrachill_users_two_factor_settings_changed_email( $email, $user = null ) {
	if ( ! is_array( $email ) || ! isset( $email['message'] ) || ! is_string( $email['message'] ) ) {
		return $email;
	}
	if ( ! $user instanceof WP_User || ec_users_can_access_wp_admin( $user ) ) {
		return $email;
	}

	$message = preg_replace( '#https?://\S*/wp-admin/(?:network/|user/)?profile\.php(?:[?\#][^\s]*)?#i', extrachill_users_settings_url(), $email['message'] );
	if ( is_string( $message ) ) {
		$email['message'] = $message;
	}

	return $email;
}
add_filter( 'two_factor_settings_changed_email', 'extrachill_users_two_factor_settings_changed_email', 10, 2 );

/** Lower the allowance when the primary credential fails. */
function extrachill_users_two_factor_reset_api_primary_login(): void {
	extrachill_users_two_factor_allow_api_primary_login( false );
}
add_action( 'wp_login_failed', 'extrachill_users_two_factor_reset_api_primary_login' );

/**
 * Authenticate a password through the full authenticate stack.
 *
 * @param string $identifier Username or email.
 * @param string $password   Password.
 * @return WP_User|WP_Error
 */
function extrachill_users_authenticate_primary( string $identifier, string $password ) {
	extrachill_users_two_factor_allow_api_primary_login( true );
	$user = wp_authenticate( $identifier, $password );
	extrachill_users_two_factor_allow_api_primary_login( false );

	return $user;
}

/**
 * Report a bearer session's 2FA time to Two Factor.
 *
 * Bearer requests have no cookie session, so Two Factor asks this filter when
 * the session last passed 2FA. Sessions verified by another policy, or never
 * verified, report 0 (may revalidate). Cookie requests keep Two Factor's value.
 *
 * @param int|false|null $time Two Factor's value for the cookie session.
 * @param WP_User|mixed  $user Current user.
 * @return int|false|null
 */
function extrachill_users_two_factor_session_time( $time, $user ) {
	if ( ! function_exists( 'wp_native_auth_get_current_session' ) || ! $user instanceof WP_User ) {
		return $time;
	}

	$session = wp_native_auth_get_current_session();
	if ( null === $session || (int) $session['user_id'] !== (int) $user->ID ) {
		return $time;
	}

	if ( EXTRACHILL_USERS_TWO_FACTOR_POLICY !== $session['challenge_policy'] || null === $session['challenge_verified_at'] ) {
		return 0;
	}

	return (int) $session['challenge_verified_at'];
}
add_filter( 'two_factor_current_session_two_factor_time', 'extrachill_users_two_factor_session_time', 10, 2 );

/**
 * Record a Two Factor revalidation on the current bearer session.
 *
 * @param WP_User|mixed $user     Revalidated user.
 * @param mixed         $provider Provider object or key (unused).
 */
function extrachill_users_two_factor_revalidated( $user, $provider = null ): void {
	unset( $provider );

	if ( ! function_exists( 'wp_native_auth_get_current_session' ) || ! function_exists( 'wp_native_auth_mark_current_session_verified' ) || ! $user instanceof WP_User ) {
		return;
	}

	$session = wp_native_auth_get_current_session();
	if ( null === $session || (int) $session['user_id'] !== (int) $user->ID ) {
		return;
	}

	wp_native_auth_mark_current_session_verified( EXTRACHILL_USERS_TWO_FACTOR_POLICY );
}
add_action( 'two_factor_user_revalidated', 'extrachill_users_two_factor_revalidated', 10, 2 );

/**
 * Run wp-native-auth's challenge policies for a verified primary credential.
 *
 * `$flow` is stored with the pending login and handed back when the login is
 * continued, so the continued response matches the entry point's own shape
 * and destination.
 *
 * @param WP_User             $user          User whose primary credential was verified.
 * @param string              $device_id     Device UUID.
 * @param array<string,mixed> $login_options device_name, remember, set_cookie.
 * @param array<string,mixed> $flow          Extra Chill flow data: flow (password|oauth) plus its redirect inputs.
 * @return array<string,mixed>|WP_Error|null Challenge response, error, or null to complete the login now.
 */
function extrachill_users_maybe_challenge_login( WP_User $user, string $device_id, array $login_options, array $flow ) {
	if ( ! function_exists( 'wp_native_auth_challenge_login' ) ) {
		return null;
	}

	return wp_native_auth_challenge_login(
		$user,
		$device_id,
		array(
			'device_name' => isset( $login_options['device_name'] ) ? (string) $login_options['device_name'] : '',
			'remember'    => ! empty( $login_options['remember'] ),
			'set_cookie'  => ! empty( $login_options['set_cookie'] ),
		),
		array( 'extrachill' => $flow )
	);
}

/**
 * Capture the Extra Chill flow of a continued login just before it completes.
 *
 * @param null|WP_Error       $blocked Null to continue.
 * @param WP_User|mixed       $user    Bound user.
 * @param array<string,mixed> $context Pending login context.
 * @return null|WP_Error
 */
function extrachill_users_capture_continued_login( $blocked, $user, $context ) {
	unset( $user );

	if ( ! is_wp_error( $blocked ) && is_array( $context ) ) {
		extrachill_users_continued_login_flow( isset( $context['extrachill'] ) && is_array( $context['extrachill'] ) ? $context['extrachill'] : array() );
	}

	return $blocked;
}
add_filter( 'wp_native_auth_revalidate_login', 'extrachill_users_capture_continued_login', PHP_INT_MAX, 3 );

/**
 * Store or read the flow of the login being continued in this request.
 *
 * @param array<string,mixed>|null $flow Flow to store, or null to read.
 * @return array<string,mixed>
 */
function extrachill_users_continued_login_flow( ?array $flow = null ): array {
	static $current = array();

	if ( null !== $flow ) {
		$current = $flow;
	}

	return $current;
}

/**
 * Raise the API-login allowance for wp-native-auth's own password login.
 *
 * The generic `wp-native/auth-login` ability calls wp_authenticate() right
 * after this filter and runs the challenge loop right after that.
 *
 * @param null|WP_Error $blocked    Null to continue.
 * @param string        $identifier Identifier being authenticated.
 * @param array         $context    Login context.
 * @return null|WP_Error
 */
function extrachill_users_two_factor_native_pre_authenticate( $blocked, $identifier, $context ) {
	unset( $identifier );

	if ( ! is_wp_error( $blocked ) && is_array( $context ) && 'login' === ( $context['reason'] ?? '' ) ) {
		extrachill_users_two_factor_allow_api_primary_login( true );
	}

	return $blocked;
}
add_filter( 'wp_native_auth_pre_authenticate', 'extrachill_users_two_factor_native_pre_authenticate', PHP_INT_MAX, 3 );

/**
 * Lower the allowance once wp-native-auth has authenticated the password.
 *
 * @param null|WP_Error $blocked Null to continue.
 * @return null|WP_Error
 */
function extrachill_users_two_factor_native_pre_login( $blocked ) {
	extrachill_users_two_factor_allow_api_primary_login( false );

	return $blocked;
}
add_filter( 'wp_native_auth_pre_login', 'extrachill_users_two_factor_native_pre_login', 1 );
