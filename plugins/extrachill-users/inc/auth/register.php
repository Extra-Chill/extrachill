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

	$email            = sanitize_email( wp_unslash( $_POST['extrachill_email'] ) );
	$password         = isset( $_POST['extrachill_password'] ) ? wp_unslash( $_POST['extrachill_password'] ) : '';
	$password_confirm = isset( $_POST['extrachill_password_confirm'] ) ? wp_unslash( $_POST['extrachill_password_confirm'] ) : '';

	$is_local_environment = defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local';
	$turnstile_bypass     = $is_local_environment || (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false );
	$turnstile_response   = isset( $_POST['cf-turnstile-response'] ) ? wp_unslash( $_POST['cf-turnstile-response'] ) : '';

	if ( ! $turnstile_bypass ) {
		if ( empty( $turnstile_response ) ) {
			$redirect->error( __( 'Captcha verification required. Please complete the challenge and try again.', 'extrachill-users' ) );
		}

		if ( ! ec_verify_turnstile_response( $turnstile_response ) ) {
			$redirect->error( __( 'Captcha verification failed. Please try again.', 'extrachill-users' ) );
		}
	}

	if ( $password !== $password_confirm ) {
		$redirect->error( __( 'Passwords do not match.', 'extrachill-users' ) );
	}

	if ( strlen( $password ) < 8 ) {
		$redirect->error( __( 'Password must be at least 8 characters.', 'extrachill-users' ) );
	}

	if ( email_exists( $email ) ) {
		// Use generic message to prevent email enumeration.
		$redirect->error( __( 'Registration could not be completed. Please try again or contact support.', 'extrachill-users' ) );
	}

	$registration_page = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';

	if ( empty( $registration_page ) ) {
		$redirect->error( __( 'Registration source is missing. Please reload and try again.', 'extrachill-users' ) );
	}

	$from_join = isset( $_POST['from_join'] ) && 'true' === $_POST['from_join'];

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
		$redirect->error( sprintf( __( 'Registration failed: %s', 'extrachill-users' ), $error_messages ) );
	}

	if ( ! $user_id ) {
		$redirect->error( __( 'Registration failed. Please try again or contact support.', 'extrachill-users' ) );
	}

	update_user_meta( $user_id, 'registration_timestamp', current_time( 'mysql' ) );

	if ( function_exists( 'extrachill_multisite_subscribe' ) ) {
		$sync_result = extrachill_multisite_subscribe( $email, 'registration' );
		if ( ! $sync_result['success'] ) {
			error_log( 'Registration newsletter subscription failed: ' . $sync_result['message'] );
		}
	}

	$processed_invite_artist_id = null;
	$invite_token_posted        = isset( $_POST['invite_token'] ) ? sanitize_text_field( wp_unslash( $_POST['invite_token'] ) ) : null;
	$invite_artist_id_posted    = isset( $_POST['invite_artist_id'] ) ? absint( $_POST['invite_artist_id'] ) : null;

	if ( $invite_token_posted && $invite_artist_id_posted && function_exists( 'ec_get_pending_invitations' ) && function_exists( 'ec_add_artist_membership' ) && function_exists( 'ec_remove_pending_invitation' ) ) {
		$pending_invitations         = ec_get_pending_invitations( $invite_artist_id_posted );
		$valid_invite_data           = null;
		$valid_invite_id_for_removal = null;

		foreach ( $pending_invitations as $invite ) {
			if ( isset( $invite['token'] ) && $invite['token'] === $invite_token_posted &&
				isset( $invite['email'] ) && strtolower( $invite['email'] ) === strtolower( $email ) &&
				isset( $invite['status'] ) && 'invited_new_user' === $invite['status'] ) {
				$valid_invite_data           = $invite;
				$valid_invite_id_for_removal = $invite['id'];
				break;
			}
		}

		if ( $valid_invite_data ) {
			if ( ec_add_artist_membership( $user_id, $invite_artist_id_posted ) ) {
				ec_remove_pending_invitation( $invite_artist_id_posted, $valid_invite_id_for_removal );
				$processed_invite_artist_id = $invite_artist_id_posted;
			}
		}
	}

	$success_redirect_url = isset( $_POST['success_redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['success_redirect_url'] ) ) : '';

	extrachill_auto_login_new_user( $user_id, $redirect, $processed_invite_artist_id, $success_redirect_url );
}
add_action( 'admin_post_nopriv_extrachill_register_user', 'extrachill_handle_registration' );
add_action( 'admin_post_extrachill_register_user', 'extrachill_handle_registration' );

/**
 * Auto-login user after registration and redirect to onboarding.
 *
 * @param int                 $user_id                    User ID.
 * @param EC_Redirect_Handler $redirect                   Redirect handler instance.
 * @param int|null            $processed_invite_artist_id Artist ID if roster invitation was processed.
 * @param string              $success_redirect_url       Custom success redirect URL from block attribute.
 */
function extrachill_auto_login_new_user( int $user_id, EC_Redirect_Handler $redirect, ?int $processed_invite_artist_id = null, string $success_redirect_url = '' ) {
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
		if ( $artist_post && 'artist_profile' === $artist_post->post_type ) {
			$final_redirect_url = get_permalink( $artist_post );
		}
	}

	if ( empty( $final_redirect_url ) && ! empty( $success_redirect_url ) ) {
		$final_redirect_url = $success_redirect_url;
	}

	if ( ! empty( $final_redirect_url ) ) {
		update_user_meta( $user_id, 'onboarding_redirect_url', $final_redirect_url );
	}

	$onboarding_url = function_exists( 'ec_get_site_url' )
		? ec_get_site_url( 'community' ) . '/onboarding/'
		: home_url( '/onboarding/' );

	$redirect->redirect_to( $onboarding_url );
}
