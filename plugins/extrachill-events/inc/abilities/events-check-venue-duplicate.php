<?php
/**
 * Events Check Venue Duplicate Ability
 *
 * Checks for existing venues matching a given name.  Used by the
 * event submission form to warn about potential duplicate venues
 * before creation.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_check_venue_duplicate_ability' );

/**
 * Register extrachill/events-check-venue-duplicate ability.
 */
function extrachill_events_register_check_venue_duplicate_ability(): void {

	wp_register_ability(
		'extrachill/events-check-venue-duplicate',
		array(
			'label'               => __( 'Check Venue Duplicate', 'extrachill-events' ),
			'description'         => __( 'Checks for existing venues matching a given name. Returns matching venue records for duplicate detection.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'name' ),
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'Venue name to check.',
					),
					'city' => array(
						'type'        => 'string',
						'description' => 'City for more accurate matching.',
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer' ),
						'name'      => array( 'type' => 'string' ),
						'slug'      => array( 'type' => 'string' ),
						'address'   => array( 'type' => 'string' ),
						'city'      => array( 'type' => 'string' ),
						'state'     => array( 'type' => 'string' ),
						'country'   => array( 'type' => 'string' ),
						'latitude'  => array( 'type' => 'number' ),
						'longitude' => array( 'type' => 'number' ),
						'timezone'  => array( 'type' => 'string' ),
						'website'   => array( 'type' => 'string' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_check_venue_duplicate',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Execute the events-check-venue-duplicate ability.
 *
 * @param array $input Input with 'name' and optional 'city'.
 * @return array|WP_Error Array of matching venue records or error.
 */
function extrachill_events_ability_check_venue_duplicate( array $input ): array|\WP_Error {
	$name = sanitize_text_field( $input['name'] ?? '' );

	if ( empty( $name ) ) {
		return new \WP_Error(
			'missing_name',
			__( 'Venue name is required.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	// Search for venues matching the name.
	$terms = get_terms( array(
		'taxonomy'   => 'venue',
		'hide_empty' => false,
		'name__like' => $name,
		'number'     => 10,
	) );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}

	$matches = array();
	foreach ( $terms as $term ) {
		$matches[] = extrachill_events_transform_venue_detail( array(
			'term_id' => $term->term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
		) );
	}

	return $matches;
}
