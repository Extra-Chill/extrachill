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
 * Whether the user signs in with Two Factor.
 *
 * @param WP_User $user User to check.
 */
function extrachill_users_user_uses_two_factor( WP_User $user ): bool {
	return class_exists( 'Two_Factor_Core' )
		&& is_callable( array( 'Two_Factor_Core', 'is_user_using_two_factor' ) )
		&& (bool) Two_Factor_Core::is_user_using_two_factor( (int) $user->ID );
}

/**
 * Refuse a login that would skip a 2FA member's second factor.
 *
 * Without the `two-factor` policy, only the password login can hand a 2FA
 * member to a code step (the validate_2fa fallback). Every other entry point
 * that reaches the challenge loop (Google, Apple) would otherwise mint tokens
 * or a cookie on the provider's word alone, so it fails closed instead.
 *
 * @param WP_User $user User whose primary credential was verified.
 * @return WP_Error|null `two_factor_required` (403), or null when the login may proceed.
 */
function extrachill_users_two_factor_required_error( WP_User $user ): ?WP_Error {
	if ( extrachill_users_two_factor_policy_active() || ! extrachill_users_user_uses_two_factor( $user ) ) {
		return null;
	}

	return new WP_Error(
		'two_factor_required',
		__( 'This account uses two-step sign-in. Sign in with your email or username and password to enter your verification code.', 'extrachill-users' ),
		array( 'status' => 403 )
	);
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
	// wp-native-auth also asks whether this policy would challenge before a
	// browser handoff. That is a probe, so no code is sent for it.
	$probe = 'browser_handoff' === ( $context['reason'] ?? '' );

	// The primary credential is verified; the scoped API-login allowance used
	// to reach this point must not outlive it.
	extrachill_users_two_factor_allow_api_primary_login( false );

	if ( ! extrachill_users_two_factor_api_available() || ! Two_Factor_Core::is_user_using_two_factor( $user->ID ) ) {
		return null;
	}

	$descriptor = extrachill_users_two_factor_descriptor( $user );

	// Like Two Factor's own form, prompting for an emailed code sends one.
	// A rate-limited send still prompts: the member may already have a code.
	if ( ! $probe && null !== $descriptor && extrachill_users_two_factor_provider_sends_codes( $descriptor['provider'] ) ) {
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
 * Whether a user has Two Factor turned on.
 *
 * Works with any Two Factor release (production runs 0.16.0, which has no
 * non-rendering login API), so it gates paths that must fail closed even
 * while the `two-factor` policy is inactive.
 *
 * @param int $user_id User ID.
 */
function extrachill_users_user_uses_two_factor( int $user_id ): bool {
	return $user_id > 0
		&& class_exists( 'Two_Factor_Core' )
		&& is_callable( array( 'Two_Factor_Core', 'is_user_using_two_factor' ) )
		&& Two_Factor_Core::is_user_using_two_factor( $user_id );
}

/**
 * When a wp-native-auth device session last passed the `two-factor` policy.
 *
 * @param int    $user_id   Session owner.
 * @param string $device_id Device UUID.
 * @return int Unix time, or 0 when the session is unknown, inactive, never verified, or verified by another policy.
 */
function extrachill_users_two_factor_device_session_time( int $user_id, string $device_id ): int {
	if ( ! function_exists( 'wp_native_auth_get_session' ) ) {
		return 0;
	}

	$session = wp_native_auth_get_session( $user_id, $device_id );
	if ( ! is_array( $session )
		|| EXTRACHILL_USERS_TWO_FACTOR_POLICY !== ( $session['challenge_policy'] ?? null )
		|| empty( $session['challenge_verified_at'] ) ) {
		return 0;
	}

	return (int) $session['challenge_verified_at'];
}

/**
 * Raise or read the flag that the refresh in progress will set a web cookie.
 *
 * extrachill_users_refresh_tokens() raises it around the token rotation so
 * the refresh gate can refuse before anything rotates.
 *
 * @param bool|null $requested True or false to set, null to read.
 */
function extrachill_users_refresh_cookie_requested( ?bool $requested = null ): bool {
	static $current = false;

	if ( null !== $requested ) {
		$current = $requested;
	}

	return $current;
}

/**
 * Stop a refresh token from upgrading a session that never passed 2FA.
 *
 * Runs on wp-native-auth's refresh (the `wp_native_auth_pre_login` filter
 * with reason `refresh`), before the token rotates, for every refresh
 * surface: extrachill/v1/auth/refresh, wp-native/auth-refresh, and the
 * OAuth refresh grant. For a member who uses Two Factor and whose device
 * session has no `two-factor` verification:
 *
 * - A refresh that would set a web cookie is refused (`two_factor_required`,
 *   403) whether or not the policy is active. The tokens are untouched and
 *   the member can sign in on the web, which asks for the code.
 * - A plain token refresh of a native app session is refused
 *   (`two_factor_required`, 401) once the policy is active, so the app signs
 *   in again and completes the code step. While the policy is inactive, it
 *   still succeeds: the app has no way to verify a session yet, and refusing
 *   would lock those members out of the app.
 *
 * OAuth-bound sessions are not refused on token refresh. They were granted
 * from a web session, and the web login already asked for the code.
 * Sessions that passed the policy refresh as before.
 *
 * @param null|WP_Error $blocked Null to continue.
 * @param WP_User|mixed $user    Session owner.
 * @param array|mixed   $context wp-native-auth context: device_id, reason.
 * @return null|WP_Error
 */
function extrachill_users_two_factor_refresh_gate( $blocked, $user, $context ) {
	if ( is_wp_error( $blocked ) || ! $user instanceof WP_User || ! is_array( $context ) || 'refresh' !== ( $context['reason'] ?? '' ) ) {
		return $blocked;
	}

	$refused = extrachill_users_two_factor_refresh_refusal(
		$user,
		isset( $context['device_id'] ) ? (string) $context['device_id'] : '',
		extrachill_users_refresh_cookie_requested(),
		extrachill_users_two_factor_policy_active()
	);

	return null !== $refused ? $refused : $blocked;
}

/**
 * Decide whether a refresh of one device session must be refused for 2FA.
 *
 * @param WP_User $user          Session owner.
 * @param string  $device_id     Device UUID.
 * @param bool    $cookie        Whether the refresh would set a web cookie.
 * @param bool    $policy_active Whether the `two-factor` policy can verify sessions.
 * @return WP_Error|null Error to refuse with, or null to allow.
 */
function extrachill_users_two_factor_refresh_refusal( WP_User $user, string $device_id, bool $cookie, bool $policy_active ): ?WP_Error {
	if ( ! extrachill_users_user_uses_two_factor( (int) $user->ID ) ) {
		return null;
	}

	if ( extrachill_users_two_factor_device_session_time( (int) $user->ID, $device_id ) > 0 ) {
		return null;
	}

	if ( $cookie ) {
		return new WP_Error(
			'two_factor_required',
			__( 'Sign in again to continue on the web.', 'extrachill-users' ),
			array( 'status' => 403 )
		);
	}

	if ( ! $policy_active || extrachill_users_is_oauth_device_session( (int) $user->ID, $device_id ) ) {
		return null;
	}

	return new WP_Error(
		'two_factor_required',
		__( 'Sign in again to finish two-step sign-in.', 'extrachill-users' ),
		array( 'status' => 401 )
	);
}
add_filter( 'wp_native_auth_pre_login', 'extrachill_users_two_factor_refresh_gate', 20, 3 );

/**
 * Whether a device session belongs to an OAuth client grant.
 *
 * @param int    $user_id   Session owner.
 * @param string $device_id Device UUID.
 */
function extrachill_users_is_oauth_device_session( int $user_id, string $device_id ): bool {
	if ( ! function_exists( 'wp_native_auth_get_session' ) ) {
		return false;
	}

	$session = wp_native_auth_get_session( $user_id, $device_id );

	return is_array( $session ) && ! empty( $session['oauth_client_id'] );
}

/**
 * Refuse a wp-native-auth browser handoff that would skip 2FA.
 *
 * wp-native-auth's receiver (`?wp-native-handoff=`) already refuses when the
 * `two-factor` policy would challenge and the minting session did not pass
 * it. While the policy is inactive (Two Factor without its login API), no
 * policy challenges, so this filter applies the same rule: a Two Factor
 * member's handoff needs a minting session the `two-factor` policy verified.
 * A refused handoff lands on the login URL, which Extra Chill sends to
 * /login/ with the destination kept.
 *
 * When it is allowed, Two Factor's wp_login handler is detached for this
 * request so it doesn't replace the new session with its own form.
 *
 * This is a separate flow from extrachill/v1/auth/browser-handoff
 * (`?ec_browser_handoff=`), which Extra Chill handles itself.
 *
 * @param null|WP_Error $blocked Null to allow.
 * @param WP_User|mixed $user    Handoff user.
 * @param array|mixed   $context wp-native-auth context: device_id, session, challenge_policy.
 * @return null|WP_Error
 */
function extrachill_users_two_factor_pre_browser_handoff( $blocked, $user, $context ) {
	if ( is_wp_error( $blocked ) || ! $user instanceof WP_User || ! extrachill_users_user_uses_two_factor( (int) $user->ID ) ) {
		return $blocked;
	}

	$session = is_array( $context ) && isset( $context['session'] ) && is_array( $context['session'] ) ? $context['session'] : null;
	if ( null === $session
		|| (int) ( $session['user_id'] ?? 0 ) !== (int) $user->ID
		|| EXTRACHILL_USERS_TWO_FACTOR_POLICY !== ( $session['challenge_policy'] ?? null )
		|| empty( $session['challenge_verified_at'] ) ) {
		return new WP_Error(
			'two_factor_required',
			__( 'Sign in to continue.', 'extrachill-users' ),
			array( 'status' => 403 )
		);
	}

	remove_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ), PHP_INT_MAX );

	return $blocked;
}
add_filter( 'wp_native_auth_pre_browser_handoff', 'extrachill_users_two_factor_pre_browser_handoff', 10, 3 );

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
 * A 2FA member is refused with `two_factor_required` when the `two-factor`
 * policy is not active, so no entry point can skip the second factor.
 *
 * @param WP_User             $user          User whose primary credential was verified.
 * @param string              $device_id     Device UUID.
 * @param array<string,mixed> $login_options device_name, remember, set_cookie.
 * @param array<string,mixed> $flow          Extra Chill flow data: flow (password|oauth) plus its redirect inputs.
 * @return array<string,mixed>|WP_Error|null Challenge response, error, or null to complete the login now.
 */
function extrachill_users_maybe_challenge_login( WP_User $user, string $device_id, array $login_options, array $flow ) {
	$two_factor_required = extrachill_users_two_factor_required_error( $user );
	if ( null !== $two_factor_required ) {
		return $two_factor_required;
	}

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

/**
 * When the current request's session last passed Two Factor.
 *
 * A bearer request answers from its wp-native-auth device session, and only
 * when the `two-factor` policy verified it. A cookie request answers from Two
 * Factor's own session stamp.
 *
 * @param int $user_id User the session must belong to.
 * @return int Unix time of the last 2FA verification, or 0 when none.
 */
function extrachill_users_current_session_two_factor_time( int $user_id ): int {
	if ( $user_id <= 0 || get_current_user_id() !== $user_id ) {
		return 0;
	}

	if ( function_exists( 'wp_native_auth_get_current_session' ) ) {
		$session = wp_native_auth_get_current_session();
		if ( null !== $session ) {
			if ( (int) $session['user_id'] !== $user_id
				|| EXTRACHILL_USERS_TWO_FACTOR_POLICY !== $session['challenge_policy']
				|| null === $session['challenge_verified_at'] ) {
				return 0;
			}

			return (int) $session['challenge_verified_at'];
		}
	}

	if ( class_exists( 'Two_Factor_Core' ) && is_callable( array( 'Two_Factor_Core', 'is_current_user_session_two_factor' ) ) ) {
		return (int) Two_Factor_Core::is_current_user_session_two_factor();
	}

	return 0;
}

/**
 * Decide whether a browser handoff may sign a user in, and prepare it.
 *
 * A browser handoff turns an app (or browser) session into a cookie session.
 * For a Two Factor member that is only safe when the session the handoff was
 * minted from had passed 2FA. In that case the new cookie session carries the
 * same 2FA time, and Two Factor's wp_login handler is detached for the rest of
 * the request so it does not destroy the session and render its own form.
 * Otherwise the handoff must not sign the member in at all.
 *
 * @param WP_User $user        User the handoff is for.
 * @param int     $verified_at When the minting session last passed 2FA (0 for never).
 * @return bool True when the handoff may set the auth cookie.
 */
function extrachill_users_two_factor_prepare_browser_handoff( WP_User $user, int $verified_at ): bool {
	if ( ! class_exists( 'Two_Factor_Core' )
		|| ! is_callable( array( 'Two_Factor_Core', 'is_user_using_two_factor' ) )
		|| ! Two_Factor_Core::is_user_using_two_factor( (int) $user->ID ) ) {
		return true;
	}

	if ( $verified_at <= 0 ) {
		return false;
	}

	remove_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ), PHP_INT_MAX );

	$user_id = (int) $user->ID;
	add_filter(
		'attach_session_information',
		static function ( $session, $session_user_id ) use ( $user_id, $verified_at ) {
			if ( (int) $session_user_id === $user_id && is_array( $session ) ) {
				$session['two-factor-login'] = $verified_at;
			}

			return $session;
		},
		10,
		2
	);

	return true;
}
