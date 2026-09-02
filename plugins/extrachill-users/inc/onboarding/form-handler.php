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

/**
 * Record an unmatched Local Scene search as a zero-result search event.
 *
 * Local Scene is optional during onboarding. When a member types a scene we have
 * no location term for, the autocomplete returns nothing to click, so the form
 * now submits without a scene rather than stranding them (issue #380). The text
 * they typed is real, unmet demand for a scene, so it is worth keeping.
 *
 * This routes through the existing `search` contract rather than the onboarding
 * one on purpose. The onboarding payload contract is explicitly limited to
 * non-PII fields and forbids free-form user input and Local Scene names, so a
 * raw term cannot travel that path. `search` already owns search_term and
 * result_count, already passes through the server-side attack classifier and
 * bot stamping in extrachill-analytics, and already feeds search-gaps
 * reporting. Reusing it means unmet Local Scene demand becomes queryable with
 * no new event type and no new storage.
 *
 * The explicit `source` is preserved by the analytics layer, which only derives
 * a source when the caller omits one, so these rows bucket as their own surface
 * instead of being misattributed to nav or archive search.
 */
function extrachill_users_onboarding_local_scene_gap() {
	check_ajax_referer( 'extrachill_onboarding_local_scene_gap', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( null, 403 );
	}

	$search_term = isset( $_POST['search_term'] ) ? sanitize_text_field( wp_unslash( $_POST['search_term'] ) ) : '';
	$search_term = trim( $search_term );
	if ( '' === $search_term || strlen( $search_term ) > 100 ) {
		wp_send_json_error( null, 400 );
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/track-analytics-event' ) : null;
	if ( ! $ability ) {
		wp_send_json_error( null, 500 );
	}

	$referer = wp_get_referer();

	$ability->execute(
		array(
			'event_type' => EC_ANALYTICS_EVENT_SEARCH,
			'event_data' => array(
				'search_term'  => $search_term,
				'result_count' => 0,
				'source'       => 'onboarding_local_scene',
			),
			'source_url' => $referer ? $referer : '',
		)
	);

	wp_send_json_success();
}
add_action( 'wp_ajax_extrachill_onboarding_local_scene_gap', 'extrachill_users_onboarding_local_scene_gap' );
