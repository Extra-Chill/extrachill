<?php
/**
 * Events Homepage Location Badges
 *
 * Displays a badge for every city with enough upcoming events, plus a
 * "See every event" link to the full /all calendar. Lets visitors jump
 * straight to a city's calendar.
 *
 * @package ExtraChillEvents
 * @since 0.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
$request->set_query_params(
	array(
		'taxonomy' => 'location',
	)
);

$response = rest_do_request( $request );

if ( $response->is_error() ) {
	return;
}

$location_counts = $response->get_data();

if ( empty( $location_counts ) || ! is_array( $location_counts ) ) {
	return;
}

$location_counts = extrachill_events_prepare_location_rows( $location_counts );

if ( empty( $location_counts ) ) {
	return;
}

extrachill_events_render_home_market_router( $location_counts );
