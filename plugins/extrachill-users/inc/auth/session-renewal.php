<?php
/**
 * Remember Me: 30-day rolling login sessions.
 *
 * A remembered login lasts 30 days and slides forward while the member keeps
 * visiting, so it ends only after 30 days of inactivity (#1782). Sessions that
 * were not remembered keep core's short default and are never promoted.
 *
 * - `auth_cookie_expiration` gives remembered cookies 30 days.
 * - `set_auth_cookie` records on the session whether it was remembered.
 * - On an authenticated front-end or REST cookie request, a remembered session
 *   issued or renewed more than a day ago is extended in place for a fresh 30
 *   days. The session token never changes, so concurrent requests and nonces in
 *   other open tabs stay valid, and the Two Factor keys are kept as-is.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/** Session key recording whether the session came from a remembered login. */
const EXTRACHILL_USERS_SESSION_REMEMBER_KEY = 'ec_remember';

/** Session key recording when a remembered session was last renewed. */
const EXTRACHILL_USERS_SESSION_RENEWED_KEY = 'ec_renewed_at';

/**
 * Remembered login lifetime.
 *
 * @return int Seconds.
 */
function extrachill_users_remembered_session_lifetime(): int {
	return 30 * DAY_IN_SECONDS;
}

/**
 * Minimum time between renewals of one remembered session.
 *
 * @return int Seconds.
 */
function extrachill_users_session_renewal_interval(): int {
	return DAY_IN_SECONDS;
}

/**
 * Remembered sessions last 30 days; others keep the core default.
 *
 * @param int  $length   Cookie lifetime in seconds.
 * @param int  $user_id  User ID.
 * @param bool $remember Whether the login was remembered.
 * @return int
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- auth_cookie_expiration filter signature.
function extrachill_users_auth_cookie_expiration( $length, $user_id, $remember ) {
	if ( $remember ) {
		return extrachill_users_remembered_session_lifetime();
	}
	return $length;
}
add_filter( 'auth_cookie_expiration', 'extrachill_users_auth_cookie_expiration', 10, 3 );

/**
 * Record on the session whether its cookie is persistent.
 *
 * Core passes `$expire` 0 for a browser-session (not remembered) cookie.
 *
 * @param string $auth_cookie Auth cookie value.
 * @param int    $expire      Cookie expiry sent to the browser; 0 when not remembered.
 * @param int    $expiration  Session expiration timestamp.
 * @param int    $user_id     User ID.
 * @param string $scheme      Cookie scheme.
 * @param string $token       Session token.
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- set_auth_cookie action signature.
function extrachill_users_record_session_remember( $auth_cookie, $expire, $expiration, $user_id, $scheme, $token ) {
	if ( ! $user_id || '' === (string) $token ) {
		return;
	}

	$manager = WP_Session_Tokens::get_instance( (int) $user_id );
	$session = $manager->get( (string) $token );
	if ( ! is_array( $session ) ) {
		return;
	}

	$remember = 0 !== (int) $expire;
	if ( isset( $session[ EXTRACHILL_USERS_SESSION_REMEMBER_KEY ] ) && $session[ EXTRACHILL_USERS_SESSION_REMEMBER_KEY ] === $remember ) {
		return;
	}

	$session[ EXTRACHILL_USERS_SESSION_REMEMBER_KEY ] = $remember;
	$manager->update( (string) $token, $session );
}
add_action( 'set_auth_cookie', 'extrachill_users_record_session_remember', 10, 6 );

/**
 * Whether a stored session came from a remembered login.
 *
 * Sessions created before the flag existed are classified by lifetime: core
 * gives non-remembered sessions 2 days and remembered ones 14, so anything
 * issued for more than 3 days was remembered.
 *
 * @param array<string,mixed> $session Session data.
 * @return bool
 */
function extrachill_users_session_is_remembered( array $session ): bool {
	if ( array_key_exists( EXTRACHILL_USERS_SESSION_REMEMBER_KEY, $session ) ) {
		return true === $session[ EXTRACHILL_USERS_SESSION_REMEMBER_KEY ];
	}

	if ( ! isset( $session['login'], $session['expiration'] ) ) {
		return false;
	}

	return ( (int) $session['expiration'] - (int) $session['login'] ) > 3 * DAY_IN_SECONDS;
}

/**
 * Extend a remembered session in place once the throttle has passed.
 *
 * The session keeps its token, so concurrent requests carrying the same cookie
 * stay authenticated and nonces in other open tabs stay valid. Only the stored
 * expiration moves forward and the renewal time is recorded; every other key
 * on the session (including the Two Factor keys) is preserved. Nothing is ever
 * destroyed.
 *
 * Does nothing for non-remembered or unknown sessions, or when the session was
 * issued or last renewed less than a day ago.
 *
 * @param int    $user_id User ID that owns the session.
 * @param string $token   Current session token.
 * @return bool Whether the session was renewed.
 */
function extrachill_users_maybe_renew_remembered_session( int $user_id, string $token ): bool {
	if ( $user_id <= 0 || '' === $token ) {
		return false;
	}

	$manager = WP_Session_Tokens::get_instance( $user_id );
	$session = $manager->get( $token );
	if ( ! is_array( $session ) || ! extrachill_users_session_is_remembered( $session ) ) {
		return false;
	}

	if ( isset( $session[ EXTRACHILL_USERS_SESSION_RENEWED_KEY ] ) ) {
		$renewed_at = (int) $session[ EXTRACHILL_USERS_SESSION_RENEWED_KEY ];
	} else {
		$renewed_at = isset( $session['login'] ) ? (int) $session['login'] : 0;
	}
	if ( time() - $renewed_at <= extrachill_users_session_renewal_interval() ) {
		return false;
	}

	$now = time();

	$session['expiration']                            = $now + (int) apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $user_id, true );
	$session[ EXTRACHILL_USERS_SESSION_RENEWED_KEY ]  = $now;
	$session[ EXTRACHILL_USERS_SESSION_REMEMBER_KEY ] = true;
	$manager->update( $token, $session );

	// Keep this request's cookies in step with the re-sent ones. Cookie names
	// are runtime constants defined by wp_cookie_constants().
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- set_auth_cookie action signature.
	$sync_auth      = static function ( $cookie, $expire, $exp, $uid, $scheme ) {
		$_COOKIE[ constant( 'secure_auth' === $scheme ? 'SECURE_AUTH_COOKIE' : 'AUTH_COOKIE' ) ] = $cookie;
	};
	$sync_logged_in = static function ( $cookie ) {
		$_COOKIE[ constant( 'LOGGED_IN_COOKIE' ) ] = $cookie;
	};

	add_action( 'set_auth_cookie', $sync_auth, 10, 5 );
	add_action( 'set_logged_in_cookie', $sync_logged_in, 10, 1 );
	try {
		wp_set_auth_cookie( $user_id, true, '', $token );
	} finally {
		remove_action( 'set_auth_cookie', $sync_auth, 10 );
		remove_action( 'set_logged_in_cookie', $sync_logged_in, 10 );
	}

	return true;
}

/**
 * Whether this request may rewrite the auth cookie.
 *
 * @return bool
 */
function extrachill_users_session_renewal_request_allowed(): bool {
	if ( headers_sent() ) {
		return false;
	}

	if ( doing_action( 'wp_logout' ) || did_action( 'wp_logout' ) ) {
		return false;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing checks.
	if ( isset( $_GET['custom_logout'] ) || ( isset( $_REQUEST['action'] ) && 'logout' === $_REQUEST['action'] ) ) {
		return false;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$authorization = '';
	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$authorization = (string) $_SERVER['HTTP_AUTHORIZATION']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$authorization = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
	if ( '' !== $authorization ) {
		return false;
	}

	return true;
}

/**
 * Renew the current request's cookie session when it is a remembered one.
 *
 * Only acts when the current user was authenticated by the logged-in cookie.
 *
 * @return bool Whether the session was renewed.
 */
function extrachill_users_renew_current_cookie_session(): bool {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! extrachill_users_session_renewal_request_allowed() ) {
		return false;
	}

	$cookie_user = wp_validate_auth_cookie( '', 'logged_in' );
	if ( (int) $cookie_user !== $user_id ) {
		return false;
	}

	return extrachill_users_maybe_renew_remembered_session( $user_id, wp_get_session_token() );
}

/**
 * Front-end page requests.
 */
function extrachill_users_renew_session_on_front_end() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	extrachill_users_renew_current_cookie_session();
}
add_action( 'template_redirect', 'extrachill_users_renew_session_on_front_end', 0 );

/**
 * REST cookie requests, after core verified the cookie and its nonce.
 *
 * @param WP_Error|null|true $result Authentication result.
 * @return WP_Error|null|true
 */
function extrachill_users_renew_session_on_rest( $result ) {
	global $wp_rest_auth_cookie;

	if ( is_wp_error( $result ) || true !== $wp_rest_auth_cookie ) {
		return $result;
	}

	// The token is unchanged, so the request's nonce stays valid.
	extrachill_users_renew_current_cookie_session();

	return $result;
}
add_filter( 'rest_authentication_errors', 'extrachill_users_renew_session_on_rest', 110 );
