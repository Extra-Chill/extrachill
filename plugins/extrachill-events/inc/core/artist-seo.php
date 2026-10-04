<?php
/**
 * Artist SEO
 *
 * SEO title and meta description for artist archives on the events site.
 * People search for an artist's "tour dates", not their "live music
 * calendar", so artist archives use the tour-dates framing that the page
 * heading already uses. Uses extrachill-seo filters; the SEO plugin remains
 * the single rendering engine.
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
 * Check if we're on an artist archive on the events site.
 *
 * @return bool
 */
function extrachill_events_is_artist_archive(): bool {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return false;
	}

	return is_tax( 'artist' );
}

/**
 * Title: "{Artist} Tour Dates".
 *
 * @hook document_title_parts
 * @param array $title_parts Document title parts.
 * @return array Modified title parts.
 */
function extrachill_events_artist_title( array $title_parts ): array {
	if ( ! extrachill_events_is_artist_archive() ) {
		return $title_parts;
	}

	$title_parts['title'] = sprintf( '%s Tour Dates', single_term_title( '', false ) );

	return $title_parts;
}
add_filter( 'document_title_parts', 'extrachill_events_artist_title', 1000 );

/**
 * Meta description for artist archives.
 *
 * @hook extrachill_seo_meta_description
 * @param string $description Default description from extrachill-seo.
 * @return string Artist-specific description or pass-through.
 */
function extrachill_events_artist_description( string $description ): string {
	if ( ! extrachill_events_is_artist_archive() ) {
		return $description;
	}

	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return $description;
	}

	$upcoming = extrachill_events_get_artist_upcoming_events( $term->term_id );

	return extrachill_events_build_artist_description( $term->name, $upcoming['total'], $upcoming['cities'] );
}
add_filter( 'extrachill_seo_meta_description', 'extrachill_events_artist_description' );

/**
 * Build an artist archive meta description.
 *
 * @param string   $artist_name Artist display name.
 * @param int      $total       Number of upcoming shows.
 * @param string[] $cities      Distinct upcoming cities, soonest first.
 * @return string Meta description (max 160 chars).
 */
function extrachill_events_build_artist_description( string $artist_name, int $total, array $cities ): string {
	if ( $total < 1 ) {
		$description = sprintf( '%s tour dates and concert history on Extra Chill. Check back for new %s shows as they are announced.', $artist_name, $artist_name );
	} else {
		$description = sprintf(
			'%s tour dates: %d upcoming %s',
			$artist_name,
			$total,
			1 === $total ? 'show' : 'shows'
		);

		$cities = array_values( array_unique( array_filter( $cities ) ) );
		if ( count( $cities ) > 3 || ( 3 === count( $cities ) && $total > 3 ) ) {
			$description .= sprintf( ' including %s, %s, %s, and more', $cities[0], $cities[1], $cities[2] );
		} elseif ( 3 === count( $cities ) ) {
			$description .= sprintf( ' in %s, %s, and %s', $cities[0], $cities[1], $cities[2] );
		} elseif ( 2 === count( $cities ) ) {
			$description .= sprintf( ' in %s and %s', $cities[0], $cities[1] );
		} elseif ( 1 === count( $cities ) ) {
			$description .= sprintf( ' in %s', $cities[0] );
		}

		$description .= '. Find tickets and concert details on Extra Chill.';
	}

	if ( strlen( $description ) > 160 ) {
		$description = substr( $description, 0, 157 );
		$last_space  = strrpos( $description, ' ' );
		if ( false !== $last_space ) {
			$description = substr( $description, 0, $last_space );
		}
		$description = rtrim( $description, ' ,.' ) . '...';
	}

	return $description;
}

/**
 * Upcoming show count and soonest distinct cities for an artist.
 *
 * @param int $term_id Artist term ID.
 * @return array{total:int,cities:string[]}
 */
function extrachill_events_get_artist_upcoming_events( int $term_id ): array {
	$empty = array(
		'total'  => 0,
		'cities' => array(),
	);

	if ( ! function_exists( 'data_machine_events_query_events' ) ) {
		return $empty;
	}

	$result = data_machine_events_query_events(
		array(
			'scope'       => 'upcoming',
			'tax_filters' => array( 'artist' => array( $term_id ) ),
			'per_page'    => 10,
			'order'       => 'ASC',
		)
	);

	$cities = array();
	foreach ( (array) ( $result['posts'] ?? array() ) as $post ) {
		$post_id   = $post instanceof WP_Post ? $post->ID : (int) $post;
		$locations = wp_get_post_terms( $post_id, 'location', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
			$cities[] = (string) $locations[0];
		}
	}

	return array(
		'total'  => (int) ( $result['total'] ?? 0 ),
		'cities' => array_values( array_unique( $cities ) ),
	);
}
