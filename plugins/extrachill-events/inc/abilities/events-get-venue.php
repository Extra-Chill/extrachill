<?php
/**
 * Events Get Venue Ability
 *
 * Returns detailed information for a single venue by term ID,
 * including address, city, state, country, coordinates, timezone,
 * and website.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_get_venue_ability' );

/**
 * Register extrachill/events-get-venue ability.
 */
function extrachill_events_register_get_venue_ability(): void {

	wp_register_ability(
		'extrachill/events-get-venue',
		array(
			'label'               => __( 'Get Venue', 'extrachill-events' ),
			'description'         => __( 'Returns detailed information for a single venue by term ID.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Venue term ID.',
					),
				),
			),
			'output_schema'       => array(
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
					'url'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_get_venue',
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
 * Execute the events-get-venue ability.
 *
 * @param array $input Input with 'id' (venue term ID).
 * @return array|WP_Error Venue detail or error.
 */
function extrachill_events_ability_get_venue( array $input ): array|\WP_Error {
	$term_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

	if ( ! $term_id ) {
		return new \WP_Error(
			'missing_id',
			__( 'Venue term ID is required.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	$venue_data = function_exists( 'data_machine_events_get_venue_data' )
		? data_machine_events_get_venue_data( $term_id )
		: null;

	if ( empty( $venue_data ) ) {
		return new \WP_Error(
			'venue_not_found',
			__( 'Venue not found.', 'extrachill-events' ),
			array( 'status' => 404 )
		);
	}

	return extrachill_events_transform_venue_detail( $venue_data );
}

/**
 * Transform raw venue data into consumer-friendly detail shape.
 *
 * Shared by events-get-venue and events-check-venue-duplicate.
 *
 * @param array $venue Raw venue data with optional coordinates string.
 * @return array Transformed venue detail.
 */
function extrachill_events_transform_venue_detail( array $venue ): array {
	$lat = null;
	$lon = null;

	if ( ! empty( $venue['coordinates'] ) && strpos( $venue['coordinates'], ',' ) !== false ) {
		$parts = explode( ',', $venue['coordinates'] );
		$lat   = (float) trim( $parts[0] );
		$lon   = (float) trim( $parts[1] );
	}

	return array(
		'id'                       => (int) ( $venue['term_id'] ?? 0 ),
		'name'                     => $venue['name'] ?? '',
		'slug'                     => $venue['slug'] ?? '',
		'description'              => $venue['description'] ?? null,
		'address'                  => $venue['address'] ?? null,
		'formatted_address'        => $venue['formatted_address'] ?? null,
		'city'                     => $venue['city'] ?? null,
		'state'                    => $venue['state'] ?? null,
		'zip'                      => $venue['zip'] ?? null,
		'country'                  => $venue['country'] ?? null,
		'latitude'                 => $lat,
		'longitude'                => $lon,
		'coordinates'              => $venue['coordinates'] ?? null,
		'timezone'                 => $venue['timezone'] ?? null,
		'website'                  => $venue['website'] ?? null,
		'phone'                    => $venue['phone'] ?? null,
		'capacity'                 => $venue['capacity'] ?? null,
		'url'                      => (string) ( $venue['url'] ?? '' ),
		'event_count'              => isset( $venue['event_count'] ) ? (int) $venue['event_count'] : null,
		'distance'                 => isset( $venue['distance'] ) ? (float) $venue['distance'] : null,
		'upcoming_events_at_venue' => $venue['upcoming_events_at_venue'] ?? array(),
	);
}
