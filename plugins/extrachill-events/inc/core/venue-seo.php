<?php
/**
 * Venue SEO
 *
 * SEO title and meta description for venue archives on the events site.
 * People search "{venue} upcoming events", usually with the city, not
 * "{venue} live music calendar". Venue archives had no meta description at
 * all, so Google built one from page chrome. Uses extrachill-seo filters; the
 * SEO plugin remains the single rendering engine.
 *
 * Filters used:
 *   - document_title_parts (WordPress native, priority 1000)
 *   - extrachill_seo_meta_description
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if we're on a venue archive on the events site.
 *
 * @return bool
 */
function extrachill_events_is_venue_archive(): bool {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return false;
	}

	return is_tax( 'venue' );
}

/**
 * Human location label for a venue: "Nashville, TN" in the US, the city
 * elsewhere. Empty when the city is unknown or already part of the name.
 *
 * @param string $venue_name Venue name.
 * @param string $city       Venue city.
 * @param string $state      Venue state/region.
 * @return string
 */
function extrachill_events_venue_location_label( string $venue_name, string $city, string $state ): string {
	$city      = trim( $city );
	$city_core = trim( (string) preg_replace( '/\s+city$/i', '', $city ) );
	if ( '' === $city_core || false !== stripos( $venue_name, $city_core ) ) {
		return '';
	}

	$state = trim( $state );
	if ( 1 === preg_match( '/^[A-Z]{2}$/', $state ) ) {
		return $city . ', ' . $state;
	}

	return $city;
}

/**
 * Build a venue archive document title: "{Venue} Upcoming Events in {City}".
 *
 * @param string $venue_name     Venue name.
 * @param string $location_label Location label, may be empty.
 * @return string
 */
function extrachill_events_build_venue_title( string $venue_name, string $location_label ): string {
	if ( '' === $location_label ) {
		return sprintf( '%s Upcoming Events', $venue_name );
	}

	return sprintf( '%s Upcoming Events in %s', $venue_name, $location_label );
}

/**
 * Build a venue archive meta description.
 *
 * Sentences are dropped from the end, not cut mid-word, so the snippet stays
 * readable within 160 characters.
 *
 * @param string $venue_name     Venue name.
 * @param string $location_label Location label, may be empty.
 * @param int    $total          Number of upcoming shows.
 * @param string $next_title     Title of the next show, may be empty.
 * @param string $next_date      Display date of the next show ("Oct 24"), may be empty.
 * @return string Meta description (max 160 chars).
 */
function extrachill_events_build_venue_description( string $venue_name, string $location_label, int $total, string $next_title = '', string $next_date = '' ): string {
	$where = '' !== $location_label ? sprintf( '%s in %s', $venue_name, $location_label ) : $venue_name;

	if ( $total < 1 ) {
		$sentences = array(
			sprintf( 'Concerts and events at %s.', $where ),
			'Past shows and new dates on the Extra Chill live music calendar.',
		);
	} else {
		$sentences = array(
			sprintf( '%d upcoming %s at %s.', $total, 1 === $total ? 'show' : 'shows', $where ),
		);

		if ( '' !== $next_title && '' !== $next_date ) {
			$sentences[] = sprintf( 'Next up: %s on %s.', $next_title, $next_date );
		}

		$sentences[] = 'Showtimes and tickets on Extra Chill.';
	}

	$description = implode( ' ', $sentences );
	$remaining   = count( $sentences );
	$too_long    = strlen( $description ) > 160;
	while ( $too_long && $remaining > 1 ) {
		array_pop( $sentences );
		--$remaining;
		$description = implode( ' ', $sentences );
		$too_long    = strlen( $description ) > 160;
	}

	if ( strlen( $description ) > 160 ) {
		$description = substr( $description, 0, 157 );
		$last_space  = strrpos( $description, ' ' );
		if ( false !== $last_space ) {
			$description = substr( $description, 0, $last_space );
		}
		$description = rtrim( $description, ' ,.:' ) . '...';
	}

	return $description;
}

/**
 * Upcoming show count and the next show for a venue.
 *
 * The count comes from venue-map.php's canonical count helper; the next show
 * is the first upcoming event ID.
 *
 * @param int $term_id Venue term ID.
 * @return array{total:int,next_id:int}
 */
function extrachill_events_get_venue_upcoming( int $term_id ): array {
	$empty = array(
		'total'   => 0,
		'next_id' => 0,
	);

	if ( ! function_exists( 'data_machine_events_query_events' ) || ! function_exists( 'extrachill_events_get_upcoming_venue_event_count' ) ) {
		return $empty;
	}

	$total = extrachill_events_get_upcoming_venue_event_count( $term_id );
	if ( $total < 1 ) {
		return $empty;
	}

	$result = data_machine_events_query_events(
		array(
			'scope'       => 'upcoming',
			'tax_filters' => array( 'venue' => array( $term_id ) ),
			'per_page'    => 1,
			'fields'      => 'ids',
			'order'       => 'ASC',
		)
	);

	$first = ( (array) ( $result['posts'] ?? array() ) )[0] ?? 0;

	return array(
		'total'   => $total,
		'next_id' => $first instanceof WP_Post ? $first->ID : (int) $first,
	);
}

/**
 * Venue location fields from data-machine-events.
 *
 * @param WP_Term $term Venue term.
 * @return string Location label.
 */
function extrachill_events_venue_term_location_label( WP_Term $term ): string {
	$data = function_exists( 'data_machine_events_get_venue_data' ) ? data_machine_events_get_venue_data( (int) $term->term_id ) : null;

	return extrachill_events_venue_location_label(
		html_entity_decode( $term->name, ENT_QUOTES ),
		html_entity_decode( (string) ( $data['city'] ?? '' ), ENT_QUOTES ),
		(string) ( $data['state'] ?? '' )
	);
}

/**
 * Title: "{Venue} Upcoming Events in {City}, {ST}".
 *
 * @hook document_title_parts
 * @param array $title_parts Document title parts.
 * @return array Modified title parts.
 */
function extrachill_events_venue_title( array $title_parts ): array {
	if ( ! extrachill_events_is_venue_archive() ) {
		return $title_parts;
	}

	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return $title_parts;
	}

	$title_parts['title'] = extrachill_events_build_venue_title(
		html_entity_decode( $term->name, ENT_QUOTES ),
		extrachill_events_venue_term_location_label( $term )
	);

	return $title_parts;
}
add_filter( 'document_title_parts', 'extrachill_events_venue_title', 1000 );

/**
 * Meta description for venue archives.
 *
 * @hook extrachill_seo_meta_description
 * @param string $description Default description from extrachill-seo.
 * @return string Venue-specific description or pass-through.
 */
function extrachill_events_venue_description( string $description ): string {
	if ( ! extrachill_events_is_venue_archive() ) {
		return $description;
	}

	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return $description;
	}

	$upcoming   = extrachill_events_get_venue_upcoming( (int) $term->term_id );
	$next_title = '';
	$next_date  = '';

	if ( $upcoming['next_id'] > 0 ) {
		$next_title = html_entity_decode( get_the_title( $upcoming['next_id'] ), ENT_QUOTES );
		$dates      = function_exists( 'datamachine_get_event_dates' ) ? datamachine_get_event_dates( $upcoming['next_id'] ) : null;
		$start      = $dates && ! empty( $dates->start_datetime ) ? date_create( (string) $dates->start_datetime ) : false;
		// Stored start times are venue-local wall time: format, do not convert.
		$next_date = $start ? $start->format( 'M j' ) : '';
	}

	return extrachill_events_build_venue_description(
		html_entity_decode( $term->name, ENT_QUOTES ),
		extrachill_events_venue_term_location_label( $term ),
		$upcoming['total'],
		$next_title,
		$next_date
	);
}
add_filter( 'extrachill_seo_meta_description', 'extrachill_events_venue_description' );
