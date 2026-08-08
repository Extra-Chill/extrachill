<?php
/**
 * Ability: extrachill/contact-submit
 *
 * @package ExtraChillContact
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_contact_register_submit_ability' );

/** Register the canonical contact submission ability. */
function extrachill_contact_register_submit_ability(): void {
	$side_effect_schema = array(
		'type'       => 'object',
		'required'   => array( 'success', 'status', 'provider', 'optional', 'retryable', 'message' ),
		'properties' => array(
			'success'   => array( 'type' => 'boolean' ),
			'status'    => array( 'type' => 'string' ),
			'provider'  => array( 'type' => 'string' ),
			'retryable' => array( 'type' => 'boolean' ),
			'optional'  => array( 'type' => 'boolean' ),
			'message'   => array( 'type' => 'string' ),
		),
	);

	wp_register_ability(
		'extrachill/contact-submit',
		array(
			'label'               => __( 'Submit Contact Form', 'extrachill-contact' ),
			'description'         => __( 'Process a contact form submission with Turnstile verification, email notifications, and optional newsletter synchronization.', 'extrachill-contact' ),
			'category'            => 'extrachill-contact',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'name', 'email', 'subject', 'message', 'turnstile_response' ),
				'properties' => array(
					'name'               => array(
						'type'        => 'string',
						'description' => __( 'Sender full name.', 'extrachill-contact' ),
					),
					'email'              => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'Sender email address.', 'extrachill-contact' ),
					),
					'subject'            => array(
						'type'        => 'string',
						'description' => __( 'Message subject line.', 'extrachill-contact' ),
					),
					'message'            => array(
						'type'        => 'string',
						'description' => __( 'Message body.', 'extrachill-contact' ),
					),
					'turnstile_response' => array(
						'type'        => 'string',
						'description' => __( 'Cloudflare Turnstile response token.', 'extrachill-contact' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'required'   => array( 'success', 'message', 'submission_id', 'replayed', 'side_effects' ),
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'message'       => array( 'type' => 'string' ),
					'submission_id' => array( 'type' => 'string' ),
					'replayed'      => array( 'type' => 'boolean' ),
					'side_effects'  => array(
						'type'       => 'object',
						'properties' => array(
							'administrator_delivery' => $side_effect_schema,
							'confirmation_delivery'  => $side_effect_schema,
							'newsletter_sync'        => $side_effect_schema,
						),
					),
				),
			),
			'execute_callback'    => 'extrachill_contact_ability_submit',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Return a side effect that was intentionally not attempted.
 *
 * @param string $provider Provider identifier.
 * @param bool   $optional Whether the side effect is optional.
 * @return array<string, mixed>
 */
function extrachill_contact_skipped_side_effect( string $provider, bool $optional ): array {
	return array(
		'success'   => false,
		'status'    => 'skipped',
		'provider'  => $provider,
		'optional'  => $optional,
		'retryable' => true,
		'message'   => 'Not attempted because administrator delivery failed.',
	);
}

/**
 * Stable replay key for one normalized submission.
 *
 * The Turnstile token is deliberately excluded because tokens are single-use.
 *
 * @param array<string, string> $submission Normalized submission.
 */
function extrachill_contact_submission_id( array $submission ): string {
	return hash( 'sha256', wp_json_encode( $submission ) );
}

/**
 * Handle a contact form submission.
 *
 * @param array<string, mixed> $input Validated ability input.
 * @return array<string, mixed>|WP_Error
 */
function extrachill_contact_ability_submit( array $input ) {
	$name       = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$email      = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$subject    = sanitize_text_field( (string) ( $input['subject'] ?? '' ) );
	$message    = sanitize_textarea_field( (string) ( $input['message'] ?? '' ) );
	$submission = compact( 'name', 'email', 'subject', 'message' );
	$id         = extrachill_contact_submission_id( $submission );
	$cache_key  = 'submission_' . $id;
	$cached     = get_transient( 'extrachill_contact_' . $id );

	if ( is_array( $cached ) && ! empty( $cached['success'] ) ) {
		$cached['replayed'] = true;
		return $cached;
	}

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'extrachill-contact' ), array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
		return new WP_Error( 'turnstile_missing', __( 'Security verification unavailable.', 'extrachill-contact' ), array( 'status' => 500 ) );
	}

	$is_local  = defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE;
	$bypass    = $is_local || (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false );
	$turnstile = isset( $input['turnstile_response'] ) ? (string) $input['turnstile_response'] : '';
	if ( ! $bypass && ( empty( $turnstile ) || ! ec_verify_turnstile_response( $turnstile ) ) ) {
		return new WP_Error( 'turnstile_failed', __( 'Security verification failed. Please try again.', 'extrachill-contact' ), array( 'status' => 403 ) );
	}

	if ( ! wp_cache_add( $cache_key, 1, 'extrachill-contact', 30 ) ) {
		return new WP_Error(
			'submission_in_progress',
			__( 'This submission is already being processed.', 'extrachill-contact' ),
			array(
				'status'        => 409,
				'submission_id' => $id,
			)
		);
	}

	$administrator = ec_contact_send_admin_email( $name, $email, $subject, $message );
	if ( empty( $administrator['success'] ) ) {
		$side_effects = array(
			'administrator_delivery' => $administrator,
			'confirmation_delivery'  => extrachill_contact_skipped_side_effect( 'datamachine/send-email', false ),
			'newsletter_sync'        => extrachill_contact_skipped_side_effect( 'extrachill/subscribe', true ),
		);
		$result       = array(
			'success'       => false,
			'message'       => __( 'Your message could not be delivered. Please try again.', 'extrachill-contact' ),
			'submission_id' => $id,
			'replayed'      => is_array( $cached ),
			'side_effects'  => $side_effects,
		);
		set_transient( 'extrachill_contact_' . $id, $result, HOUR_IN_SECONDS );
		wp_cache_delete( $cache_key, 'extrachill-contact' );

		return new WP_Error(
			'contact_administrator_delivery_failed',
			$result['message'],
			array(
				'status'     => 502,
				'submission' => $result,
			)
		);
	}

	$result = array(
		'success'       => true,
		'message'       => __( 'Your message has been sent successfully. We\'ll get back to you soon.', 'extrachill-contact' ),
		'submission_id' => $id,
		'replayed'      => is_array( $cached ),
		'side_effects'  => array(
			'administrator_delivery' => $administrator,
			'confirmation_delivery'  => ec_contact_send_user_confirmation( $name, $email, $subject, $message ),
			'newsletter_sync'        => ec_contact_sync_to_sendy( $email ),
		),
	);

	set_transient( 'extrachill_contact_' . $id, $result, DAY_IN_SECONDS );
	wp_cache_delete( $cache_key, 'extrachill-contact' );

	return $result;
}
