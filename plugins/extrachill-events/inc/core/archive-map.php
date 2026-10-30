<?php
/**
 * Archive Map Renderer
 *
 * Single shared entry point for rendering the data-machine-events/events-map
 * block on taxonomy archive pages (location / venue / artist). Each archive
 * differs only in the distinctive attributes it passes (zoom, route mode); the
 * common attributes — collapse behaviour and expanded height — live here so
 * there is exactly one place that builds the block string and calls do_blocks().
 *
 * Collapse behaviour (#847):
 *   The map previously rendered OPEN above the calendar at a reduced 280px
 *   height (data-machine-events#373/#377), where it still pushed the first
 *   event card roughly 1.75 screens down on mobile once the sign-in prompts
 *   above it were counted in. Archives now pass `defaultCollapsed: true` so
 *   the map costs nothing above the listings, and the whole block moved BELOW
 *   the calendar (`extrachill_archive_below_calendar`) so even a persisted-open
 *   map can never bury the events again.
 *
 *   Because collapsed-by-default removes the "map dominates the page"
 *   constraint that forced the small height (#373), the expanded height is
 *   raised to 560px — tall enough to browse a city's venue clusters without
 *   panning, while still leaving roughly a third of a phone viewport visible
 *   above it. The expanded/collapsed choice persists across archives via
 *   localStorage (assets/js/archive-map.js): a reader who opens the map once
 *   does not have to reopen it on every city, venue, or artist page. The
 *   persistence script re-opens persisted-open maps by clicking the block's
 *   own toggle, which routes through the block's expand path (deferred React
 *   mount → Leaflet initializes inside the now-visible container), so no grey
 *   tiles and no duplicated collapse logic on the consumer side.
 *
 * @package ExtraChillEvents
 * @since 0.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expanded map height (px) used for archive maps.
 *
 * The events-map block defaults to 400px. Archives now render the map
 * collapsed by default (#847), so height only applies to the opt-in expanded
 * state and can be generous again: 560px gives enough vertical room to read a
 * city's venue geography without panning, at the cost of nothing above the
 * event listings.
 *
 * @return int Height in pixels.
 */
function extrachill_events_archive_map_height(): int {
	/**
	 * Filter the archive map height.
	 *
	 * @param int $height Height in pixels. Default 560.
	 */
	return (int) apply_filters( 'extrachill_events_archive_map_height', 560 );
}

/**
 * Build the common archive events-map block attributes.
 *
 * Exposed separately from rendering so tests can assert the attribute
 * contract without invoking the block pipeline.
 *
 * @param array $attrs Caller-specific events-map block attributes. Merged
 *                     over the common archive defaults (caller wins on
 *                     conflict, except this is not expected for the common
 *                     keys).
 * @return array Merged block attributes.
 */
function extrachill_events_archive_map_block_attributes( array $attrs = array() ): array {
	$defaults = array(
		'collapsible'      => true,
		'defaultCollapsed' => true,
		'height'           => extrachill_events_archive_map_height(),
	);

	// Caller attrs win on conflict; common archive defaults fill the rest.
	return array_merge( $defaults, $attrs );
}

/**
 * Render the events-map block for a taxonomy archive.
 *
 * Builds the events-map block markup once, merging the caller's distinctive
 * attributes with the common archive attributes (block-native `collapsible`
 * + `defaultCollapsed` and the expanded `height`). Callers (location / venue
 * / artist) only supply what makes their map different — e.g. `zoom` for
 * venue, or `chronologicalRouteMode` for artist.
 *
 * Common attributes applied here:
 *   - collapsible: true       — block-native, accessible collapse toggle.
 *   - defaultCollapsed: true  — collapsed by default (#847); persistence in
 *                               assets/js/archive-map.js re-opens it for
 *                               returning readers.
 *   - height: expanded        — see extrachill_events_archive_map_height().
 *
 * @param array  $attrs   Caller-specific events-map block attributes.
 * @param string $context Archive context slug (location|venue|artist). Reserved
 *                        for future per-archive tweaks; currently unused by the
 *                        block but kept for caller clarity and forward use.
 * @return string Rendered events-map block markup.
 */
function extrachill_events_render_archive_map( array $attrs = array(), string $context = '' ): string {
	unset( $context );

	$block = sprintf(
		'<!-- wp:data-machine-events/events-map %s /-->',
		wp_json_encode( extrachill_events_archive_map_block_attributes( $attrs ) )
	);

	return do_blocks( $block );
}

/**
 * Enqueue the archive map persistence script on archive types that render a map.
 *
 * The events-map block ships its own collapse toggle; this script only owns
 * the consumer-side persistence of the reader's choice (#847): it stores the
 * open/closed state in localStorage and re-opens persisted-open maps on later
 * archives by clicking the block's own toggle, so the block's expand path
 * (deferred mount + invalidateSize) stays the single source of truth.
 *
 * @hook wp_enqueue_scripts
 */
function extrachill_events_enqueue_archive_map_assets(): void {
	if ( ! is_tax( array( 'location', 'venue', 'artist' ) ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-events-archive-map',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/archive-map.js',
		array(),
		EXTRACHILL_EVENTS_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_enqueue_archive_map_assets' );
