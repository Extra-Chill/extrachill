<?php
/**
 * Location Map
 *
 * Renders a Leaflet map on location archive pages showing all venues
 * in that city with their coordinates. Map centers on the location's
 * geo tag and auto-fits to show all venue markers.
 *
 * @package ExtraChillEvents
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render venue map on location archive pages.
 *
 * Hooked to extrachill_archive_below_description which fires in the
 * archive template between the header and the calendar block.
 *
 * @hook extrachill_archive_below_description
 */
function extrachill_events_render_location_map() {
	if ( ! is_tax( 'location' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return;
	}

	// Get location center coordinates.
	$center = extrachill_events_get_location_coordinates( $term->term_id );
	if ( ! $center ) {
		return;
	}

	// Get all venues in this location.
	$venues = extrachill_events_get_location_venues( $term->term_id );

	// Build venue data for JS.
	$venue_data = array();
	foreach ( $venues as $venue ) {
		$venue_data[] = array(
			'name'    => $venue['name'],
			'lat'     => $venue['lat'],
			'lon'     => $venue['lon'],
			'address' => $venue['address'],
			'url'     => $venue['url'],
		);
	}

	$map_type = 'osm-standard';
	if ( class_exists( 'DataMachineEvents\Admin\Settings_Page' ) ) {
		$map_type = \DataMachineEvents\Admin\Settings_Page::get_map_display_type();
	}

	$map_id = 'location-venue-map-' . $term->term_id;
	?>
	<div class="location-map-container">
		<div
			id="<?php echo esc_attr( $map_id ); ?>"
			class="location-venue-map"
			data-center-lat="<?php echo esc_attr( $center['lat'] ); ?>"
			data-center-lon="<?php echo esc_attr( $center['lon'] ); ?>"
			data-map-type="<?php echo esc_attr( $map_type ); ?>"
			data-venues="<?php echo esc_attr( wp_json_encode( $venue_data ) ); ?>"
		></div>
		<?php if ( ! empty( $venues ) ) : ?>
			<p class="location-map-venue-count">
				<?php
				printf(
					/* translators: %d: number of venues */
					esc_html( _n( '%d venue', '%d venues', count( $venues ), 'extrachill-events' ) ),
					count( $venues )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_render_location_map' );

/**
 * Enqueue Leaflet and location map assets on location archive pages.
 *
 * @hook wp_enqueue_scripts
 */
function extrachill_events_enqueue_location_map_assets() {
	if ( ! is_tax( 'location' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return;
	}

	// Only load if this location has coordinates.
	$center = extrachill_events_get_location_coordinates( $term->term_id );
	if ( ! $center ) {
		return;
	}

	// Leaflet CSS & JS (same versions as datamachine-events).
	wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
	wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

	// Location map CSS.
	wp_enqueue_style(
		'extrachill-events-location-map',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/location-map.css',
		array( 'leaflet' ),
		EXTRACHILL_EVENTS_VERSION
	);

	// Location map JS.
	wp_enqueue_script(
		'extrachill-events-location-map',
		EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/location-map.js',
		array( 'leaflet' ),
		EXTRACHILL_EVENTS_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_enqueue_location_map_assets' );
