<?php
/**
 * Events Submit Ability
 *
 * Public-facing event submission.  Validates input, verifies Turnstile,
 * resolves contact info, and delegates to the existing
 * extrachill/submit-event ability (registered in
 * inc/Abilities/EventSubmissionAbilities.php).
 *
 * This ability is the branded entry-point that matches the
 * POST /event-submissions REST route. The canonical business logic
 * already lives in extrachill/submit-event.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_submit_ability' );

/**
 * Register extrachill/events-submit ability.
 */
function extrachill_events_register_submit_ability(): void {

	wp_register_ability(
		'extrachill/events-submit',
		array(
			'label'               => __( 'Submit Event', 'extrachill-events' ),
			'description'         => __( 'Process a public event submission. Validates input, verifies Turnstile, stores flyer, and queues for review.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'event_title', 'event_date' ),
				'properties' => array(
					'event_title'        => array(
						'type'        => 'string',
						'description' => 'Event title.',
					),
					'event_date'         => array(
						'type'        => 'string',
						'description' => 'Event date (YYYY-MM-DD).',
					),
					'event_time'         => array(
						'type'        => 'string',
						'description' => 'Event start time (HH:MM).',
					),
					'venue_name'         => array(
						'type'        => 'string',
						'description' => 'Venue name.',
					),
					'event_city'         => array(
						'type'        => 'string',
						'description' => 'City or region.',
					),
					'event_lineup'       => array(
						'type'        => 'string',
						'description' => 'Lineup or headliners.',
					),
					'event_link'         => array(
						'type'        => 'string',
						'description' => 'Ticket or info URL.',
					),
					'notes'              => array(
						'type'        => 'string',
						'description' => 'Additional details.',
					),
					'contact_name'       => array(
						'type'        => 'string',
						'description' => 'Submitter name (required for anonymous submissions).',
					),
					'contact_email'      => array(
						'type'        => 'string',
						'description' => 'Submitter email (required for anonymous submissions).',
					),
					'turnstile_response' => array(
						'type'        => 'string',
						'description' => 'Cloudflare Turnstile verification token.',
					),
					'system_prompt'      => array(
						'type'        => 'string',
						'description' => 'Custom system prompt for AI processing step.',
					),
					'flyer'              => array(
						'type'        => 'object',
						'description' => 'Uploaded flyer file data from $_FILES.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'message' => array( 'type' => 'string' ),
					'job_id'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_submit',
			'permission_callback' => '__return_true',
			'meta'                => array(
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

/**
 * Execute the events-submit ability.
 *
 * Delegates to the existing extrachill/submit-event ability which
 * owns the canonical Turnstile + DM workflow logic.
 *
 * @param array $input Submission data matching input_schema.
 * @return array|WP_Error Result with message and job_id, or error.
 */
function extrachill_events_ability_submit( array $input ): array|\WP_Error {
	$ability = wp_get_ability( 'extrachill/submit-event' );
	if ( ! $ability ) {
		return new \WP_Error(
			'ability_unavailable',
			__( 'Event submission is not available.', 'extrachill-events' ),
			array( 'status' => 500 )
		);
	}

	// Pass the full input through — extrachill/submit-event handles
	// sanitisation, Turnstile verification, contact resolution, and
	// DM workflow execution.
	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result;
}
