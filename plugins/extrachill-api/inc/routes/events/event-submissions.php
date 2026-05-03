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
	$ability = wp_get_ability( 'extrachill/events-submit' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
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
		'system_prompt'      => sanitize_textarea_field( $request->get_param( 'system_prompt' ) ),
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
