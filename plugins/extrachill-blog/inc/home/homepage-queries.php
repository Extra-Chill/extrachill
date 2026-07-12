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

global $latest_blog_posts;

$latest_blog_posts = get_posts(
	array(
		'numberposts' => 5,
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
 * Get the latest Festival Wire posts from wire.extrachill.com.
 *
 * Direct cross-blog read via switch_to_blog() — the Wire publishes multiple
 * dispatches per day, so this is the homepage's freshest live signal. Each
 * item carries a humanized age ("3 hours ago") to make recency visible.
 *
 * @param int $limit Number of posts to return.
 * @return array[] Array of items: title, url, time_diff.
 */
function extrachill_blog_get_wire_latest( $limit = 4 ) {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return array();
	}

	$wire_blog_id = ec_get_blog_id( 'wire' );
	if ( ! $wire_blog_id ) {
		return array();
	}

	$items = array();

	switch_to_blog( $wire_blog_id );

	$wire_posts = get_posts(
		array(
			'numberposts' => absint( $limit ),
			'post_type'   => 'festival_wire',
		)
	);

	foreach ( $wire_posts as $wire_post ) {
		$items[] = array(
			'title'     => get_the_title( $wire_post ),
			'url'       => get_permalink( $wire_post ),
			'time_diff' => human_time_diff( (int) get_post_time( 'U', true, $wire_post ), time() ),
		);
	}

	restore_current_blog();

	return $items;
}

/**
 * Resolve live network proof numbers for the hero stat strip.
 *
 * Wraps ec_get_network_stats() (extrachill-multisite NetworkStats primitive)
 * with a function_exists() guard. Only metrics that are explicitly available
 * with a numeric value are returned — never a fabricated zero.
 *
 * @return array<string,int> Map of metric key => integer value.
 */
function extrachill_blog_get_hero_stats() {
	if ( ! function_exists( 'ec_get_network_stats' ) ) {
		return array();
	}

	$keys  = array( 'events_count', 'events_cities', 'total_members', 'artist_profiles' );
	$stats = ec_get_network_stats( $keys );

	if ( ! is_array( $stats ) ) {
		return array();
	}

	$values = array();

	foreach ( $keys as $key ) {
		if ( empty( $stats[ $key ] ) || ! is_array( $stats[ $key ] ) ) {
			continue;
		}

		$metric = $stats[ $key ];

		if ( empty( $metric['available'] ) || ! isset( $metric['value'] ) || ! is_numeric( $metric['value'] ) ) {
			continue;
		}

		$values[ $key ] = (int) $metric['value'];
	}

	return $values;
}
