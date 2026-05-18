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

// Re-key as a list after filtering for predictable iteration.
$venue_counts = array_values( $venue_counts );

// Honor the priority-venue system: bubble priority venues to the front of
// the badge graph while preserving count-descending order within each tier.
// Priority venues are an editorial signal — venues we want surfaced regardless
// of raw upcoming-event volume. The calendar applies the same priority signal
// to event ordering via ec_events_reorder_by_priority(); badges should match.
$priority_venue_ids = function_exists( 'ec_get_priority_venue_ids' )
	? ec_get_priority_venue_ids()
	: array();

if ( ! empty( $priority_venue_ids ) ) {
	usort(
		$venue_counts,
		function ( $a, $b ) use ( $priority_venue_ids ) {
			$a_priority = in_array( (int) ( $a['term_id'] ?? 0 ), $priority_venue_ids, true );
			$b_priority = in_array( (int) ( $b['term_id'] ?? 0 ), $priority_venue_ids, true );

			if ( $a_priority !== $b_priority ) {
				return $a_priority ? -1 : 1;
			}

			// Same tier — preserve count-descending order from the ability.
			$a_count = (int) ( $a['count'] ?? 0 );
			$b_count = (int) ( $b['count'] ?? 0 );

			return $b_count <=> $a_count;
		}
	);
}
?>
	<div class="taxonomy-badges ec-edge-gutter location-archive-venue-badges">
	<?php
	foreach ( $venue_counts as $venue ) :
		$is_priority    = ! empty( $priority_venue_ids ) && in_array( (int) ( $venue['term_id'] ?? 0 ), $priority_venue_ids, true );
		$priority_class = $is_priority ? ' venue-badge-priority' : '';
		?>
		<a href="<?php echo esc_url( $venue['url'] ); ?>" class="taxonomy-badge venue-badge venue-<?php echo esc_attr( $venue['slug'] ); ?><?php echo esc_attr( $priority_class ); ?>">
			<?php echo esc_html( $venue['name'] ); ?> (<?php echo esc_html( $venue['count'] ); ?>)
		</a>
	<?php endforeach; ?>
	</div>
