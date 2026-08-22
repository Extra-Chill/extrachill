<?php
/**
 * Registration Handler
 *
 * Handles user registration via admin-post.php with EC_Redirect_Handler.
 * Creates users on community.extrachill.com via extrachill_create_community_user filter.
 * Redirects to /onboarding for username selection and artist/professional flags.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT  = 5;
const EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW = 15 * MINUTE_IN_SECONDS;
const EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP = 'extrachill-users-registration-admission';

/**
 * Get the registration limiter key for the current requester and window.
 *
 * The custom cache group is registered as global so all sites in the network
 * share one IP budget. Network ID remains in the key to isolate separate
 * networks when a cache backend serves more than one.
 *
 * @param int|null $now Optional Unix timestamp. Defaults to now.
 * @return string Cache key, or an empty string when the requester IP is unavailable.
 */
function extrachill_users_registration_attempt_key( ?int $now = null ): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( '' === $ip ) {
		return '';
	}

	$now       = null === $now ? time() : $now;
	$window_id = intdiv( $now, EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW );
	$ip_hash   = substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );

	return sprintf( 'network_%d_window_%d_ip_%s', get_current_network_id(), $window_id, $ip_hash );
}

/**
 * Build a stable registration rate-limit error.
 *
 * @param int $expires_at Fixed-window expiry timestamp.
 * @return WP_Error Rate-limit response with REST-safe metadata.
 */
function extrachill_users_registration_rate_limit_error( int $expires_at ): WP_Error {
	return new WP_Error(
		'registration_rate_limited',
		__( 'Too many registration attempts. Please try again later.', 'extrachill-users' ),
		array(
			'status'      => 429,
			'retry_after' => max( 1, $expires_at - time() ),
			'expires_at'  => $expires_at,
		)
	);
}

/**
 * Atomically increment and admit one registration attempt.
 *
 * `wp_cache_add()` admits the first request and `wp_cache_incr()` atomically
 * admits every concurrent follower without a separate read/write race. Redis
 * preserves the original TTL on increment. Retaining each addressed key for
 * two windows prevents it from expiring between a failed add and increment,
 * while the window in the key keeps admission accounting fixed and bounded.
 *
 * @param string $key        Network-global cache key.
 * @param int    $expires_at Fixed-window expiry timestamp.
 * @return true|WP_Error True when admitted, otherwise a stable error.
 */
function extrachill_users_increment_registration_attempt( string $key, int $expires_at ) {
	wp_cache_add_global_groups( EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP );

	$ttl = 2 * EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW;
	if ( wp_cache_add( $key, 1, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP, $ttl ) ) {
		$count = 1;
	} else {
		$count = wp_cache_incr( $key, 1, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP );
	}

	if ( false === $count || ! is_numeric( $count ) ) {
		return new WP_Error(
			'registration_limiter_unavailable',
			__( 'Registration is temporarily unavailable. Please try again.', 'extrachill-users' ),
			array( 'status' => 503 )
		);
	}

	return (int) $count > EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT
		? extrachill_users_registration_rate_limit_error( $expires_at )
		: true;
}

/**
 * Admit the current requester through the network-wide registration limiter.
 *
 * @return true|WP_Error True when admitted, otherwise a fail-closed error.
 */
function extrachill_users_admit_registration_attempt() {
	$key = extrachill_users_registration_attempt_key();
	if ( '' === $key
		|| ! function_exists( 'wp_using_ext_object_cache' )
		|| ! wp_using_ext_object_cache()
		|| ! function_exists( 'wp_cache_add' )
		|| ! function_exists( 'wp_cache_incr' )
		|| ! function_exists( 'wp_cache_add_global_groups' )
	) {
		return new WP_Error(
			'registration_limiter_unavailable',
			__( 'Registration is temporarily unavailable. Please try again.', 'extrachill-users' ),
			array( 'status' => 503 )
		);
	}

	$now        = time();
	$expires_at = ( intdiv( $now, EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW ) + 1 ) * EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW;

	return extrachill_users_increment_registration_attempt( $key, $expires_at );
}

/**
 * Apply shared anti-abuse policy to branded password registration.
 *
 * @param string $email            Sanitized registration email.
 * @param string $turnstile_token  Turnstile response token.
 * @return true|WP_Error True when registration may continue.
 */
function extrachill_users_validate_password_registration( string $email, string $turnstile_token ) {
	/**
	 * Filter the Turnstile verifier used by password registration.
	 *
	 * Production uses Extra Chill Network's verifier. Tests can substitute a
	 * deterministic callable without making external Cloudflare requests.
	 *
	 * @param callable(string):(true|WP_Error)|string $verifier Turnstile verifier callable.
	 */
	$turnstile_verifier = apply_filters( 'extrachill_users_registration_turnstile_verifier', 'ec_turnstile_check_request' );
	if ( ! is_callable( $turnstile_verifier ) ) {
		return new WP_Error(
			'turnstile_missing',
			__( 'Security verification unavailable.', 'extrachill-users' ),
			array( 'status' => 500 )
		);
	}

	$turnstile_check = call_user_func( $turnstile_verifier, $turnstile_token );
	if ( is_wp_error( $turnstile_check ) ) {
		return $turnstile_check;
	}

	/**
	 * Filter the atomic registration admission callable.
	 *
	 * Tests may substitute a deterministic concurrency seam. Production uses
	 * the network-global persistent-object-cache implementation above.
	 *
	 * @param callable $admitter Atomic admission callable.
	 */
	$admitter = apply_filters( 'extrachill_users_registration_admitter', 'extrachill_users_admit_registration_attempt' );
	if ( ! is_callable( $admitter ) ) {
		return new WP_Error(
			'registration_limiter_unavailable',
			__( 'Registration is temporarily unavailable. Please try again.', 'extrachill-users' ),
			array( 'status' => 503 )
		);
	}

	$admission = call_user_func( $admitter );
	if ( is_wp_error( $admission ) ) {
		return $admission;
	}

	if ( is_email( $email ) && is_email_address_unsafe( $email ) ) {
		return new WP_Error(
			'unsafe_email',
			__( 'That email address is not allowed. Please use another email provider.', 'extrachill-users' ),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Validate the anti-abuse policy fields from a registration form request.
 *
 * @param array $request Form request values.
 * @return true|WP_Error True when the form request may continue.
 */
function extrachill_users_validate_registration_form_request( array $request ) {
	$email = isset( $request['extrachill_email'] ) ? sanitize_email( wp_unslash( $request['extrachill_email'] ) ) : '';
	$token = isset( $request['cf-turnstile-response'] ) ? wp_unslash( $request['cf-turnstile-response'] ) : '';

	return extrachill_users_validate_password_registration( $email, $token );
}

/**
 * Handle registration form submission.
 *
 * Creates user on community.extrachill.com via extrachill_create_community_user filter,
 * processes roster invitations, subscribes to newsletter, auto-logs in user,
 * and redirects to onboarding.
 */
function extrachill_handle_registration() {
	$redirect = EC_Redirect_Handler::from_post( 'ec_registration' );

	$redirect->verify_nonce( 'extrachill_register_nonce_field', 'extrachill_register_nonce' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above through EC_Redirect_Handler.
	$email              = sanitize_email( wp_unslash( $_POST['extrachill_email'] ) );
	$password           = isset( $_POST['extrachill_password'] ) ? wp_unslash( $_POST['extrachill_password'] ) : '';
	$password_confirm   = isset( $_POST['extrachill_password_confirm'] ) ? wp_unslash( $_POST['extrachill_password_confirm'] ) : '';
	$newsletter_consent = isset( $_POST['newsletter_consent'] ) && '1' === (string) wp_unslash( $_POST['newsletter_consent'] );

	$check = extrachill_users_validate_registration_form_request( $_POST );
	if ( is_wp_error( $check ) ) {
		$redirect->error( $check->get_error_message() );
	}

	if ( $password !== $password_confirm ) {
		$redirect->error( __( 'Passwords do not match.', 'extrachill-users' ) );
	}

	$password_validation = extrachill_users_validate_password( $password );
	if ( is_wp_error( $password_validation ) ) {
		$redirect->error( $password_validation->get_error_message() );
	}

	if ( email_exists( $email ) ) {
		// Use generic message to prevent email enumeration.
		$redirect->error( __( 'Registration could not be completed. Please try again or contact support.', 'extrachill-users' ) );
	}

	$registration_page = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';

	if ( empty( $registration_page ) ) {
		$redirect->error( __( 'Registration source is missing. Please reload and try again.', 'extrachill-users' ) );
	}

	$from_join               = isset( $_POST['from_join'] ) && 'true' === $_POST['from_join'];
	$invite_token_posted     = isset( $_POST['invite_token'] ) ? sanitize_text_field( wp_unslash( $_POST['invite_token'] ) ) : '';
	$invite_artist_id_posted = isset( $_POST['invite_artist_id'] ) ? absint( $_POST['invite_artist_id'] ) : 0;
	$has_artist_invitation   = false;
	if ( $invite_token_posted || $invite_artist_id_posted ) {
		$validation = ec_users_request_artist_invitation( $email, $invite_token_posted, $invite_artist_id_posted );
		if ( is_wp_error( $validation ) ) {
			$redirect->error( $validation->get_error_message() );
		}
		$has_artist_invitation = true;
	}

	$username = function_exists( 'ec_generate_username_from_email' )
		? ec_generate_username_from_email( $email )
		: 'user' . wp_rand( 10000, 99999 );

	$registration_data = array(
		'username'          => $username,
		'password'          => $password,
		'email'             => $email,
		'registration_page' => $registration_page,
		'from_join'         => $from_join,
	);

	$user_id = apply_filters( 'extrachill_create_community_user', false, $registration_data );

	if ( is_wp_error( $user_id ) ) {
		$error_messages = implode( ', ', $user_id->get_error_messages() );
		/* translators: %s: registration failure messages. */
		$redirect->error( sprintf( __( 'Registration failed: %s', 'extrachill-users' ), $error_messages ) );
	}

	if ( ! $user_id ) {
		$redirect->error( __( 'Registration failed. Please try again or contact support.', 'extrachill-users' ) );
	}

	update_user_meta( $user_id, 'registration_timestamp', current_time( 'mysql' ) );

	extrachill_users_record_registration_newsletter_consent( $user_id, $email, $newsletter_consent, 'web', 'standard', $registration_page );

	$processed_invite_artist_id = null;
	$invitation_outcome         = array();
	if ( $has_artist_invitation ) {
		$acceptance = ec_users_request_artist_invitation( $email, $invite_token_posted, $invite_artist_id_posted, $user_id );
		if ( is_wp_error( $acceptance ) ) {
			$invitation_outcome = ec_users_classify_artist_invitation_error( $acceptance );
		} else {
			$processed_invite_artist_id = $invite_artist_id_posted;
		}
	}

	$success_redirect_url = isset( $_POST['success_redirect_url'] ) ? wp_unslash( $_POST['success_redirect_url'] ) : '';
	if ( ! function_exists( 'ec_users_is_valid_return_to_url' )
		|| ! ec_users_is_valid_return_to_url( $success_redirect_url ) ) {
		$success_redirect_url = '';
	} else {
		$success_redirect_url = esc_url_raw( $success_redirect_url );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	extrachill_auto_login_new_user( $user_id, $redirect, $processed_invite_artist_id, $success_redirect_url, $invitation_outcome );
}
add_action( 'admin_post_nopriv_extrachill_register_user', 'extrachill_handle_registration' );
add_action( 'admin_post_extrachill_register_user', 'extrachill_handle_registration' );

/**
 * Auto-login user after registration and resume the requested destination.
 *
 * @param int                 $user_id                    User ID.
 * @param EC_Redirect_Handler $redirect                   Redirect handler instance.
 * @param int|null            $processed_invite_artist_id Artist ID if roster invitation was processed.
 * @param string              $success_redirect_url       Custom success redirect URL from block attribute.
 * @param array               $invitation_outcome         Classified invitation failure after account creation.
 */
function extrachill_auto_login_new_user( int $user_id, EC_Redirect_Handler $redirect, ?int $processed_invite_artist_id = null, string $success_redirect_url = '', array $invitation_outcome = array() ) {
	$user = get_user_by( 'id', $user_id );

	if ( ! $user ) {
		$redirect->error( __( 'Registration completed but login failed. Please try logging in.', 'extrachill-users' ) );
	}

	wp_set_current_user( $user_id, $user->user_login );
	wp_set_auth_cookie( $user_id, false );
	do_action( 'wp_login', $user->user_login, $user );

	$final_redirect_url = '';

	if ( $processed_invite_artist_id ) {
		$artist_post = get_post( $processed_invite_artist_id );
		if ( $artist_post instanceof WP_Post && 'artist_profile' === $artist_post->post_type ) {
			$final_redirect_url = get_permalink( $artist_post );
		}
	}

	if ( empty( $final_redirect_url ) && ! empty( $success_redirect_url ) ) {
		$final_redirect_url = $success_redirect_url;
	}

	$from_join = function_exists( 'ec_is_onboarding_from_join' ) && ec_is_onboarding_from_join( $user_id );
	if ( $from_join && ! empty( $final_redirect_url ) ) {
		update_user_meta( $user_id, 'onboarding_redirect_url', $final_redirect_url );
	}

	$account_created_token = ec_users_create_account_created_token( $user_id );
	$redirect_url          = ec_users_post_registration_redirect_url( $from_join, false, $final_redirect_url, $account_created_token );
	if ( $invitation_outcome ) {
		$query_args = array( 'artist_invitation' => $invitation_outcome['status'] );
		if ( ! empty( $invitation_outcome['error'] ) ) {
			$query_args['artist_invitation_error']        = $invitation_outcome['error']['code'];
			$query_args['artist_invitation_error_status'] = $invitation_outcome['error']['status'];
		}
		$redirect_url = add_query_arg( $query_args, $redirect_url );
	}

	$redirect->redirect_to( $redirect_url );
}
