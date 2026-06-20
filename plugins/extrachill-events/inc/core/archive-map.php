<?php
/**
 * Archive Map Renderer
 *
 * Single shared entry point for rendering the data-machine-events/events-map
 * block on taxonomy archive pages (location / venue / artist). Each archive
 * differs only in the distinctive attributes it passes (zoom, route mode); the
 * common attributes — collapse capability and reduced height — live here so
 * there is exactly one place that builds the block string and calls do_blocks().
 *
 * Collapse behaviour:
 *   Gardner's calendar feedback (data-machine-events#373) was that the 400px
 *   map dominated the archive and pushed the event list far down the page. The
 *   events-map block now ships its own block-native, accessible collapse
 *   capability (data-machine-events#377: the `collapsible` / `defaultCollapsed`
 *   attributes render a real <button aria-expanded> toggle and correctly handle
 *   Leaflet's invalidateSize on expand). We adopt that here by passing
 *   `collapsible: true`, and deliberately leave the map OPEN by default
 *   (`defaultCollapsed` defaults false) — the map is visible but the reader can
 *   collapse it. Combined with the reduced height below, the map reads as a
 *   secondary element rather than the page hero.
 *
 *   This replaces the previous EC-side collapse workaround (a custom wrapper +
 *   assets/css/archive-map.css + assets/js/archive-map.js) that existed only
 *   because the block could not collapse itself. The block now owns that job.
 *
 * @package ExtraChillEvents
 * @since 0.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduced map height (px) used for archive maps.
 *
 * The events-map block defaults to 400px. Archives pass this smaller value so
 * the map is non-dominant even when expanded. This is independent of the
 * block's collapse capability — a smaller-but-visible map.
 *
 * @return int Height in pixels.
 */
function extrachill_events_archive_map_height(): int {
	/**
	 * Filter the archive map height.
	 *
	 * @param int $height Height in pixels. Default 280.
	 */
	return (int) apply_filters( 'extrachill_events_archive_map_height', 280 );
}

/**
 * Render the events-map block for a taxonomy archive.
 *
 * Builds the events-map block markup once, merging the caller's distinctive
 * attributes with the common archive attributes (block-native `collapsible`
 * and the reduced `height`). Callers (location / venue / artist) only supply
 * what makes their map different — e.g. `zoom` for venue, or
 * `chronologicalRouteMode` for artist.
 *
 * Common attributes applied here:
 *   - collapsible: true  — adopt the block's own collapse toggle (#377).
 *   - height: reduced     — see extrachill_events_archive_map_height().
 *
 * The map is left OPEN by default (no `defaultCollapsed`), so the toggle lets
 * the reader collapse it rather than starting collapsed.
 *
 * @param array  $attrs   Caller-specific events-map block attributes. Merged
 *                        over the common archive defaults (caller wins on
 *                        conflict, except this is not expected for the common
 *                        keys).
 * @param string $context Archive context slug (location|venue|artist). Reserved
 *                        for future per-archive tweaks; currently unused by the
 *                        block but kept for caller clarity and forward use.
 * @return string Rendered events-map block markup.
 */
function extrachill_events_render_archive_map( array $attrs = array(), string $context = '' ): string {
	unset( $context );

	$defaults = array(
		'collapsible' => true,
		'height'      => extrachill_events_archive_map_height(),
	);

	// Caller attrs win on conflict; common archive defaults fill the rest.
	$merged = array_merge( $defaults, $attrs );

	$block = sprintf(
		'<!-- wp:data-machine-events/events-map %s /-->',
		wp_json_encode( $merged )
	);

	return do_blocks( $block );
}
