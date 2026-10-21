<?php
/**
 * Event submission endpoint — thin REST wrapper for extrachill/submit-event ability.
 *
 * Calls the underlying extrachill/submit-event ability directly. The legacy
 * extrachill/events-submit wrapper was a pure pass-through and has been
 * removed (see Extra-Chill/extrachill-events#104).
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_event_submission_route' );

/** Register the public event submission adapter. */
/**
 * Validate an optional term ID: empty, or a non-negative integer.
 *
 * @param mixed $value Raw request value.
 * @return bool
 */
function extrachill_api_validate_optional_term_id( $value ): bool {
	if ( null === $value || '' === $value ) {
		return true;
	}
	return is_numeric( $value ) && (int) $value == $value && (int) $value >= 0; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- "104506" must equal 104506.
}

function extrachill_api_register_event_submission_route() {
	// Captcha (Cloudflare Turnstile) is enforced here, at the human-facing
	// boundary. The underlying extrachill/submit-event ability is captcha-
	// free so it can be called from CLI, admin forms, or scheduled reruns
	// without fabricating a token.
	$turnstile_callback = function_exists( 'ec_turnstile_permission_callback' )
		? ec_turnstile_permission_callback()
		: '__return_true';

	register_rest_route(
		'extrachill/v1',
		'/event-submissions',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_event_submission',
			'permission_callback' => $turnstile_callback,
			'args'                => array(
				'event_title'        => array(
					'required' => true,
					'type'     => 'string',
				),
				'event_date'         => array(
					'required' => true,
					'type'     => 'string',
				),
				'event_time'         => array( 'type' => 'string' ),
				'venue_name'         => array( 'type' => 'string' ),
				// The form's hidden venue picker posts "" when the submitter types a
				// venue instead of choosing a suggestion; treat that as "no pick" (0).
				'venue_term_id'      => array(
					'description'       => 'Venue term ID chosen from suggestions, or empty for a free-text venue.',
					'validate_callback' => 'extrachill_api_validate_optional_term_id',
					'sanitize_callback' => 'absint',
				),
				'event_city'         => array( 'type' => 'string' ),
				'event_lineup'       => array( 'type' => 'string' ),
				'event_link'         => array( 'type' => 'string' ),
				'notes'              => array( 'type' => 'string' ),
				'contact_name'       => array( 'type' => 'string' ),
				'contact_email'      => array( 'type' => 'string' ),
				'turnstile_response' => array(
					'required' => false,
					'type'     => 'string',
				),
				'system_prompt'      => array(
					'required' => false,
					'type'     => 'string',
				),
			),
		)
	);
}

/**
 * Execute an admitted event submission through the owner ability.
 *
 * @param WP_REST_Request $request Admitted REST request.
 * @return WP_REST_Response|WP_Error Submission response.
 */
function extrachill_api_handle_event_submission( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/submit-event' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
	}

	$input = array(
		'event_title'   => sanitize_text_field( $request->get_param( 'event_title' ) ),
		'event_date'    => sanitize_text_field( $request->get_param( 'event_date' ) ),
		'event_time'    => sanitize_text_field( $request->get_param( 'event_time' ) ),
		'venue_name'    => sanitize_text_field( $request->get_param( 'venue_name' ) ),
		'venue_term_id' => absint( $request->get_param( 'venue_term_id' ) ),
		'event_city'    => sanitize_text_field( $request->get_param( 'event_city' ) ),
		'event_lineup'  => sanitize_text_field( $request->get_param( 'event_lineup' ) ),
		'event_link'    => esc_url_raw( $request->get_param( 'event_link' ) ),
		'notes'         => sanitize_textarea_field( $request->get_param( 'notes' ) ),
		'contact_name'  => sanitize_text_field( $request->get_param( 'contact_name' ) ),
		'contact_email' => sanitize_email( $request->get_param( 'contact_email' ) ),
		'system_prompt' => sanitize_textarea_field( $request->get_param( 'system_prompt' ) ),
	);

	// Pass through file upload data for flyer.
	$files = $request->get_file_params();
	if ( ! empty( $files['flyer'] ) && ! empty( $files['flyer']['tmp_name'] ) ) {
		$input['flyer'] = $files['flyer'];
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
