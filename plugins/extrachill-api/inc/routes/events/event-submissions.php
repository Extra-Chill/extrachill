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

function extrachill_api_register_event_submission_route() {
	// Captcha (Cloudflare Turnstile) is enforced here, at the human-facing
	// boundary. The underlying extrachill/submit-event ability is captcha-
	// free so it can be called from CLI, admin forms, or scheduled reruns
	// without fabricating a token.
	$turnstile_callback = function_exists( 'ec_turnstile_permission_callback' )
		? ec_turnstile_permission_callback()
		: '__return_true';

	register_rest_route( 'extrachill/v1', '/event-submissions', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_handle_event_submission',
		'permission_callback' => $turnstile_callback,
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
	$ability = wp_get_ability( 'extrachill/submit-event' );
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
