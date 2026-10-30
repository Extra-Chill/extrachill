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
					'name'    => array(
						'type'        => 'string',
						'description' => 'Venue name to check.',
					),
					'city'    => array(
						'type'        => 'string',
						'description' => 'City for more accurate matching.',
					),
					'address' => array(
						'type'        => 'string',
						'description' => 'Street address for canonical venue identity matching.',
					),
					'state'   => array(
						'type'        => 'string',
						'description' => 'State or region for canonical venue identity matching.',
					),
					'country' => array(
						'type'        => 'string',
						'description' => 'Country for canonical venue identity matching.',
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'address'     => array( 'type' => 'string' ),
						'city'        => array( 'type' => 'string' ),
						'state'       => array( 'type' => 'string' ),
						'country'     => array( 'type' => 'string' ),
						'zip'         => array( 'type' => 'string' ),
						'latitude'    => array( 'type' => 'number' ),
						'longitude'   => array( 'type' => 'number' ),
						'coordinates' => array( 'type' => 'string' ),
						'timezone'    => array( 'type' => 'string' ),
						'website'     => array( 'type' => 'string' ),
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

	if ( ! class_exists( '\DataMachineEvents\Core\Venue_Taxonomy' )
		|| ! method_exists( '\DataMachineEvents\Core\Venue_Taxonomy', 'resolve_venue_identity' )
	) {
		return new \WP_Error(
			'ability_unavailable',
			__( 'Canonical venue identity resolver is not available.', 'extrachill-events' ),
			array( 'status' => 500 )
		);
	}

	return extrachill_events_find_duplicate_venues(
		$input,
		array( '\DataMachineEvents\Core\Venue_Taxonomy', 'resolve_venue_identity' )
	);
}

/**
 * Delegate matching to the canonical venue identity ability and adapt its result.
 *
 * @param array    $input    Venue identity evidence.
 * @param callable $resolver Canonical DME venue identity resolver.
 * @return array|WP_Error Matching canonical venue records or error.
 */
function extrachill_events_find_duplicate_venues( array $input, callable $resolver ): array|\WP_Error {
	$name = sanitize_text_field( $input['name'] ?? '' );

	$venue_data = array();
	foreach ( array( 'address', 'city', 'state', 'country' ) as $field ) {
		if ( ! empty( $input[ $field ] ) ) {
			$venue_data[ $field ] = sanitize_text_field( $input[ $field ] );
		}
	}

	$identity = $resolver( $name, $venue_data );
	$term_id  = (int) ( $identity['term_id'] ?? 0 );
	if ( 'matched' !== ( $identity['match_status'] ?? '' ) || ! $term_id ) {
		return array();
	}

	$venue = function_exists( 'data_machine_events_get_venue_data' )
		? data_machine_events_get_venue_data( $term_id )
		: null;

	if ( empty( $venue ) ) {
		return new \WP_Error(
			'venue_not_found',
			__( 'The canonical duplicate venue could not be loaded.', 'extrachill-events' ),
			array( 'status' => 500 )
		);
	}

	return array( extrachill_events_transform_venue_detail( $venue ) );
}
