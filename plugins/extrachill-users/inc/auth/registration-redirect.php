<?php
/**
 * Progressive post-registration redirects.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * One-time query marker used to show account-creation confirmation.
 */
const EC_USERS_ACCOUNT_CREATED_PARAM     = 'ec_account_created';
const EC_USERS_ACCOUNT_CREATED_TOKEN_TTL = 5 * MINUTE_IN_SECONDS;

/**
 * Create a short-lived, one-time registration continuation token.
 *
 * WordPress nonces are bound to the current request's session cookie, which is
 * not available yet when REST registration has just emitted a new auth cookie.
 * A network transient keeps the continuation valid across multisite redirects.
 *
 * @param int $user_id Newly registered user ID.
 * @return string Opaque continuation token.
 */
function ec_users_create_account_created_token( int $user_id ): string {
	$token = wp_generate_password( 32, false, false );
	set_site_transient( 'ec_users_account_created_' . hash( 'sha256', $token ), $user_id, EC_USERS_ACCOUNT_CREATED_TOKEN_TTL );

	return $token;
}

/**
 * Consume a registration continuation token for the authenticated user.
 *
 * @param string $token   Opaque continuation token.
 * @param int    $user_id Authenticated user ID.
 * @return bool Whether the token was valid and consumed.
 */
function ec_users_consume_account_created_token( string $token, int $user_id ): bool {
	if ( '' === $token || $user_id < 1 ) {
		return false;
	}

	$key    = 'ec_users_account_created_' . hash( 'sha256', $token );
	$stored = (int) get_site_transient( $key );
	if ( $stored !== $user_id ) {
		return false;
	}

	delete_site_transient( $key );
	return true;
}

/**
 * Resolve the destination after account creation.
 *
 * Artist/professional join requests still require onboarding. Ordinary account
 * creation returns visitors to the Extra Chill feature that prompted registration
 * so profile customization can happen later.
 *
 * @param bool   $from_join             Whether the request came from /join.
 * @param bool   $onboarding_completed Whether profile onboarding is complete.
 * @param string $success_redirect_url  Requested post-registration destination.
 * @param string $account_created_token Signed one-time confirmation token.
 * @return string Safe post-registration destination.
 */
function ec_users_post_registration_redirect_url( $from_join, $onboarding_completed, $success_redirect_url, $account_created_token = '' ) {
	if ( $from_join && ! $onboarding_completed ) {
		$redirect_url = function_exists( 'ec_get_site_url' )
			? ec_get_site_url( 'community' ) . '/onboarding/'
			: home_url( '/onboarding/' );
	} elseif ( '' !== (string) $success_redirect_url
		&& function_exists( 'ec_users_is_valid_return_to_url' )
		&& ec_users_is_valid_return_to_url( $success_redirect_url ) ) {
		$redirect_url = $from_join
			? add_query_arg( 'from_join', 'true', (string) $success_redirect_url )
			: (string) $success_redirect_url;
	} else {
		$redirect_url = function_exists( 'ec_get_site_url' )
			? ec_get_site_url( 'community' )
			: home_url();
	}

	return '' !== $account_created_token
		? add_query_arg( EC_USERS_ACCOUNT_CREATED_PARAM, $account_created_token, $redirect_url )
		: $redirect_url;
}

/**
 * Show a one-time confirmation after registration creates an account.
 *
 * Removing the signed marker through a same-page redirect prevents refreshes
 * from repeating the notice while preserving feature-specific query parameters.
 */
function ec_users_handle_account_created_notice() {
	if ( ! is_user_logged_in() || ! isset( $_GET[ EC_USERS_ACCOUNT_CREATED_PARAM ] ) ) {
		return;
	}

	$notice_nonce = sanitize_text_field( wp_unslash( $_GET[ EC_USERS_ACCOUNT_CREATED_PARAM ] ) );
	if ( ! ec_users_consume_account_created_token( $notice_nonce, get_current_user_id() ) ) {
		return;
	}

	if ( function_exists( 'extrachill_set_notice' ) ) {
		$args             = array();
		$profile_edit_url = function_exists( 'extrachill_get_user_community_profile_edit_url' )
			? extrachill_get_user_community_profile_edit_url( get_current_user_id() )
			: '';
		if ( '' !== $profile_edit_url ) {
			$args['actions'] = array(
				array(
					'label' => __( 'Customize Your Profile', 'extrachill-users' ),
					'url'   => $profile_edit_url,
				),
			);
		}

		extrachill_set_notice(
			__( "You're in! Your Extra Chill account is ready. Set up your profile whenever you have time.", 'extrachill-users' ),
			'success',
			$args
		);
	}

	wp_safe_redirect( remove_query_arg( EC_USERS_ACCOUNT_CREATED_PARAM ) );
	exit;
}
add_action( 'template_redirect', 'ec_users_handle_account_created_notice', 1 );
