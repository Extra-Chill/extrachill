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
 * Results are cached per-request in a static variable (same location term is
 * queried by both the map and SEO hooks on a single page load) and in a
 * transient with 1-hour TTL for cross-request caching.
 *
 * @param int $term_id Location term ID.
 * @return array Array of venue data arrays with keys: term_id, name, slug, lat, lon, address, url.
 */
function extrachill_events_get_location_venues( int $term_id ): array {
	// Per-request static cache — prevents duplicate work when called by
	// both location-map.php and location-seo.php in the same page load.
	static $request_cache = array();
	if ( isset( $request_cache[ $term_id ] ) ) {
		return $request_cache[ $term_id ];
	}

	// Transient cache — avoids the venue query across requests.
	$cache_key = 'ec_location_venues_' . $term_id;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		$request_cache[ $term_id ] = $cached;
		return $cached;
	}

	$location = get_term( $term_id, 'location' );
	if ( ! $location || is_wp_error( $location ) ) {
		return array();
	}

	// Build set of matchable city names: term name + child term names + aliases.
	$city_names = extrachill_events_get_location_city_names( $location );

	// Single SQL query to find matching venues with city + coordinates meta,
	// replacing the N+1 get_term_meta() loop over all 2,000+ venues.
	global $wpdb;
	$city_placeholders = implode( ', ', array_fill( 0, count( $city_names ), '%s' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$venue_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug,
			        tt.count AS event_count,
			        tm_city.meta_value AS venue_city,
			        tm_coords.meta_value AS venue_coordinates
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id AND tt.taxonomy = 'venue'
			INNER JOIN {$wpdb->termmeta} tm_city ON t.term_id = tm_city.term_id AND tm_city.meta_key = '_venue_city'
			INNER JOIN {$wpdb->termmeta} tm_coords ON t.term_id = tm_coords.term_id AND tm_coords.meta_key = '_venue_coordinates'
			WHERE LOWER(tm_city.meta_value) IN ({$city_placeholders})
			AND tm_coords.meta_value != ''
			AND tm_coords.meta_value LIKE '%%,%%'",
			...$city_names
		)
	);

	$matched = array();

	foreach ( $venue_rows as $row ) {
		$parts = explode( ',', $row->venue_coordinates );
		$lat   = floatval( trim( $parts[0] ) );
		$lon   = floatval( trim( $parts[1] ) );

		if ( 0.0 === $lat && 0.0 === $lon ) {
			continue;
		}

		$address = '';
		if ( class_exists( 'DataMachineEvents\Core\Venue_Taxonomy' ) ) {
			$address = \DataMachineEvents\Core\Venue_Taxonomy::get_formatted_address( $row->term_id );
		}

		$matched[] = array(
			'term_id'     => (int) $row->term_id,
			'name'        => $row->name,
			'slug'        => $row->slug,
			'lat'         => $lat,
			'lon'         => $lon,
			'address'     => $address,
			'url'         => get_term_link( (int) $row->term_id, 'venue' ),
			'event_count' => (int) $row->event_count,
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

	$request_cache[ $term_id ] = $matched;
	set_transient( $cache_key, $matched, HOUR_IN_SECONDS );

	return $matched;
}

/**
 * Invalidate the venue cache for a location when venues change.
 *
 * Clears all location venue transients since any venue edit could
 * affect any location's venue list.
 *
 * @param int $term_id Venue term ID.
 */
function extrachill_events_invalidate_location_venue_cache( int $term_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '_transient_ec_location_venues_%'
		OR option_name LIKE '_transient_timeout_ec_location_venues_%'"
	);
}
add_action( 'edited_venue', 'extrachill_events_invalidate_location_venue_cache' );
add_action( 'created_venue', 'extrachill_events_invalidate_location_venue_cache' );
add_action( 'delete_venue', 'extrachill_events_invalidate_location_venue_cache' );
