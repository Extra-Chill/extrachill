<?php
/**
 * Bridge: makes wp-native-auth respect Extra Chill policy when both
 * plugins are active. Loaded conditionally from extrachill-users.php.
 *
 * Hooks delegate to existing extrachill-users functions. No duplicated logic.
 *
 * Filters / actions consumed:
 *  - wp_native_auth_pre_login         (filter) — community membership + block check
 *  - wp_native_auth_user_payload      (filter) — decorate user with profile_url
 *  - wp_native_auth_pre_authenticate  (filter) — Turnstile gate (structured no-op v0.1)
 *  - wp_native_auth_after_login       (action) — fire EC after-login hooks
 *  - wp_native_auth_pre_register      (filter) — EC username override
 *  - wp_native_auth_after_register    (action) — community provisioning and metadata
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
 * @param array         $context Contextual data from wp-native-auth (device_id, etc.).
 * @return null|WP_Error Null to continue, WP_Error to block login.
 */
function extrachill_users_wp_native_pre_login_check( null|WP_Error $result, WP_User $user, array $context ): null|WP_Error {
	unset( $context );
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
	unset( $context );
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
	unset( $identifier, $context );
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
 * @param int    $user_id    The logged-in user's ID.
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

/**
 * Pre-register gate: fail closed when site registration policy cannot run.
 *
 * WordPress native auth deliberately exposes a generic registration ability, but its
 * schema cannot carry Extra Chill's Turnstile proof. Block that path before
 * user creation; branded registration remains the only public site surface.
 *
 * @param null|WP_Error $result            Pass-through from earlier filters, or null.
 * @param array         $registration_data Registration data: [email, password, username]. Passed by reference by the caller.
 * @param array         $context           Contextual data from wp-native-auth (device_id, etc.).
 * @return WP_Error Existing policy error or the fail-closed site policy error.
 */
function extrachill_users_wp_native_pre_register( null|WP_Error $result, array &$registration_data, array $context ): WP_Error {
	unset( $registration_data, $context );
	if ( $result instanceof WP_Error ) {
		return $result; // Earlier filter already blocked.
	}

	/*
	 * wp-native/auth-register is public but cannot carry Extra Chill's required
	 * Turnstile proof. Keep it fail-closed at the consumer policy layer. Future
	 * exposure must route through the branded registration boundary or supply a
	 * server-verifiable context that can execute the same site policy first.
	 */
	return new WP_Error(
		'extrachill_registration_surface_unavailable',
		__( 'Registration is not available through this endpoint.', 'extrachill-users' ),
		array( 'status' => 403 )
	);
}
add_filter( 'wp_native_auth_pre_register', 'extrachill_users_wp_native_pre_register', 10, 3 );

/**
 * After-register action: provisions community membership, records default-off
 * newsletter consent, registration metadata, and fires the EC after-register hook.
 *
 * Delegates to existing extrachill-users / EC functions — no new business logic.
 * Mirrors the post-creation steps in extrachill_users_register_with_tokens()
 * (inc/auth-tokens/service.php).
 *
 * @param int    $user_id    The newly created user's ID.
 * @param string $device_id  The device UUID that registered.
 * @param array  $token_pair The token pair issued (access + refresh).
 */
function extrachill_users_wp_native_after_register( int $user_id, string $device_id, array $token_pair ): void {
	// Community-blog membership: delegate to the same filter the existing
	// registration service uses for cross-blog provisioning.
	$user = get_user_by( 'id', $user_id );
	if ( $user ) {
		$registration_data = array(
			'username' => $user->user_login,
			'password' => '', // Already created — not needed for provisioning.
			'email'    => $user->user_email,
		);

		/**
		 * Provision community-blog membership for the new user.
		 *
		 * This is the same filter used by extrachill_users_register_with_tokens()
		 * to add the user to the community blog. Consumer code (EC multisite)
		 * listens on this filter to call add_user_to_blog().
		 */
		apply_filters( 'extrachill_create_community_user', $user_id, $registration_data );
	}

	if ( $user ) {
		extrachill_users_record_registration_newsletter_consent( $user_id, $user->user_email, false, 'wp-native', 'native' );
	}

	// Registration metadata: mirrors service.php post-creation meta.
	update_user_meta( $user_id, 'registration_timestamp', current_time( 'mysql' ) );

	/**
	 * Fires after a successful registration through wp-native-auth.
	 *
	 * Allows EC subsystems (analytics, notifications, onboarding, etc.) to react
	 * to native-auth registrations identically to REST-route registrations.
	 *
	 * @param int    $user_id    The newly created user's ID.
	 * @param string $device_id  The device UUID.
	 * @param array  $token_pair The issued token pair.
	 */
	do_action( 'extrachill_users_after_native_register', $user_id, $device_id, $token_pair );
}
add_action( 'wp_native_auth_after_register', 'extrachill_users_wp_native_after_register', 10, 3 );
