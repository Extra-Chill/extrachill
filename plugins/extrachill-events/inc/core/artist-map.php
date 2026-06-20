<?php
/**
 * Artist Map
 *
 * Renders the data-machine-events/events-map block on artist taxonomy
 * archive pages in chronological-route mode — a date-ordered polyline
 * connecting the artist's upcoming-tour venues so a fan can see the
 * tour footprint at a glance.
 *
 * Mirrors `location-map.php` / `venue-map.php`: the extrachill layer
 * only decides *whether* and *in what mode* to render the block on this
 * archive context. All map logic, venue fetching, polyline drawing,
 * residency collapsing, first/last marker styling, and empty-route
 * hiding live in the generic data-machine-events events-map block.
 *
 * Backend wiring is automatic: on an artist archive the block's
 * render.php seeds `taxonomy=artist` + `term_id` from the queried
 * object, and chronological-route mode makes the frontend request the
 * opt-in `include=events` payload. VenueMapAbilities then attaches
 * `upcoming_events_at_venue` per venue (chronological ascending) because
 * taxonomy + term_id + include_events are all present. No EC-side
 * query_args / venues filter is needed for the artist archive — unlike
 * the `/my-shows/` page, which has no taxonomy/term context and must
 * supply its own payload.
 *
 * @package ExtraChillEvents
 * @since 0.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the events-map block in chronological-route mode on artist archives.
 *
 * Gating:
 *   - Only on `is_tax( 'artist' )`.
 *   - Only when the artist has at least 2 distinct upcoming venues — a
 *     route needs at least two stops. Uses the transient-cached
 *     `extrachill_events_get_term_calendar_stats()` (already computed for
 *     the stats line rendered just above this hook) so the gate adds no
 *     extra query. The block applies a second, stricter guard
 *     client-side (>= 2 venues that actually have coordinates) and hides
 *     itself when that is not met, so venues missing geo never produce a
 *     broken single-point map.
 *
 * @hook extrachill_archive_below_description
 */
function extrachill_events_render_artist_map() {
	if ( ! is_tax( 'artist' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return;
	}

	// Cheap gate: need >= 2 upcoming venues to draw a route. The block's
	// own coordinate-aware guard handles the final hide when venues lack
	// geo, but this avoids emitting the block markup + booting Leaflet for
	// artists with a single (or zero) upcoming venue.
	if ( function_exists( 'extrachill_events_get_term_calendar_stats' ) ) {
		$stats = extrachill_events_get_term_calendar_stats( 'artist', (int) $term->term_id );
		if ( (int) ( $stats['venues'] ?? 0 ) < 2 ) {
			return;
		}
	}

	// Render the block in chronological-route mode via the shared archive-map
	// helper. The block reads taxonomy/term_id from its own render context
	// (is_tax + queried object) and auto-fits to the route's bounding box. The
	// helper applies the block-native collapse toggle (#377, open by default)
	// and the reduced height so the route map stays secondary to the tour-date
	// list (data-machine-events#373), consistent with the location/venue
	// archives.
	echo extrachill_events_render_archive_map( array( 'chronologicalRouteMode' => true ), 'artist' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted block markup from do_blocks().
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_render_artist_map' );

/**
 * Suppress the map summary on artist archives.
 *
 * The archive header already renders a canonical upcoming-events stats
 * line ("N upcoming events at N venues in N locations") via
 * `extrachill_events_render_term_calendar_stats()`. Showing the same
 * counts inside the map's summary slot would duplicate the number on the
 * page, so return an empty string. The map itself still renders — only
 * its overlay summary text is dropped. Mirrors the location/venue
 * archive treatment.
 *
 * @hook data_machine_events_map_summary
 * @param string $summary Current summary.
 * @param array  $venues  Venue data array (empty — dynamic mode).
 * @param array  $context Map context.
 * @return string Summary text. Empty on artist archives.
 */
function extrachill_events_filter_artist_map_summary( string $summary, array $venues, array $context ): string {
	if ( ! ( $context['is_taxonomy'] ?? false ) || 'artist' !== ( $context['taxonomy'] ?? '' ) ) {
		return $summary;
	}

	// Counts live in the archive-header stats line; the map stands alone.
	return '';
}
add_filter( 'data_machine_events_map_summary', 'extrachill_events_filter_artist_map_summary', 10, 3 );
