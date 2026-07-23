<?php
/**
 * REST route: POST /wp-json/extrachill/v1/contact/submit
 *
 * Thin REST wrapper for the extrachill/contact-submit ability.
 *
 * The underlying extrachill/contact-submit ability (registered in the
 * extrachill-contact plugin) owns all of the contact-submission logic:
 * Cloudflare Turnstile verification, input sanitisation, admin + user
 * email dispatch, and Sendy newsletter sync.
 *
 * Because the ability performs Turnstile verification itself, this route
 * does NOT verify Turnstile at the HTTP boundary — Turnstile tokens are
 * single-use, so verifying here and again in the ability would always fail
 * the second check. The token is declared as a request arg and passed
 * through to the ability, which is the single place that verifies it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_contact_submit_route' );

function extrachill_api_register_contact_submit_route() {
	register_rest_route(
		'extrachill/v1',
		'/contact/submit',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_contact_submit',
			// Turnstile is verified inside the extrachill/contact-submit
			// ability, not here. Tokens are single-use, so the route must
			// not also verify or the ability's check would always fail.
			'permission_callback' => '__return_true',
			'args'                => array(
				'name'               => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'email'              => array(
					'required'          => true,
					'type'              => 'string',
					'format'            => 'email',
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_email',
				),
				'subject'            => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message'            => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'turnstile_response' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
}

function extrachill_api_handle_contact_submit( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/contact-submit' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_not_found',
			__( 'Contact form processing unavailable.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute(
		array(
			'name'               => $request->get_param( 'name' ),
			'email'              => $request->get_param( 'email' ),
			'subject'            => $request->get_param( 'subject' ),
			'message'            => $request->get_param( 'message' ),
			'turnstile_response' => $request->get_param( 'turnstile_response' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
