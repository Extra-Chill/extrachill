<?php
/**
 * Location Taxonomy Meta
 *
 * Registers coordinate meta for the location taxonomy and provides
 * helper functions for geo data. Geocodes locations via Nominatim
 * when coordinates are missing.
 *
 * @package ExtraChillEvents
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register location taxonomy meta fields.
 *
 * @hook init
 */
function extrachill_events_register_location_meta() {
	register_term_meta( 'location', '_location_coordinates', array(
		'type'              => 'string',
		'description'       => 'Latitude,longitude center point for the location.',
		'single'            => true,
		'show_in_rest'      => true,
		'sanitize_callback' => 'sanitize_text_field',
	) );

	register_term_meta( 'location', '_location_city_aliases', array(
		'type'              => 'string',
		'description'       => 'Comma-separated city name aliases for venue matching.',
		'single'            => true,
		'show_in_rest'      => true,
		'sanitize_callback' => 'sanitize_text_field',
	) );
}
add_action( 'init', 'extrachill_events_register_location_meta' );

/**
 * Get parsed coordinates for a location term.
 *
 * @param int $term_id Location term ID.
 * @return array|null Array with 'lat' and 'lon' floats, or null if not set.
 */
function extrachill_events_get_location_coordinates( int $term_id ): ?array {
	$coordinates = get_term_meta( $term_id, '_location_coordinates', true );

	if ( empty( $coordinates ) || strpos( $coordinates, ',' ) === false ) {
		return null;
	}

	$parts = explode( ',', $coordinates );
	$lat   = floatval( trim( $parts[0] ) );
	$lon   = floatval( trim( $parts[1] ) );

	if ( 0.0 === $lat && 0.0 === $lon ) {
		return null;
	}

	return array(
		'lat' => $lat,
		'lon' => $lon,
	);
}

/**
 * Build the set of matchable city names for a location term.
 *
 * Collects the term's own name, names of direct child location terms,
 * and any aliases stored in `_location_city_aliases` term meta (comma-separated).
 * All names are returned lowercased for case-insensitive matching.
 *
 * @param WP_Term $location Location term object.
 * @return array Lowercased city name strings.
 */
function extrachill_events_get_location_city_names( WP_Term $location ): array {
	$names = array( strtolower( $location->name ) );

	// Add child location term names (e.g. Brooklyn under New York City).
	$children = get_terms( array(
		'taxonomy'   => 'location',
		'parent'     => $location->term_id,
		'hide_empty' => false,
		'fields'     => 'names',
	) );

	if ( ! is_wp_error( $children ) ) {
		foreach ( $children as $child_name ) {
			$names[] = strtolower( $child_name );
		}
	}

	// Add explicit aliases from term meta (comma-separated).
	$aliases = get_term_meta( $location->term_id, '_location_city_aliases', true );
	if ( ! empty( $aliases ) ) {
		foreach ( explode( ',', $aliases ) as $alias ) {
			$alias = strtolower( trim( $alias ) );
			if ( '' !== $alias ) {
				$names[] = $alias;
			}
		}
	}

	return array_unique( $names );
}

/**
 * Get all venues that belong to a location by matching venue city name.
 *
 * Matches venues where _venue_city matches the location term name, child term
 * names, or explicit aliases (case-insensitive). Returns venue data including
 * coordinates for map rendering.
 *
 * @param int $term_id Location term ID.
 * @return array Array of venue data arrays with keys: term_id, name, slug, lat, lon, address, url.
 */
function extrachill_events_get_location_venues( int $term_id ): array {
	$location = get_term( $term_id, 'location' );
	if ( ! $location || is_wp_error( $location ) ) {
		return array();
	}

	// Build set of matchable city names: term name + child term names + aliases.
	$city_names = extrachill_events_get_location_city_names( $location );

	// Get all venue terms (no hide_empty to include venues with past-only events).
	$venues = get_terms( array(
		'taxonomy'   => 'venue',
		'hide_empty' => false,
		'number'     => 0,
	) );

	if ( is_wp_error( $venues ) || empty( $venues ) ) {
		return array();
	}

	$matched = array();

	foreach ( $venues as $venue ) {
		$venue_city = get_term_meta( $venue->term_id, '_venue_city', true );

		if ( empty( $venue_city ) || ! in_array( strtolower( $venue_city ), $city_names, true ) ) {
			continue;
		}

		$coordinates = get_term_meta( $venue->term_id, '_venue_coordinates', true );
		if ( empty( $coordinates ) || strpos( $coordinates, ',' ) === false ) {
			continue;
		}

		$parts = explode( ',', $coordinates );
		$lat   = floatval( trim( $parts[0] ) );
		$lon   = floatval( trim( $parts[1] ) );

		if ( 0.0 === $lat && 0.0 === $lon ) {
			continue;
		}

		$address = '';
		if ( class_exists( 'DataMachineEvents\Core\Venue_Taxonomy' ) ) {
			$address = \DataMachineEvents\Core\Venue_Taxonomy::get_formatted_address( $venue->term_id );
		}

		$matched[] = array(
			'term_id'     => $venue->term_id,
			'name'        => $venue->name,
			'slug'        => $venue->slug,
			'lat'         => $lat,
			'lon'         => $lon,
			'address'     => $address,
			'url'         => get_term_link( $venue ),
			'event_count' => $venue->count,
		);
	}

	// Sort by priority first, then by event count descending.
	$priority_ids = function_exists( 'ec_get_priority_venue_ids' ) ? ec_get_priority_venue_ids() : array();
	usort( $matched, function ( $a, $b ) use ( $priority_ids ) {
		$a_priority = in_array( $a['term_id'], $priority_ids, true ) ? 1 : 0;
		$b_priority = in_array( $b['term_id'], $priority_ids, true ) ? 1 : 0;

		if ( $a_priority !== $b_priority ) {
			return $b_priority - $a_priority;
		}

		return $b['event_count'] - $a['event_count'];
	} );

	return $matched;
}
