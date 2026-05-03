<?php
/**
 * Events Geocode Ability
 *
 * Geocodes a search query (address, city, or place name) via
 * data-machine-events/geocode-search (Nominatim) and returns
 * lat/lon results scoped to the US.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_geocode_ability' );

/**
 * Register extrachill/events-geocode ability.
 */
function extrachill_events_register_geocode_ability(): void {

	wp_register_ability(
		'extrachill/events-geocode',
		array(
			'label'               => __( 'Events Geocode', 'extrachill-events' ),
			'description'         => __( 'Geocode a search query to lat/lon coordinates via Nominatim. Results scoped to US.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'q' ),
				'properties' => array(
					'q' => array(
						'type'        => 'string',
						'description' => 'Search query (address, city, or place name).',
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'lat'          => array( 'type' => 'number' ),
						'lon'          => array( 'type' => 'number' ),
						'display_name' => array( 'type' => 'string' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_geocode',
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
 * Execute the events-geocode ability.
 *
 * @param array $input Input with 'q' search query.
 * @return array|WP_Error Array of geocode results or error.
 */
function extrachill_events_ability_geocode( array $input ): array|\WP_Error {
	$ability = wp_get_ability( 'data-machine-events/geocode-search' );
	if ( ! $ability ) {
		return new \WP_Error(
			'ability_unavailable',
			__( 'Geocode search ability is not registered.', 'extrachill-events' ),
			array( 'status' => 500 )
		);
	}

	$query = sanitize_text_field( $input['q'] ?? '' );
	if ( empty( $query ) ) {
		return new \WP_Error(
			'missing_query',
			__( 'Search query (q) is required.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	$result = $ability->execute( array(
		'query'        => $query,
		'countrycodes' => 'us',
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Transform Nominatim results to GeoSearchResult shape.
	$results = array();
	foreach ( $result['results'] ?? array() as $item ) {
		$results[] = array(
			'lat'          => (float) ( $item['lat'] ?? 0 ),
			'lon'          => (float) ( $item['lon'] ?? 0 ),
			'display_name' => $item['display_name'] ?? '',
		);
	}

	return $results;
}
