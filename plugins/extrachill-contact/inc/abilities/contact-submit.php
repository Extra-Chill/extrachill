<?php
declare(strict_types=1);
/**
 * Ability: extrachill/contact-submit
 *
 * Process a contact form submission: validate Turnstile token,
 * send admin + user emails, and sync to Sendy.
 *
 * Canonical implementation — the REST route in extrachill-api
 * refactors to a thin shim that delegates here.
 *
 * @package ExtraChillContact
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_contact_register_submit_ability' );

/**
 * Register the contact-submit ability.
 */
function extrachill_contact_register_submit_ability(): void {

	wp_register_ability(
		'extrachill/contact-submit',
		array(
			'label'       => __( 'Submit Contact Form', 'extrachill-contact' ),
			'description' => __( 'Process a contact form submission with Turnstile verification, email notifications, and Sendy newsletter sync.', 'extrachill-contact' ),
			'category'    => 'extrachill-contact',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'name', 'email', 'subject', 'message', 'turnstile_response' ),
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => __( 'Sender full name.', 'extrachill-contact' ),
					),
					'email' => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'Sender email address.', 'extrachill-contact' ),
					),
					'subject' => array(
						'type'        => 'string',
						'description' => __( 'Message subject line.', 'extrachill-contact' ),
					),
					'message' => array(
						'type'        => 'string',
						'description' => __( 'Message body.', 'extrachill-contact' ),
					),
					'turnstile_response' => array(
						'type'        => 'string',
						'description' => __( 'Cloudflare Turnstile response token.', 'extrachill-contact' ),
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_contact_ability_submit',
			'permission_callback' => '__return_true',
			'meta' => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Handle a contact form submission.
 *
 * Validation (Turnstile, input sanitisation) lives here so the
 * ability is self-contained and the REST route can become a thin shim.
 *
 * @param array<string, mixed> $input Validated ability input.
 * @return array{success: bool, message: string}|WP_Error
 */
function extrachill_contact_ability_submit( array $input ): array|WP_Error {

	// ── Turnstile verification ──────────────────────────────────────────────
	if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
		return new WP_Error(
			'turnstile_missing',
			__( 'Security verification unavailable.', 'extrachill-contact' ),
			array( 'status' => 500 )
		);
	}

	$is_local     = defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE;
	$bypass       = $is_local || (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false );
	$turnstile    = isset( $input['turnstile_response'] ) ? (string) $input['turnstile_response'] : '';

	if ( ! $bypass && ( empty( $turnstile ) || ! ec_verify_turnstile_response( $turnstile ) ) ) {
		return new WP_Error(
			'turnstile_failed',
			__( 'Security verification failed. Please try again.', 'extrachill-contact' ),
			array( 'status' => 403 )
		);
	}

	// ── Sanitise inputs ─────────────────────────────────────────────────────
	$name    = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$email   = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$subject = sanitize_text_field( (string) ( $input['subject'] ?? '' ) );
	$message = sanitize_textarea_field( (string) ( $input['message'] ?? '' ) );

	if ( ! is_email( $email ) ) {
		return new WP_Error(
			'invalid_email',
			__( 'A valid email address is required.', 'extrachill-contact' ),
			array( 'status' => 400 )
		);
	}

	// ── Dispatch emails & Sendy sync ────────────────────────────────────────
	if ( ! function_exists( 'ec_contact_send_admin_email' ) ) {
		return new WP_Error(
			'contact_unavailable',
			__( 'Contact form processing unavailable.', 'extrachill-contact' ),
			array( 'status' => 500 )
		);
	}

	ec_contact_send_admin_email( $name, $email, $subject, $message );
	ec_contact_send_user_confirmation( $name, $email, $subject, $message );
	ec_contact_sync_to_sendy( $email );

	return array(
		'success' => true,
		'message' => __( 'Your message has been sent successfully. We\'ll get back to you soon.', 'extrachill-contact' ),
	);
}
