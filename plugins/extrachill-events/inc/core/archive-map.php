<?php
/**
 * Archive Map Collapsible Wrapper
 *
 * Shared presentation layer for the events-map block when it appears on
 * taxonomy archive pages (location / venue / artist). Gardner's calendar
 * feedback (data-machine-events#373) was that the 400px map dominated the
 * Charleston location archive and pushed the actual event list far down the
 * page. This helper wraps the block markup in a collapsible, height-reduced
 * container so the map is available but no longer the dominant element.
 *
 * The wrapper:
 *   - Renders a real <button aria-expanded> toggle ("Show map" / "Hide map").
 *   - Defaults COLLAPSED — the list is the priority, the map is opt-in.
 *   - Collapses with max-height + overflow (NOT display:none) so Leaflet keeps
 *     a layout box and never boots zero-sized. On first expand a synthetic
 *     window `resize` event is dispatched so Leaflet's built-in resize handler
 *     calls invalidateSize() and the tiles paint correctly.
 *   - Passes a reduced height (280px) to the events-map block so even when
 *     expanded the map reads as a secondary element, not the page hero.
 *
 * EC-side only: the events-map block itself is unchanged — we only pass its
 * existing `height` attribute and wrap its output. No data-machine-events or
 * theme changes are required.
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
 * the map is non-dominant even when expanded.
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
 * Wrap events-map block markup in a collapsible, height-reduced container.
 *
 * Callers pass the rendered block HTML (already produced via do_blocks()).
 * This keeps each archive map renderer in control of WHICH block attributes
 * it sets (zoom, chronologicalRouteMode, etc.) while sharing the collapse UI.
 *
 * @param string $map_html  Rendered events-map block markup.
 * @param string $archive   Archive context slug (location|venue|artist) for styling hooks.
 * @return string Wrapped markup, or empty string if no map HTML was provided.
 */
function extrachill_events_render_collapsible_map( string $map_html, string $archive = '' ): string {
	$map_html = trim( $map_html );
	if ( '' === $map_html ) {
		return '';
	}

	$panel_id    = 'extrachill-events-archive-map-' . ( $archive ? sanitize_html_class( $archive ) : 'panel' );
	$context_cls = $archive ? ' is-' . sanitize_html_class( $archive ) : '';

	ob_start();
	?>
	<div class="extrachill-events-archive-map<?php echo esc_attr( $context_cls ); ?>" data-collapsible-map>
		<button type="button"
				class="extrachill-events-archive-map__toggle"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $panel_id ); ?>">
			<span class="extrachill-events-archive-map__toggle-label"><?php esc_html_e( 'Show map', 'extrachill-events' ); ?></span>
			<span class="extrachill-events-archive-map__toggle-icon dashicons dashicons-location-alt" aria-hidden="true"></span>
		</button>
		<div id="<?php echo esc_attr( $panel_id ); ?>" class="extrachill-events-archive-map__panel" hidden>
			<?php echo $map_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted block markup from do_blocks(). ?>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Enqueue the archive-map collapse assets.
 *
 * Loads on any taxonomy archive where one of the map renderers may output a
 * map (location / venue / artist). The toggle script + styles are tiny and
 * self-contained; they no-op when no collapsible map is present on the page.
 *
 * @hook wp_enqueue_scripts
 */
function extrachill_events_archive_map_assets() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;
	if ( (int) get_current_blog_id() !== $events_blog_id ) {
		return;
	}

	if ( ! is_tax( 'location' ) && ! is_tax( 'venue' ) && ! is_tax( 'artist' ) ) {
		return;
	}

	$css_path = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/archive-map.css';
	$js_path  = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/js/archive-map.js';

	wp_enqueue_style(
		'extrachill-events-archive-map',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/archive-map.css',
		array(),
		file_exists( $css_path ) ? (string) filemtime( $css_path ) : EXTRACHILL_EVENTS_VERSION
	);

	wp_enqueue_script(
		'extrachill-events-archive-map',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/archive-map.js',
		array(),
		file_exists( $js_path ) ? (string) filemtime( $js_path ) : EXTRACHILL_EVENTS_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_archive_map_assets' );
