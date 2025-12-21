<?php
/**
 * REST route: POST /wp-json/extrachill/v1/contact/submit
 *
 * Contact form submission endpoint with Turnstile verification,
 * email notifications, and Sendy newsletter integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_contact_submit_route' );

function extrachill_api_register_contact_submit_route() {
	register_rest_route( 'extrachill/v1', '/contact/submit', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_handle_contact_submit',
		'permission_callback' => '__return_true',
		'args'                => array(
			'name' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'email' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => 'is_email',
				'sanitize_callback' => 'sanitize_email',
			),
			'subject' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'message' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'turnstile_response' => array(
				'required' => true,
				'type'     => 'string',
			),
		),
	) );
}

function extrachill_api_handle_contact_submit( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
		return new WP_Error(
			'turnstile_missing',
			__( 'Security verification unavailable.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$is_local_environment = defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local';
	$turnstile_bypass     = $is_local_environment || (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false );

	$turnstile_response = $request->get_param( 'turnstile_response' );
	if ( ! $turnstile_bypass && ( empty( $turnstile_response ) || ! ec_verify_turnstile_response( $turnstile_response ) ) ) {
		return new WP_Error(
			'turnstile_failed',
			__( 'Security verification failed. Please try again.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	$name    = $request->get_param( 'name' );
	$email   = $request->get_param( 'email' );
	$subject = $request->get_param( 'subject' );
	$message = $request->get_param( 'message' );

	if ( ! function_exists( 'ec_contact_send_admin_email' ) ) {
		return new WP_Error(
			'contact_unavailable',
			__( 'Contact form processing unavailable.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	ec_contact_send_admin_email( $name, $email, $subject, $message );
	ec_contact_send_user_confirmation( $name, $email, $subject, $message );
	ec_contact_sync_to_sendy( $email );

	return rest_ensure_response( array(
		'success' => true,
		'message' => __( 'Your message has been sent successfully. We\'ll get back to you soon.', 'extrachill-api' ),
	) );
}
