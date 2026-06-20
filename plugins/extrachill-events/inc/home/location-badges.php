<?php
/**
 * Events Homepage Location Badges
 *
 * Homepage router: shows the top N cities by upcoming-event count as badges,
 * with a "Browse all cities" link to the full /location/ directory. Keeps the
 * homepage glanceable instead of rendering every qualifying city (147+).
 *
 * @package ExtraChillEvents
 * @since 0.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Number of city badges to surface on the homepage router.
 *
 * @since 0.24.0
 */
$top_n = (int) apply_filters( 'extrachill_events_home_badge_limit', 25 );

$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
$request->set_query_params(
	array(
		'taxonomy' => 'location',
		'limit'    => $top_n,
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
?>
	<div class="taxonomy-badges ec-edge-gutter">
	<?php foreach ( $location_counts as $location ) : ?>
		<a href="<?php echo esc_url( $location['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $location['slug'] ); ?>">
			<?php echo esc_html( $location['name'] ); ?> (<?php echo esc_html( $location['count'] ); ?>)
		</a>
	<?php endforeach; ?>
	</div>

	<p class="events-browse-all-cities">
		<a href="<?php echo esc_url( home_url( '/location/' ) ); ?>">
			<?php esc_html_e( 'Browse all cities &rarr;', 'extrachill-events' ); ?>
		</a>
	</p>
