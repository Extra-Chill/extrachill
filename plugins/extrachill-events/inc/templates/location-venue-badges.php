<?php
/**
 * Location Archive Venue Badges
 *
 * Displays venue badges with upcoming event counts on each location taxonomy
 * archive, scoped to events in that location. Mirrors the homepage location
 * badge graph one level deeper for navigability and internal linking from a
 * city archive to its active venues.
 *
 * @package ExtraChillEvents
 * @since 0.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$term = get_queried_object();
if ( ! $term || ! isset( $term->slug, $term->taxonomy ) || 'location' !== $term->taxonomy ) {
	return;
}

$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
$request->set_query_params(
	array(
		'taxonomy'      => 'venue',
		'location_slug' => $term->slug,
	)
);

$response = rest_do_request( $request );

if ( $response->is_error() ) {
	return;
}

$venue_counts = $response->get_data();

if ( empty( $venue_counts ) || ! is_array( $venue_counts ) ) {
	return;
}

/**
 * Filter the minimum number of upcoming events a venue must have to render
 * as a badge on a location archive. Tunable separately from the homepage
 * location badge threshold because per-city venue counts are smaller. The
 * default of 3 keeps active venues visible while filtering out one-off
 * shows that would otherwise crowd the badge graph in dense markets.
 *
 * @since 0.22.0
 *
 * @param int      $min_events Minimum upcoming event count. Default 3.
 * @param \WP_Term $term       Current location term.
 */
$min_events   = apply_filters( 'extrachill_events_venue_badge_min_count', 3, $term );
$venue_counts = array_filter(
	$venue_counts,
	function ( $venue ) use ( $min_events ) {
		return isset( $venue['count'] ) && $venue['count'] >= $min_events;
	}
);

if ( empty( $venue_counts ) ) {
	return;
}
?>
	<div class="taxonomy-badges ec-edge-gutter location-archive-venue-badges">
	<?php foreach ( $venue_counts as $venue ) : ?>
		<a href="<?php echo esc_url( $venue['url'] ); ?>" class="taxonomy-badge venue-badge venue-<?php echo esc_attr( $venue['slug'] ); ?>">
			<?php echo esc_html( $venue['name'] ); ?> (<?php echo esc_html( $venue['count'] ); ?>)
		</a>
	<?php endforeach; ?>
	</div>
