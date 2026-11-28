<?php
/**
 * Event Schema Ability
 *
 * Non-REST ability over the public `ec_seo_get_event_schema()` seam so other
 * plugins (the partner events payload in extrachill-events) can compose the
 * exact Event entity our own event pages emit instead of rebuilding it.
 *
 * @package ExtraChill\SEO\Abilities
 */

namespace ExtraChill\SEO\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the `extrachill/event-schema` ability.
 */
function register_event_schema_ability(): void {
	wp_register_ability(
		'extrachill/event-schema',
		array(
			'label'               => __( 'Get Event Schema', 'extrachill-seo' ),
			'description'         => __( 'Return the standalone Schema.org Event entity (with @context) that a published event page emits.', 'extrachill-seo' ),
			'category'            => 'extrachill-seo',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Published data_machine_events post ID on the events site.',
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => 'Schema.org Event entity including @context.',
			),
			'execute_callback'    => __NAMESPACE__ . '\\execute_event_schema',
			// Published event pages already emit this entity publicly.
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_event_schema_ability' );

/**
 * Execute the event schema ability.
 *
 * @param array $input Ability input with `post_id`.
 * @return array|\WP_Error Event entity or not-found error.
 */
function execute_event_schema( array $input ) {
	$schema = function_exists( 'ExtraChill\\SEO\\Schema\\ec_seo_get_event_schema' )
		? \ExtraChill\SEO\Schema\ec_seo_get_event_schema( (int) ( $input['post_id'] ?? 0 ) )
		: null;

	if ( null === $schema ) {
		return new \WP_Error( 'extrachill_event_schema_unavailable', __( 'No event schema is available for that post.', 'extrachill-seo' ), array( 'status' => 404 ) );
	}

	return $schema;
}
