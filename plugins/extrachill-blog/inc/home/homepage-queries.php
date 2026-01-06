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
 * Uses internal REST API call to get top 8 locations with upcoming events,
 * sorted by event count descending.
 *
 * @return array Array of location data: name, slug, count, url
 */
function extrachill_blog_get_location_event_counts() {
	$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
	$request->set_query_params(
		array(
			'taxonomy' => 'location',
			'limit'    => 8,
		)
	);

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return array();
	}

	$data = $response->get_data();
	return is_array( $data ) ? $data : array();
}

/**
 * Get festival terms with post counts from wire.extrachill.com
 *
 * Uses internal REST API call to get top 8 festivals with wire posts,
 * sorted by count descending.
 *
 * @return array Array of festival data: name, slug, count, url
 */
function extrachill_blog_get_wire_festival_counts() {
	$request = new WP_REST_Request( 'GET', '/extrachill/v1/wire/taxonomy-counts' );
	$request->set_query_params(
		array(
			'taxonomy' => 'festival',
			'limit'    => 8,
		)
	);

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return array();
	}

	$data = $response->get_data();
	return is_array( $data ) ? $data : array();
}
