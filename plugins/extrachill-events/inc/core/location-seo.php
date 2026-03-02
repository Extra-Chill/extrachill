<?php
/**
 * Location SEO
 *
 * Provides SEO data for location archive pages on the events site.
 * Uses extrachill-seo filters instead of direct output — the SEO plugin
 * is the single rendering engine.
 *
 * Filters used:
 *   - document_title_parts (WordPress native, priority 1000)
 *   - extrachill_seo_meta_description
 *
 * @package ExtraChillEvents
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Helpers ---

/**
 * Check if we're on a location archive on the events site (but not a discovery page).
 *
 * @return bool
 */
function extrachill_events_is_location_archive(): bool {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return false;
	}

	if ( ! is_tax( 'location' ) ) {
		return false;
	}

	// Discovery pages handle their own SEO.
	if ( function_exists( 'extrachill_events_is_discovery_page' ) && extrachill_events_is_discovery_page() ) {
		return false;
	}

	return true;
}

// --- Title ---

/**
 * Override document title for location archives on the events site.
 *
 * Changes "{City} Live Music Calendar" → "Live Music in {City} Tonight & This Week".
 * Runs at priority 1000 to override extrachill-seo's default pattern.
 *
 * @hook document_title_parts
 * @param array $title_parts Document title parts.
 * @return array Modified title parts.
 */
function extrachill_events_location_title( array $title_parts ): array {
	if ( ! extrachill_events_is_location_archive() ) {
		return $title_parts;
	}

	$term_name            = single_term_title( '', false );
	$title_parts['title'] = sprintf( 'Live Music in %s Tonight & This Week', $term_name );

	return $title_parts;
}
add_filter( 'document_title_parts', 'extrachill_events_location_title', 1000 );

// --- Meta Description ---

/**
 * Provide meta description for location archives via extrachill-seo filter.
 *
 * Generates descriptions like:
 * "Find live music in Charleston tonight and this week. 45 upcoming shows
 * across 17 venues including The Royal American, Charleston Pour House, and more."
 *
 * @hook extrachill_seo_meta_description
 * @param string $description Default description from extrachill-seo.
 * @return string Location-specific description or pass-through.
 */
function extrachill_events_location_description( string $description ): string {
	if ( ! extrachill_events_is_location_archive() ) {
		return $description;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return $description;
	}

	return extrachill_events_build_location_description( $term->name, $term->term_id );
}
add_filter( 'extrachill_seo_meta_description', 'extrachill_events_location_description' );

// --- Shared Helpers ---

/**
 * Build a dynamic meta description for a location.
 *
 * Used by both location archives and discovery pages.
 *
 * @param string $city_name City display name.
 * @param int    $term_id   Location term ID.
 * @param string $scope_label Optional scope suffix (e.g., "tonight", "this weekend").
 * @return string Meta description (max 160 chars).
 */
function extrachill_events_build_location_description( string $city_name, int $term_id, string $scope_label = '' ): string {
	$scope_text = ! empty( $scope_label ) ? ' ' . $scope_label : ' tonight and this week';

	$description = sprintf( 'Find live music in %s%s.', $city_name, $scope_text );

	// Count upcoming events for this location.
	$event_count = extrachill_events_get_upcoming_event_count( $term_id );

	// Get venue data.
	$venues      = extrachill_events_get_location_venues( $term_id );
	$venue_count = count( $venues );

	if ( $event_count > 0 && $venue_count > 0 ) {
		$description .= sprintf(
			' %d upcoming shows across %d venues',
			$event_count,
			$venue_count
		);

		// Add top venue names (up to 2).
		$venue_names = array_column( $venues, 'name' );
		if ( count( $venue_names ) >= 2 ) {
			$description .= sprintf(
				' including %s, %s, and more.',
				$venue_names[0],
				$venue_names[1]
			);
		} elseif ( count( $venue_names ) === 1 ) {
			$description .= sprintf( ' including %s.', $venue_names[0] );
		} else {
			$description .= '.';
		}
	} else {
		$description .= sprintf( ' Browse the full %s live music calendar on Extra Chill.', $city_name );
	}

	// Truncate to 160 chars at word boundary.
	if ( strlen( $description ) > 160 ) {
		$description = substr( $description, 0, 157 );
		$last_space  = strrpos( $description, ' ' );
		if ( false !== $last_space ) {
			$description = substr( $description, 0, $last_space );
		}
		$description .= '...';
	}

	return $description;
}

/**
 * Count upcoming events for a location term.
 *
 * @param int $term_id Location term ID.
 * @return int Number of upcoming events.
 */
function extrachill_events_get_upcoming_event_count( int $term_id ): int {
	$args = array(
		'post_type'      => 'data_machine_events',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'tax_query'      => array(
			array(
				'taxonomy' => 'location',
				'terms'    => $term_id,
			),
		),
		'meta_query'     => array(
			array(
				'key'     => '_datamachine_event_datetime',
				'value'   => current_time( 'mysql' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
			),
		),
	);

	$query = new \WP_Query( $args );

	return $query->found_posts;
}
