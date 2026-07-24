<?php
/**
 * Login Handler
 *
 * Handles login error redirects via EC_Redirect_Handler and blocks direct wp-login.php access.
 * Includes rate limiting to prevent brute force attacks.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_LOGIN_RATE_LIMIT  = 5;
const EXTRACHILL_USERS_LOGIN_RATE_WINDOW = 15 * MINUTE_IN_SECONDS;
const EXTRACHILL_USERS_LOGIN_CACHE_GROUP = 'extrachill-users-login-rate-limit';

/**
 * Get the transient key for login attempts.
 *
 * @param string $username Username being attempted.
 * @return string Transient key.
 */
function ec_get_login_attempt_key( $username ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( '' === $ip ) {
		return '';
	}

	$identifier = trim( (string) $username );
	$user       = is_email( $identifier ) ? get_user_by( 'email', $identifier ) : get_user_by( 'login', $identifier );
	$identity   = $user instanceof WP_User ? 'user:' . $user->ID : 'identifier:' . strtolower( $identifier );
	return 'ec_login_attempts_' . md5( $ip . '|' . $identity );
}

/**
 * Return a stable error when atomic login accounting is unavailable.
 *
 * @return WP_Error Storage error.
 */
function ec_login_limiter_unavailable_error() {
	return new WP_Error(
		'ec_login_limiter_unavailable',
		__( 'Login is temporarily unavailable. Please try again.', 'extrachill-users' )
	);
}

/**
 * Run one login rate-limit storage operation.
 *
 * Production uses the persistent object cache. Tests may replace this callable
 * through `extrachill_users_login_rate_limit_store` to control race ordering.
 *
 * @param string   $operation Operation name: get, increment, or clear.
 * @param string   $key       Hashed requester and identity key.
 * @param int|null $now       Optional Unix timestamp for deterministic tests.
 * @return int|WP_Error Effective attempt count, or an error on storage failure.
 */
function ec_login_rate_limit_cache_operation( $operation, $key, $now = null ) {
	if ( ! function_exists( 'wp_using_ext_object_cache' )
		|| ! wp_using_ext_object_cache()
		|| ! function_exists( 'wp_cache_add' )
		|| ! function_exists( 'wp_cache_incr' )
		|| ! function_exists( 'wp_cache_set' )
	) {
		return ec_login_limiter_unavailable_error();
	}

	$now          = null === $now ? time() : (int) $now;
	$window_key   = $key . '_window_' . intdiv( $now, EXTRACHILL_USERS_LOGIN_RATE_WINDOW );
	$total_key    = $window_key . '_total';
	$cleared_key  = $window_key . '_cleared';
	$storage_ttl  = 2 * EXTRACHILL_USERS_LOGIN_RATE_WINDOW;
	$read_counter = static function ( $counter_key ) use ( $storage_ttl ) {
		$found = false;
		$count = wp_cache_get( $counter_key, EXTRACHILL_USERS_LOGIN_CACHE_GROUP, true, $found );
		if ( $found ) {
			return is_numeric( $count ) ? (int) $count : ec_login_limiter_unavailable_error();
		}

		if ( wp_cache_add( $counter_key, 0, EXTRACHILL_USERS_LOGIN_CACHE_GROUP, $storage_ttl ) ) {
			return 0;
		}

		$count = wp_cache_get( $counter_key, EXTRACHILL_USERS_LOGIN_CACHE_GROUP, true, $found );
		return $found && is_numeric( $count ) ? (int) $count : ec_login_limiter_unavailable_error();
	};

	if ( 'clear' === $operation ) {
		$total = $read_counter( $total_key );
		if ( is_wp_error( $total ) ) {
			return $total;
		}

		// A failure before this read is cleared; an increment after it remains
		// above the marker. Concurrent marker regression can only overcount.
		return wp_cache_set( $cleared_key, $total, EXTRACHILL_USERS_LOGIN_CACHE_GROUP, $storage_ttl )
			? 0
			: ec_login_limiter_unavailable_error();
	}

	if ( 'get' !== $operation && 'increment' !== $operation ) {
		return ec_login_limiter_unavailable_error();
	}

	if ( 'increment' === $operation ) {
		if ( wp_cache_add( $total_key, 1, EXTRACHILL_USERS_LOGIN_CACHE_GROUP, $storage_ttl ) ) {
			$total = 1;
		} else {
			$total = wp_cache_incr( $total_key, 1, EXTRACHILL_USERS_LOGIN_CACHE_GROUP );
			if ( false === $total || ! is_numeric( $total ) ) {
				return ec_login_limiter_unavailable_error();
			}
		}
	} else {
		$total = $read_counter( $total_key );
		if ( is_wp_error( $total ) ) {
			return $total;
		}
	}

	$cleared = $read_counter( $cleared_key );
	return is_wp_error( $cleared ) ? $cleared : max( 0, (int) $total - $cleared );
}

/**
 * Use the configured login rate-limit store.
 *
 * @param string $operation Operation name.
 * @param string $key       Hashed limiter key.
 * @return int|WP_Error Current attempt count, or an error on storage failure.
 */
function ec_login_rate_limit_store( $operation, $key ) {
	if ( '' === $key ) {
		return ec_login_limiter_unavailable_error();
	}

	/**
	 * Filter the atomic login rate-limit storage callable.
	 *
	 * @param callable $store Storage callable accepting operation and key.
	 */
	$store = apply_filters( 'extrachill_users_login_rate_limit_store', 'ec_login_rate_limit_cache_operation' );
	if ( ! is_callable( $store ) ) {
		return ec_login_limiter_unavailable_error();
	}

	$result = call_user_func( $store, $operation, $key, null );
	return is_wp_error( $result ) || is_numeric( $result )
		? $result
		: ec_login_limiter_unavailable_error();
}

/**
 * Check if login is blocked due to too many failed attempts.
 *
 * @param string $username Username being attempted.
 * @return bool True if blocked.
 */
function ec_is_login_blocked( $username ) {
	if ( empty( $username ) ) {
		return false;
	}

	$attempts = ec_login_rate_limit_store( 'get', ec_get_login_attempt_key( $username ) );
	return is_wp_error( $attempts ) || $attempts >= EXTRACHILL_USERS_LOGIN_RATE_LIMIT;
}

/**
 * Record a failed login attempt.
 *
 * @param string $username Username that failed.
 * @return int|WP_Error Current attempt count, or an error on storage failure.
 */
function ec_record_failed_login( $username ) {
	if ( empty( $username ) ) {
		return ec_login_limiter_unavailable_error();
	}

	return ec_login_rate_limit_store( 'increment', ec_get_login_attempt_key( $username ) );
}

/**
 * Clear login attempts after successful login.
 *
 * @param string $username Username that succeeded.
 * @return int|WP_Error Zero on success, or an error on storage failure.
 */
function ec_clear_login_attempts( $username ) {
	if ( empty( $username ) ) {
		return ec_login_limiter_unavailable_error();
	}

	return ec_login_rate_limit_store( 'clear', ec_get_login_attempt_key( $username ) );
}

/**
 * Block authentication if too many failed attempts.
 *
 * @param WP_User|WP_Error|null $user     User object, error, or null.
 * @param string                $username Username being attempted.
 * @return WP_User|WP_Error|null User or error.
 */
function ec_rate_limit_login( $user, $username ) {
	if ( empty( $username ) ) {
		return $user;
	}

	if ( is_wp_error( $user ) ) {
		$attempts = ec_record_failed_login( $username );
		if ( is_wp_error( $attempts ) ) {
			return $attempts;
		}
	} else {
		$attempts = ec_login_rate_limit_store( 'get', ec_get_login_attempt_key( $username ) );
		if ( is_wp_error( $attempts ) ) {
			return $attempts;
		}
	}

	if ( $attempts > EXTRACHILL_USERS_LOGIN_RATE_LIMIT || ( ! is_wp_error( $user ) && $attempts >= EXTRACHILL_USERS_LOGIN_RATE_LIMIT ) ) {
		return new WP_Error(
			'ec_login_blocked',
			__( 'Too many failed login attempts. Please try again in 15 minutes.', 'extrachill-users' )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'ec_rate_limit_login', 20, 2 );

/**
 * Clear login attempts on successful login.
 *
 * @param string  $user_login Username.
 * @param WP_User $user       User object.
 */
function ec_clear_attempts_on_login( $user_login, $user ) {
	unset( $user );
	if ( empty( $user_login ) ) {
		return;
	}
	ec_clear_login_attempts( $user_login );
}
add_action( 'wp_login', 'ec_clear_attempts_on_login', 10, 2 );

/**
 * Clear login attempts after successful Two-Factor Authentication.
 *
 * The REST login flow redirects 2FA users before wp_authenticate() runs,
 * so the wp_login action never fires. This hooks into the Two Factor plugin's
 * own authenticated action to clear the attempts counter.
 *
 * @param WP_User $user User that completed 2FA.
 */
function ec_clear_attempts_on_two_factor( WP_User $user ) {
	ec_clear_login_attempts( $user->user_login );
}
add_action( 'two_factor_user_authenticated', 'ec_clear_attempts_on_two_factor' );

/**
 * Handle failed login attempts by setting error transient and redirecting.
 *
 * @param string $username Username attempted
 */
function extrachill_handle_login_failed( $username ) {
	unset( $username );
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

	if ( empty( $referrer ) || false !== strpos( $referrer, 'wp-login' ) || false !== strpos( $referrer, 'wp-admin' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Login failure callback runs after core auth handling; values are used only for redirect context.
	$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
	$fragment   = isset( $_POST['source_fragment'] ) ? sanitize_text_field( wp_unslash( $_POST['source_fragment'] ) ) : 'tab-login';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( empty( $source_url ) ) {
		$source_url = home_url( '/login/' );
	}

	$redirect = new EC_Redirect_Handler( $source_url, $fragment, 'ec_login' );
	$redirect->error( __( 'Invalid username or password. Please try again.', 'extrachill-users' ) );
}
add_action( 'wp_login_failed', 'extrachill_handle_login_failed' );

/**
 * Intercept authentication errors and redirect with transient message.
 *
 * @param WP_User|WP_Error $user     User object or error
 * @param string           $username Username
 * @param string           $password Password
 * @return WP_User|WP_Error
 */
// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress authenticate filter signature.
function extrachill_intercept_auth_error( $user, $username, $password ) {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return $user;
	}

	if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		return $user;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Authenticate filter runs during core login handling; values are used only for redirect context.
	if ( ! isset( $_POST['log'] ) || ! isset( $_POST['pwd'] ) ) {
		return $user;
	}

	if ( ! is_wp_error( $user ) ) {
		return $user;
	}

	$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
	$fragment   = isset( $_POST['source_fragment'] ) ? sanitize_text_field( wp_unslash( $_POST['source_fragment'] ) ) : 'tab-login';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( empty( $source_url ) ) {
		$source_url = home_url( '/login/' );
	}

	$redirect = new EC_Redirect_Handler( $source_url, $fragment, 'ec_login' );
	$redirect->error( __( 'Invalid username or password. Please try again.', 'extrachill-users' ) );
	// Unreachable: error() always exits via wp_safe_redirect()/exit.
}
add_filter( 'authenticate', 'extrachill_intercept_auth_error', 99, 3 );

/**
 * Redirect direct wp-login.php access to custom login page.
 *
 * Password reset links from WordPress core emails point at
 * wp-login.php?action=rp&key=...&login=... — without special handling
 * the generic redirect strips the query string and dumps users on
 * /login/ with no way to set a new password. We catch action=rp /
 * action=resetpass explicitly and redirect to the community
 * /reset-password/ page so the key + login survive the hop.
 */
function extrachill_redirect_wp_login_access() {
	if ( false === strpos( strtolower( $_SERVER['REQUEST_URI'] ), '/wp-login.php' ) ) {
		return;
	}

	if ( is_user_logged_in() ) {
		return;
	}

	if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading reset key from query string for redirect routing; no state change here.
	$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

	// Allow two-factor authentication challenge pages through.
	if ( in_array( $action, array( 'validate_2fa', 'revalidate_2fa' ), true ) ) {
		return;
	}

	// Route password reset links to the community /reset-password/ handler.
	if ( in_array( $action, array( 'rp', 'resetpass' ), true ) ) {
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

		$reset_url = add_query_arg(
			array(
				'action' => 'reset',
				'key'    => rawurlencode( $key ),
				'login'  => rawurlencode( $login ),
			),
			ec_get_site_url( 'community' ) . '/reset-password/'
		);

		wp_safe_redirect( $reset_url );
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$login_url = home_url( '/login/' );
	if ( function_exists( 'ec_users_get_validated_login_redirect_from_request' )
		&& function_exists( 'ec_users_login_url_with_redirect' ) ) {
		$redirect_to = ec_users_get_validated_login_redirect_from_request();
		if ( null !== $redirect_to ) {
			$login_url = ec_users_login_url_with_redirect( $login_url, $redirect_to );
		}
	}

	wp_safe_redirect( $login_url );
	exit;
}
add_action( 'init', 'extrachill_redirect_wp_login_access' );
