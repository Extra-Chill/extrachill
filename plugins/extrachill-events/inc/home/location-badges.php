<?php
/**
 * Events Homepage Location Badges
 *
 * Displays location badges with upcoming event counts for easy city filtering.
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

$min_events      = apply_filters( 'extrachill_events_badge_min_count', 20 );
$location_counts = array_filter( $location_counts, function ( $location ) use ( $min_events ) {
	return $location['count'] >= $min_events;
} );

if ( empty( $location_counts ) ) {
	return;
}
?>
<div class="taxonomy-badges">
	<?php foreach ( $location_counts as $location ) : ?>
		<a href="<?php echo esc_url( $location['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $location['slug'] ); ?>">
			<?php echo esc_html( $location['name'] ); ?> (<?php echo esc_html( $location['count'] ); ?>)
		</a>
	<?php endforeach; ?>
</div>
