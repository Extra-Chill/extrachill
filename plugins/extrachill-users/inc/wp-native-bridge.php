<?php
/**
 * Bridge: makes wp-native-auth respect Extra Chill policy when both
 * plugins are active. Loaded conditionally from extrachill-users.php.
 *
 * Hooks delegate to existing extrachill-users functions. No duplicated logic.
 *
 * @package ExtraChill\Users
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Pre-login gate: enforce community-blog membership and user blocking.
 *
 * Delegates to WordPress core is_user_member_of_blog() and extrachill-users'
 * extrachill_users_is_blocked(). Error codes match the existing REST routes
 * (extrachill/v1/auth/*) so clients see identical responses regardless of
 * which auth surface they hit.
 *
 * @param null|WP_Error $result  Pass-through from earlier filters, or null.
 * @param WP_User       $user    The authenticated WordPress user.
 * @param array          $context Contextual data from wp-native-auth (device_id, etc.).
 * @return null|WP_Error Null to continue, WP_Error to block login.
 */
function extrachill_users_wp_native_pre_login_check( null|WP_Error $result, WP_User $user, array $context ): null|WP_Error {
	if ( $result instanceof WP_Error ) {
		return $result; // Earlier filter already blocked.
	}

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return $result; // EC dependency missing, fall through.
	}

	$community_blog_id = ec_get_blog_id( 'community' );
	if ( empty( $community_blog_id ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'Community blog ID not available.',
			array( 'status' => 500 )
		);
	}

	if ( ! is_user_member_of_blog( $user->ID, $community_blog_id ) ) {
		return new WP_Error(
			'extrachill_not_a_member',
			'User is not a member of the community site.',
			array( 'status' => 403 )
		);
	}

	if ( function_exists( 'extrachill_users_is_blocked' ) && extrachill_users_is_blocked( (int) $user->ID ) ) {
		return new WP_Error(
			'extrachill_user_blocked',
			'This account has been suspended.',
			array( 'status' => 403 )
		);
	}

	return $result;
}
add_filter( 'wp_native_auth_pre_login', 'extrachill_users_wp_native_pre_login_check', 10, 3 );

/**
 * User payload decorator: adds profile_url to the User object.
 *
 * Delegates to extrachill_get_user_profile_url() — the same function used
 * by the existing REST routes and the OAuth login flow.
 *
 * @param array   $payload  The User array being returned by wp-native-auth.
 * @param WP_User $wp_user  The underlying WordPress user object.
 * @param array   $context  Contextual data from wp-native-auth.
 * @return array The decorated User payload.
 */
function extrachill_users_wp_native_decorate_user( array $payload, WP_User $wp_user, array $context ): array {
	if ( function_exists( 'extrachill_get_user_profile_url' ) ) {
		$payload['profile_url'] = extrachill_get_user_profile_url( $wp_user->ID, $wp_user->user_email );
	}

	return $payload;
}
add_filter( 'wp_native_auth_user_payload', 'extrachill_users_wp_native_decorate_user', 10, 3 );

/**
 * Pre-authenticate gate: Turnstile verification for web clients.
 *
 * Matches the same bypass logic used by the existing token-based registration
 * in inc/auth-tokens/service.php:
 * - App clients (HTTP_EXTRACHILL_CLIENT: app) are exempted.
 * - Local dev environments are exempted.
 * - The extrachill_bypass_turnstile_verification filter can disable it.
 *
 * NOTE (v0.1): wp-native-auth does not accept a turnstile_response input
 * field in its login schema, so this filter cannot actually verify a Turnstile
 * token yet. For v0.1 the handler is a structured no-op that documents the
 * bypass conditions. When wp-native-auth adds a turnstile_response input
 * (or a generic `extra` bag), this handler will be updated to call
 * ec_verify_turnstile_response(). Until then, Turnstile enforcement remains
 * web-only via the existing extrachill/v1/auth/* REST routes.
 *
 * @param null|WP_Error $result     Pass-through from earlier filters, or null.
 * @param string        $identifier The username or email being authenticated.
 * @param array         $context    Contextual data from wp-native-auth.
 * @return null|WP_Error Null to continue, WP_Error to block.
 */
function extrachill_users_wp_native_pre_authenticate( null|WP_Error $result, string $identifier, array $context ): null|WP_Error {
	if ( $result instanceof WP_Error ) {
		return $result;
	}

	// App client opt-out: same convention as existing REST routes.
	$is_app = isset( $_SERVER['HTTP_EXTRACHILL_CLIENT'] )
		&& 'app' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_EXTRACHILL_CLIENT'] ) );
	if ( $is_app ) {
		return $result;
	}

	// Skip in local dev.
	$is_local = defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local';
	if ( $is_local ) {
		return $result;
	}

	// Apply same filter EC uses elsewhere to bypass.
	if ( (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false ) ) {
		return $result;
	}

	// v0.1: wp-native-auth doesn't pass a turnstile_response in $context,
	// so we cannot verify a token here yet. Fall through — Turnstile remains
	// enforced via the existing extrachill/v1/auth/* REST routes for web clients.
	return $result;
}
add_filter( 'wp_native_auth_pre_authenticate', 'extrachill_users_wp_native_pre_authenticate', 10, 3 );

/**
 * After-login action: fires existing EC after-login hooks.
 *
 * This is a passthrough to allow other extrachill-users subsystems to react
 * to logins that occur through wp-native-auth (e.g. analytics, logging).
 *
 * @param int   $user_id    The logged-in user's ID.
 * @param string $device_id  The device UUID that authenticated.
 * @param array  $token_pair The token pair issued (access + refresh).
 */
function extrachill_users_wp_native_after_login( int $user_id, string $device_id, array $token_pair ): void {
	/**
	 * Fires after a successful login through wp-native-auth.
	 *
	 * Allows EC subsystems (analytics, notifications, etc.) to react to
	 * native-auth logins identically to REST-route logins.
	 *
	 * @param int    $user_id    The logged-in user's ID.
	 * @param string $device_id  The device UUID.
	 * @param array  $token_pair The issued token pair.
	 */
	do_action( 'extrachill_users_after_native_login', $user_id, $device_id, $token_pair );
}
add_action( 'wp_native_auth_after_login', 'extrachill_users_wp_native_after_login', 10, 3 );
