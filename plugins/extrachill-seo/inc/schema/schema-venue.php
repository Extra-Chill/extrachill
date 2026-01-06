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

add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		if ( ! is_tax( 'venue' ) ) {
			return $graph;
		}

		if ( ! class_exists( '\DataMachineEvents\Core\Venue_Taxonomy' ) ) {
			return $graph;
		}

		$term       = get_queried_object();
		$venue_data = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data( $term->term_id );

		if ( empty( $venue_data ) ) {
			return $graph;
		}

		$venue_url = get_term_link( $term );

		$venue_schema = [
			'@type' => 'EventVenue',
			'@id'   => $venue_url . '#venue',
			'name'  => $venue_data['name'],
			'url'   => $venue_url,
		];

		if ( ! empty( $venue_data['description'] ) ) {
			$venue_schema['description'] = wp_strip_all_tags( $venue_data['description'] );
		}

		$address = [];
		if ( ! empty( $venue_data['address'] ) ) {
			$address['streetAddress'] = $venue_data['address'];
		}
		if ( ! empty( $venue_data['city'] ) ) {
			$address['addressLocality'] = $venue_data['city'];
		}
		if ( ! empty( $venue_data['state'] ) ) {
			$address['addressRegion'] = $venue_data['state'];
		}
		if ( ! empty( $venue_data['zip'] ) ) {
			$address['postalCode'] = $venue_data['zip'];
		}
		if ( ! empty( $venue_data['country'] ) ) {
			$address['addressCountry'] = $venue_data['country'];
		}

		if ( ! empty( $address ) ) {
			$address['@type']        = 'PostalAddress';
			$venue_schema['address'] = $address;
		}

		if ( ! empty( $venue_data['coordinates'] ) ) {
			$coords = explode( ',', $venue_data['coordinates'] );
			if ( count( $coords ) === 2 ) {
				$venue_schema['geo'] = [
					'@type'     => 'GeoCoordinates',
					'latitude'  => (float) trim( $coords[0] ),
					'longitude' => (float) trim( $coords[1] ),
				];
			}
		}

		if ( ! empty( $venue_data['phone'] ) ) {
			$venue_schema['telephone'] = $venue_data['phone'];
		}

		if ( ! empty( $venue_data['website'] ) ) {
			$venue_schema['sameAs'] = [ $venue_data['website'] ];
		}

		if ( ! empty( $venue_data['capacity'] ) ) {
			$venue_schema['maximumAttendeeCapacity'] = (int) $venue_data['capacity'];
		}

		$graph[] = $venue_schema;

		return $graph;
	}
);
