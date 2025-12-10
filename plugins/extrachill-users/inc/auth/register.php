<?php
/**
 * Registration Handler
 *
 * Handles user registration via admin-post.php with EC_Redirect_Handler.
 * Creates users on community.extrachill.com via extrachill_create_community_user filter.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle registration form submission.
 *
 * Creates user on community.extrachill.com via extrachill_create_community_user filter,
 * processes roster invitations, subscribes to newsletter, and auto-logs in user.
 */
function extrachill_handle_registration() {
	$redirect = EC_Redirect_Handler::from_post( 'ec_registration' );

	$redirect->verify_nonce( 'extrachill_register_nonce_field', 'extrachill_register_nonce' );

	$username         = sanitize_user( wp_unslash( $_POST['extrachill_username'] ) );
	$email            = sanitize_email( wp_unslash( $_POST['extrachill_email'] ) );
	$password         = isset( $_POST['extrachill_password'] ) ? $_POST['extrachill_password'] : '';
	$password_confirm = isset( $_POST['extrachill_password_confirm'] ) ? $_POST['extrachill_password_confirm'] : '';

	$turnstile_response = isset( $_POST['cf-turnstile-response'] ) ? wp_unslash( $_POST['cf-turnstile-response'] ) : '';

	if ( empty( $turnstile_response ) ) {
		$redirect->error( __( 'Captcha verification required. Please complete the challenge and try again.', 'extrachill-users' ) );
	}

	if ( ! ec_verify_turnstile_response( $turnstile_response ) ) {
		$redirect->error( __( 'Captcha verification failed. Please try again.', 'extrachill-users' ) );
	}

	if ( $password !== $password_confirm ) {
		$redirect->error( __( 'Passwords do not match.', 'extrachill-users' ) );
	}

	if ( username_exists( $username ) || email_exists( $email ) ) {
		$redirect->error( __( 'An account already exists with this username or email.', 'extrachill-users' ) );
	}

	// Join flow requires artist or professional selection
	if ( isset( $_POST['from_join'] ) && $_POST['from_join'] === 'true' ) {
		if ( ! isset( $_POST['user_is_artist'] ) && ! isset( $_POST['user_is_professional'] ) ) {
			$redirect->error(
				__( 'To create your extrachill.link page, please select "I am a musician" or "I work in the music industry".', 'extrachill-artist-platform' )
			);
		}
	}

    $registration_page = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';

    if ( empty( $registration_page ) ) {
        $redirect->error( __( 'Registration source is missing. Please reload and try again.', 'extrachill-users' ) );
    }

    $registration_data = array(
        'username'             => $username,
        'password'             => $password,
        'email'                => $email,
        'user_is_artist'       => isset( $_POST['user_is_artist'] ),
        'user_is_professional' => isset( $_POST['user_is_professional'] ),
        'registration_page'    => $registration_page,
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

	if ( $invite_token_posted && $invite_artist_id_posted && function_exists( 'bp_get_pending_invitations' ) && function_exists( 'bp_add_artist_membership' ) && function_exists( 'bp_remove_pending_invitation' ) ) {
		$pending_invitations       = bp_get_pending_invitations( $invite_artist_id_posted );
		$valid_invite_data         = null;
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
			if ( bp_add_artist_membership( $user_id, $invite_artist_id_posted ) ) {
				bp_remove_pending_invitation( $invite_artist_id_posted, $valid_invite_id_for_removal );
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
 * Auto-login user after registration and redirect to appropriate destination.
 *
 * @param int                 $user_id                 User ID
 * @param EC_Redirect_Handler $redirect                Redirect handler instance
 * @param int|null            $processed_invite_artist_id Artist ID if roster invitation was processed
 * @param string              $success_redirect_url    Custom success redirect URL from block attribute
 */
function extrachill_auto_login_new_user( int $user_id, EC_Redirect_Handler $redirect, ?int $processed_invite_artist_id = null, string $success_redirect_url = '' ) {
	$user = get_user_by( 'id', $user_id );

	if ( ! $user ) {
		$redirect->error( __( 'Registration completed but login failed. Please try logging in.', 'extrachill-users' ) );
	}

	wp_set_current_user( $user_id, $user->user_login );
	wp_set_auth_cookie( $user_id, true );
	do_action( 'wp_login', $user->user_login, $user );

	if ( $processed_invite_artist_id ) {
		$artist_post = get_post( $processed_invite_artist_id );
		if ( $artist_post && 'artist_profile' === $artist_post->post_type ) {
			$redirect->redirect_to( get_permalink( $artist_post ) );
		}
	}

	if ( ! empty( $success_redirect_url ) ) {
		$redirect->redirect_to( $success_redirect_url );
	}

	$final_url = apply_filters( 'registration_redirect', home_url(), $user_id );
	$redirect->redirect_to( $final_url );
}
