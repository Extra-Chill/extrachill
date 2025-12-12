<?php
/**
 * Contact Form REST API Endpoints
 *
 * Handles form submission via REST API for the headless contact form.
 *
 * @package ExtraChillContact
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register REST API routes for contact form.
 */
function extrachill_contact_register_rest_routes() {
	register_rest_route( 'extrachill/v1', '/contact/submit', array(
		'methods'             => 'POST',
		'callback'            => 'extrachill_contact_handle_submission',
		'permission_callback' => '__return_true',
		'args'                => array(
			'name' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function( $value ) {
					return ! empty( trim( $value ) );
				},
			),
			'email' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => 'is_email',
			),
			'subject' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function( $value ) {
					$valid_subjects = array(
						'General Inquiry',
						'Partnership/Collaboration',
						'Shop/Store Support',
						'Technical Issue',
						'Other',
					);
					return in_array( $value, $valid_subjects, true );
				},
			),
			'message' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function( $value ) {
					return ! empty( trim( $value ) );
				},
			),
			'turnstile_response' => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}

/**
 * Handle contact form submission.
 */
function extrachill_contact_handle_submission( WP_REST_Request $request ) {
	// Verify nonce
	if ( ! wp_verify_nonce( $request->get_header( 'x_wp_nonce' ), 'wp_rest' ) ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'Invalid nonce.', 'extrachill-contact' ),
			array( 'status' => 403 )
		);
	}

	// Get sanitized data
	$name               = $request->get_param( 'name' );
	$email              = $request->get_param( 'email' );
	$subject            = $request->get_param( 'subject' );
	$message            = $request->get_param( 'message' );
	$turnstile_response = $request->get_param( 'turnstile_response' );

	// Validate Turnstile if site key is available
	if ( function_exists( 'ec_get_turnstile_site_key' ) && ec_get_turnstile_site_key() ) {
		if ( empty( $turnstile_response ) ) {
			return new WP_Error(
				'turnstile_required',
				__( 'Please complete the security verification.', 'extrachill-contact' ),
				array( 'status' => 400 )
			);
		}

		// Verify Turnstile response
		if ( function_exists( 'ec_verify_turnstile' ) ) {
			$verification = ec_verify_turnstile( $turnstile_response );
			if ( ! $verification['success'] ) {
				return new WP_Error(
					'turnstile_invalid',
					__( 'Security verification failed. Please try again.', 'extrachill-contact' ),
					array( 'status' => 400 )
				);
			}
		}
	}

	// Send admin notification email
	ec_contact_send_admin_email( $name, $email, $subject, $message );

	// Send user confirmation email
	ec_contact_send_user_confirmation( $name, $email, $subject, $message );

	// Handle newsletter subscription
	ec_contact_sync_to_sendy( $email );

	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => __( 'Your message has been sent successfully.', 'extrachill-contact' ),
		),
		200
	);
}

// Register REST routes
add_action( 'rest_api_init', 'extrachill_contact_register_rest_routes' );