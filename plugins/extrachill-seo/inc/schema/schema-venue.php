<?php
/**
 * EventVenue Schema for Venue Taxonomy Archives
 *
 * Outputs EventVenue schema on venue taxonomy archive pages.
 * Requires datamachine-events plugin for venue data functions.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the EventVenue schema entity for a venue taxonomy term.
 *
 * @param \WP_Term $term Venue term.
 * @return array|null EventVenue entity, or null when venue data is unavailable.
 */
function ec_seo_build_venue_schema( \WP_Term $term ): ?array {
	// Uses data-machine-events public integration API. See data-machine-events
	// docs/integration-api.md.
	if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
		return null;
	}

	$venue_data = data_machine_events_get_venue_data( (int) $term->term_id );

	if ( empty( $venue_data ) ) {
		return null;
	}

	$venue_url = get_term_link( $term );

	$venue_schema = array(
		'@type' => 'EventVenue',
		'@id'   => $venue_url . '#venue',
		'name'  => $venue_data['name'],
		'url'   => $venue_url,
	);

	if ( ! empty( $venue_data['description'] ) ) {
		$venue_schema['description'] = wp_strip_all_tags( $venue_data['description'] );
	}

	$address = ec_seo_build_postal_address(
		array(
			'street'  => $venue_data['address'] ?? '',
			'city'    => $venue_data['city'] ?? '',
			'state'   => $venue_data['state'] ?? '',
			'zip'     => $venue_data['zip'] ?? '',
			'country' => $venue_data['country'] ?? '',
		)
	);
	if ( ! empty( $address ) ) {
		$venue_schema['address'] = $address;
	}

	if ( ! empty( $venue_data['coordinates'] ) ) {
		$coords = explode( ',', $venue_data['coordinates'] );
		if ( count( $coords ) === 2 ) {
			$venue_schema['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) trim( $coords[0] ),
				'longitude' => (float) trim( $coords[1] ),
			);
		}
	}

	if ( ! empty( $venue_data['phone'] ) ) {
		$venue_schema['telephone'] = $venue_data['phone'];
	}

	if ( ! empty( $venue_data['website'] ) ) {
		$venue_schema['sameAs'] = array( $venue_data['website'] );
	}

	if ( ! empty( $venue_data['capacity'] ) ) {
		$venue_schema['maximumAttendeeCapacity'] = (int) $venue_data['capacity'];
	}

	return $venue_schema;
}

/**
 * Append EventVenue schema to the graph on venue taxonomy archives.
 *
 * @param array $graph Current schema graph.
 * @return array Graph with venue entity appended when applicable.
 */
function ec_seo_emit_venue_schema( $graph ) {
	return ec_seo_register_taxonomy_schema( $graph, 'venue', __NAMESPACE__ . '\\ec_seo_build_venue_schema' );
}
add_filter( 'extrachill_seo_schema_graph', __NAMESPACE__ . '\\ec_seo_emit_venue_schema' );
