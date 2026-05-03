<?php
/**
 * Events List Venues Ability
 *
 * Returns venues with optional geo-proximity, viewport-bounds,
 * and location-taxonomy filtering.
 *
 * Delegates to data-machine-events/list-venues and transforms
 * venue records into a consumer-friendly shape.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_list_venues_ability' );

/**
 * Register extrachill/events-list-venues ability.
 */
function extrachill_events_register_list_venues_ability(): void {

	wp_register_ability(
		'extrachill/events-list-venues',
		array(
			'label'               => __( 'List Venues', 'extrachill-events' ),
			'description'         => __( 'Returns venues with optional geo-proximity, viewport-bounds, and location-taxonomy filtering.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'location' => array(
						'type'        => 'string',
						'description' => 'Filter by location taxonomy slug.',
					),
					'sw_lat'   => array(
						'type'        => 'number',
						'description' => 'Southwest latitude bound.',
					),
					'sw_lng'   => array(
						'type'        => 'number',
						'description' => 'Southwest longitude bound.',
					),
					'ne_lat'   => array(
						'type'        => 'number',
						'description' => 'Northeast latitude bound.',
					),
					'ne_lng'   => array(
						'type'        => 'number',
						'description' => 'Northeast longitude bound.',
					),
					'lat'      => array(
						'type'        => 'number',
						'description' => 'Center latitude for proximity search.',
					),
					'lng'      => array(
						'type'        => 'number',
						'description' => 'Center longitude for proximity search.',
					),
					'radius'   => array(
						'type'        => 'integer',
						'description' => 'Search radius in miles.',
						'default'     => 25,
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
						'latitude'    => array( 'type' => 'number' ),
						'longitude'   => array( 'type' => 'number' ),
						'event_count' => array( 'type' => 'integer' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_list_venues',
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
 * Execute the events-list-venues ability.
 *
 * @param array $input Input matching input_schema.
 * @return array|WP_Error Array of venue records or error.
 */
function extrachill_events_ability_list_venues( array $input ): array|\WP_Error {
	$ability = wp_get_ability( 'data-machine-events/list-venues' );
	if ( ! $ability ) {
		return new \WP_Error(
			'ability_unavailable',
			__( 'List venues ability is not registered.', 'extrachill-events' ),
			array( 'status' => 500 )
		);
	}

	$delegate_input = array();

	// Geo proximity params.
	if ( isset( $input['lat'] ) && isset( $input['lng'] ) ) {
		$delegate_input['lat']    = (float) $input['lat'];
		$delegate_input['lng']    = (float) $input['lng'];
		$delegate_input['radius'] = (int) ( $input['radius'] ?? 25 );
	}

	// Viewport bounds.
	if ( isset( $input['sw_lat'], $input['sw_lng'], $input['ne_lat'], $input['ne_lng'] ) ) {
		$delegate_input['bounds'] = implode( ',', array(
			(float) $input['sw_lat'],
			(float) $input['sw_lng'],
			(float) $input['ne_lat'],
			(float) $input['ne_lng'],
		) );
	}

	// Location taxonomy filter.
	$location = $input['location'] ?? '';
	if ( ! empty( $location ) ) {
		$term = get_term_by( 'slug', sanitize_text_field( $location ), 'location' );
		if ( $term && ! is_wp_error( $term ) ) {
			$delegate_input['taxonomy'] = 'location';
			$delegate_input['term_id']  = $term->term_id;
		}
	}

	$result = $ability->execute( $delegate_input );

	if ( is_wp_error( $result ) ) {
		return new \WP_Error(
			'venues_error',
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	return array_map( 'extrachill_events_transform_venue', $result['venues'] ?? array() );
}

/**
 * Transform a raw venue record into consumer-friendly shape.
 *
 * @param array $venue Raw venue data.
 * @return array Transformed venue.
 */
function extrachill_events_transform_venue( array $venue ): array {
	return array(
		'id'          => (int) ( $venue['term_id'] ?? 0 ),
		'name'        => $venue['name'] ?? '',
		'slug'        => $venue['slug'] ?? '',
		'address'     => $venue['address'] ?? null,
		'latitude'    => isset( $venue['lat'] ) ? (float) $venue['lat'] : null,
		'longitude'   => isset( $venue['lon'] ) ? (float) $venue['lon'] : null,
		'event_count' => (int) ( $venue['event_count'] ?? 0 ),
	);
}
