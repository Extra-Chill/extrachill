<?php
/**
 * Homepage Queries
 *
 * Pre-fetches homepage content into global variables for template consumption.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $live_reviews_posts, $interviews_posts;

$live_reviews_posts = get_posts(
	array(
		'numberposts'   => 3,
		'category_name' => 'live-music-reviews',
	)
);
$interviews_posts   = get_posts(
	array(
		'numberposts'   => 3,
		'category_name' => 'interviews',
	)
);

/**
 * Get location terms with upcoming event counts from events.extrachill.com
 *
 * Queries Blog ID 7 for location taxonomy terms that have future events,
 * returning top 8 locations sorted by event count descending.
 *
 * @return array Array of location data: name, slug, count, url
 */
function extrachill_blog_get_location_event_counts() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id ) {
		return array();
	}

	$locations = array();

	try {
		switch_to_blog( $events_blog_id );

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => true,
				'childless'  => true,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$today = gmdate( 'Y-m-d 00:00:00' );

		foreach ( $terms as $term ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'datamachine_events',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'location',
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
					'meta_query'     => array(
						array(
							'key'     => '_datamachine_event_datetime',
							'value'   => $today,
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
				)
			);

			$count = $query->found_posts;

			if ( $count > 0 ) {
				$locations[] = array(
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => $count,
					'url'   => get_term_link( $term ),
				);
			}
		}
	} finally {
		restore_current_blog();
	}

	usort(
		$locations,
		function ( $a, $b ) {
			return $b['count'] - $a['count'];
		}
	);

	return array_slice( $locations, 0, 8 );
}

/**
 * Get festival terms with post counts from wire.extrachill.com
 *
 * Queries Blog ID 11 for festival taxonomy terms that have wire items,
 * returning top 8 festivals sorted by count descending.
 *
 * @return array Array of festival data: name, slug, count, url
 */
function extrachill_blog_get_wire_festival_counts() {
	$wire_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'wire' ) : 11;
	if ( ! $wire_blog_id ) {
		return array();
	}

	$festivals = array();

	try {
		switch_to_blog( $wire_blog_id );

		$terms = get_terms(
			array(
				'taxonomy'   => 'festival',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 8,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		foreach ( $terms as $term ) {
			$festivals[] = array(
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
				'url'   => get_term_link( $term ),
			);
		}
	} finally {
		restore_current_blog();
	}

	return $festivals;
}
