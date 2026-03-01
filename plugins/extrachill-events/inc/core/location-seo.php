<?php
/**
 * Location SEO
 *
 * Improves title tags and meta descriptions for location archive pages
 * on the events site. Overrides the generic "{City} Live Music Calendar"
 * pattern with "Live Music in {City}" and adds dynamic meta descriptions
 * with event counts and venue information.
 *
 * @package ExtraChillEvents
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter document title for location archives on the events site.
 *
 * Changes "{City} Live Music Calendar" → "Live Music in {City}".
 * Runs at priority 1000 to override extrachill-seo's default pattern.
 *
 * @hook document_title_parts
 * @param array $title_parts Document title parts.
 * @return array Modified title parts.
 */
function extrachill_events_location_title( array $title_parts ): array {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return $title_parts;
	}

	if ( ! is_tax( 'location' ) ) {
		return $title_parts;
	}

	$term_name            = single_term_title( '', false );
	$title_parts['title'] = sprintf( 'Live Music in %s Tonight & This Week', $term_name );

	return $title_parts;
}
add_filter( 'document_title_parts', 'extrachill_events_location_title', 1000 );

/**
 * Add dynamic meta description for location archives.
 *
 * Generates descriptions like:
 * "Find live music in Charleston tonight and this week. 45 upcoming shows
 * across 17 venues including The Royal American, Charleston Pour House, and more."
 *
 * @hook wp_head
 * @priority 4 (before extrachill-seo's meta description at priority 5)
 */
function extrachill_events_location_meta_description() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return;
	}

	if ( ! is_tax( 'location' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return;
	}

	$city_name = $term->name;

	// Count upcoming events for this location.
	$event_count = extrachill_events_get_upcoming_event_count( $term->term_id );

	// Get venue data.
	$venues      = extrachill_events_get_location_venues( $term->term_id );
	$venue_count = count( $venues );

	// Build description.
	$description = sprintf( 'Find live music in %s tonight and this week.', $city_name );

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

	printf(
		'<meta name="description" content="%s" />' . "\n",
		esc_attr( $description )
	);

	// Also add OG description.
	printf(
		'<meta property="og:description" content="%s" />' . "\n",
		esc_attr( $description )
	);
}
add_action( 'wp_head', 'extrachill_events_location_meta_description', 4 );

/**
 * Prevent extrachill-seo from outputting a duplicate meta description on location archives.
 *
 * @hook extrachill_seo_skip_meta_description
 * @param bool $skip Whether to skip the meta description.
 * @return bool
 */
function extrachill_events_skip_seo_description( bool $skip ): bool {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return $skip;
	}

	if ( is_tax( 'location' ) ) {
		return true;
	}

	return $skip;
}
add_filter( 'extrachill_seo_skip_meta_description', 'extrachill_events_skip_seo_description' );

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
