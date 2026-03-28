<?php
/**
 * Ability: extrachill/progress-story
 *
 * Evaluates whether a player's action semantically matches any of the available
 * story triggers and returns the destination step if so. This is pure game logic —
 * no AI involved. The AI game master decides WHEN to call this; the ability
 * handles the actual trigger matching.
 *
 * @package ExtraChillAIAdventure
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the ability category for AI Adventure.
 */
function extrachill_ai_adventure_register_ability_category() {
	wp_register_ability_category(
		'extrachill-ai-adventure',
		array(
			'label'       => __( 'AI Adventure', 'extrachill-ai-adventure' ),
			'description' => __( 'Game mechanics for AI-powered text adventures.', 'extrachill-ai-adventure' ),
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'extrachill_ai_adventure_register_ability_category' );

/**
 * Register the progress-story ability.
 */
function extrachill_ai_adventure_register_progress_story_ability() {
	wp_register_ability(
		'extrachill/progress-story',
		array(
			'label'               => __( 'Progress Story', 'extrachill-ai-adventure' ),
			'description'         => __( 'Evaluate whether a player action matches a story trigger and progress to the next step.', 'extrachill-ai-adventure' ),
			'category'            => 'extrachill-ai-adventure',
			'execute_callback'    => 'extrachill_ai_adventure_execute_progress_story',
			'permission_callback' => '__return_true',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'trigger_id'  => array(
						'type'        => 'string',
						'description' => 'The ID of the triggered story branch.',
					),
					'triggers'    => array(
						'type'        => 'array',
						'description' => 'Available triggers with id and destination.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'          => array( 'type' => 'string' ),
								'destination' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'required'   => array( 'trigger_id', 'triggers' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'progressed'    => array( 'type' => 'boolean' ),
					'next_step_id'  => array( 'type' => 'string' ),
					'trigger_id'    => array( 'type' => 'string' ),
				),
			),
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'extrachill_ai_adventure_register_progress_story_ability' );

/**
 * Execute the progress-story ability.
 *
 * Validates the trigger_id against the available triggers and returns the
 * destination step. The AI decides which trigger_id to pass based on its
 * understanding of the player's intent.
 *
 * @param array $input {
 *     @type string $trigger_id  The trigger ID the AI believes was activated.
 *     @type array  $triggers    Available triggers with 'id' and 'destination'.
 * }
 * @return array|WP_Error Result with progressed status and next step ID.
 */
function extrachill_ai_adventure_execute_progress_story( $input ) {
	$trigger_id = $input['trigger_id'] ?? '';
	$triggers   = $input['triggers'] ?? array();

	if ( empty( $trigger_id ) ) {
		return new WP_Error(
			'missing_trigger_id',
			__( 'No trigger_id provided.', 'extrachill-ai-adventure' )
		);
	}

	if ( empty( $triggers ) ) {
		return new WP_Error(
			'no_triggers',
			__( 'No triggers available for this step.', 'extrachill-ai-adventure' )
		);
	}

	// Find the matching trigger.
	foreach ( $triggers as $trigger ) {
		if ( isset( $trigger['id'] ) && $trigger['id'] === $trigger_id ) {
			$destination = $trigger['destination'] ?? '';

			if ( empty( $destination ) ) {
				return new WP_Error(
					'no_destination',
					__( 'Trigger has no destination step.', 'extrachill-ai-adventure' )
				);
			}

			return array(
				'progressed'   => true,
				'next_step_id' => $destination,
				'trigger_id'   => $trigger_id,
			);
		}
	}

	// Trigger ID not found in available triggers.
	return array(
		'progressed'   => false,
		'next_step_id' => '',
		'trigger_id'   => $trigger_id,
	);
}
