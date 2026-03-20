<?php
/**
 * Event submission endpoint — thin REST wrapper for extrachill/submit-event ability.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_event_submission_route' );

function extrachill_api_register_event_submission_route() {
	register_rest_route( 'extrachill/v1', '/event-submissions', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_handle_event_submission',
		'permission_callback' => '__return_true',
		'args'                => array(
			'flow_id'            => array(
				'required' => false,
				'type'     => 'integer',
			),
			'turnstile_response' => array(
				'required' => false,
				'type'     => 'string',
			),
			'system_prompt'      => array(
				'required' => false,
				'type'     => 'string',
			),
		),
	) );
}

function extrachill_api_handle_event_submission( WP_REST_Request $request ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/submit-event' ) : null;
	if ( ! $ability ) {
		return new WP_Error(
			'ability_unavailable',
			__( 'Event submission is not available.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$input = array(
		'event_title'        => sanitize_text_field( $request->get_param( 'event_title' ) ),
		'event_date'         => sanitize_text_field( $request->get_param( 'event_date' ) ),
		'event_time'         => sanitize_text_field( $request->get_param( 'event_time' ) ),
		'venue_name'         => sanitize_text_field( $request->get_param( 'venue_name' ) ),
		'event_city'         => sanitize_text_field( $request->get_param( 'event_city' ) ),
		'event_lineup'       => sanitize_text_field( $request->get_param( 'event_lineup' ) ),
		'event_link'         => esc_url_raw( $request->get_param( 'event_link' ) ),
		'notes'              => sanitize_textarea_field( $request->get_param( 'notes' ) ),
		'contact_name'       => sanitize_text_field( $request->get_param( 'contact_name' ) ),
		'contact_email'      => sanitize_email( $request->get_param( 'contact_email' ) ),
		'turnstile_response' => sanitize_text_field( $request->get_param( 'turnstile_response' ) ),
		'flow_id'            => absint( $request->get_param( 'flow_id' ) ),
		'system_prompt'      => sanitize_textarea_field( $request->get_param( 'system_prompt' ) ),
	);

	// Pass through file upload data for flyer.
	$files = $request->get_file_params();
	if ( ! empty( $files['flyer'] ) && ! empty( $files['flyer']['tmp_name'] ) ) {
		$input['flyer'] = $files['flyer'];
	}

	$result = $ability->execute( $input );

	if ( isset( $result['error'] ) ) {
		$status = 400;

		// Map specific errors to appropriate HTTP status codes.
		$error_message = $result['error'];
		if ( str_contains( $error_message, 'Security verification' ) ) {
			$status = 403;
		} elseif ( str_contains( $error_message, 'unavailable' ) || str_contains( $error_message, 'Scheduler' ) ) {
			$status = 500;
		} elseif ( str_contains( $error_message, 'not found' ) ) {
			$status = 404;
		}

		return new WP_Error( 'submission_failed', $error_message, array( 'status' => $status ) );
	}

	return rest_ensure_response( $result );
}
