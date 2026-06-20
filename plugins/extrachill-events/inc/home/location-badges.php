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

$min_events      = (int) apply_filters( 'extrachill_events_badge_min_count', 20 );
$location_counts = array_filter(
	$location_counts,
	static function ( $location ) use ( $min_events ) {
		return $location['count'] >= $min_events;
	}
);

if ( empty( $location_counts ) ) {
	return;
}
?>
	<div class="taxonomy-badges ec-edge-gutter">
	<?php foreach ( $location_counts as $location ) : ?>
		<a href="<?php echo esc_url( $location['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $location['slug'] ); ?>">
			<?php echo esc_html( $location['name'] ); ?> (<?php echo esc_html( $location['count'] ); ?>)
		</a>
	<?php endforeach; ?>
	</div>

	<p class="events-browse-all-cities">
		<a href="<?php echo esc_url( home_url( '/all/' ) ); ?>">
			<?php esc_html_e( 'See every event &rarr;', 'extrachill-events' ); ?>
		</a>
	</p>
