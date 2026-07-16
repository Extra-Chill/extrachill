<?php
/**
 * Onboarding browser fallback and diagnostics.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Complete onboarding when JavaScript is unavailable.
 */
function extrachill_users_handle_onboarding_form() {
	$redirect = EC_Redirect_Handler::from_post( 'ec_onboarding' );

	if ( ! is_user_logged_in() ) {
		$redirect->error( __( 'Your session expired. Please log in and try again.', 'extrachill-users' ) );
	}

	$redirect->verify_nonce( 'onboarding_nonce', 'extrachill_complete_onboarding' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
	$data = array(
		'username'               => isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ), true ) : '',
		'user_is_artist'         => ! empty( $_POST['user_is_artist'] ),
		'user_is_professional'   => ! empty( $_POST['user_is_professional'] ),
		'local_scene'            => isset( $_POST['local_scene'] ) ? sanitize_title( wp_unslash( $_POST['local_scene'] ) ) : '',
		'local_scene_visibility' => isset( $_POST['local_scene_visibility'] ) ? sanitize_key( wp_unslash( $_POST['local_scene_visibility'] ) ) : 'public',
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$result = ec_complete_onboarding( get_current_user_id(), $data );
	if ( is_wp_error( $result ) ) {
		$redirect->error( $result->get_error_message() );
	}

	$redirect_url = isset( $result['redirect_url'] ) ? esc_url_raw( $result['redirect_url'] ) : '';
	$redirect->redirect_to( $redirect_url ? $redirect_url : home_url() );
}
add_action( 'admin_post_extrachill_complete_onboarding', 'extrachill_users_handle_onboarding_form' );
add_action( 'admin_post_nopriv_extrachill_complete_onboarding', 'extrachill_users_handle_onboarding_form' );

/**
 * Resolve a bounded client diagnostic to an analytics event.
 *
 * @param string $outcome    Client outcome.
 * @param string $error_code Stable client error code.
 * @return array|WP_Error Event descriptor or validation error.
 */
function extrachill_users_get_onboarding_client_event( string $outcome, string $error_code = '' ) {
	$allowed_errors = array(
		'auth_utils_missing',
		'form_missing',
		'username_empty',
		'username_too_short',
		'username_too_long',
		'username_invalid_chars',
		'local_scene_unselected',
		'role_required',
		'invalid_response',
		'response_rejected',
		'request_failed',
	);
	if ( 'client_failed' !== $outcome || ! in_array( $error_code, $allowed_errors, true ) ) {
		return new WP_Error( 'invalid_onboarding_diagnostic', __( 'Invalid onboarding diagnostic.', 'extrachill-users' ) );
	}

	return array(
		'event_type' => EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED,
		'event_data' => array( 'error_code' => 'client_' . $error_code ),
	);
}

/**
 * Record a privacy-safe onboarding browser event.
 */
function extrachill_users_onboarding_client_analytics() {
	check_ajax_referer( 'extrachill_onboarding_analytics', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( null, 403 );
	}

	$outcome    = isset( $_POST['outcome'] ) ? sanitize_key( wp_unslash( $_POST['outcome'] ) ) : '';
	$error_code = isset( $_POST['error_code'] ) ? sanitize_key( wp_unslash( $_POST['error_code'] ) ) : '';
	$event      = extrachill_users_get_onboarding_client_event( $outcome, $error_code );
	if ( is_wp_error( $event ) ) {
		wp_send_json_error( null, 400 );
	}

	ec_users_emit_onboarding_event( $event['event_type'], get_current_user_id(), $event['event_data'] );
	wp_send_json_success();
}
add_action( 'wp_ajax_extrachill_onboarding_analytics', 'extrachill_users_onboarding_client_analytics' );
