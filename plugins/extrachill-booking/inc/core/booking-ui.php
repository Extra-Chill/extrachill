<?php
/**
 * Booking-owned adapters for Events venue surfaces.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register the inquiry block as part of the booking feature (its build stays in Events until P3). */
function extrachill_events_register_booking_blocks() {
	if ( ! ec_is_events_site() ) {
		return;
	}
	$directory = EXTRACHILL_EVENTS_PLUGIN_DIR . 'build/venue-booking-inquiry';
	if ( file_exists( $directory . '/block.json' ) ) {
		register_block_type( $directory );
	}
}
add_action( 'init', 'extrachill_events_register_booking_blocks' );

/**
 * Attach the booking-settings entry before the venue-settings shell executes.
 *
 * @param array $metadata Block metadata.
 * @return array
 */
function extrachill_events_booking_settings_metadata( $metadata ) {
	if ( 'extrachill/venue-settings' !== ( $metadata['name'] ?? '' ) ) {
		return $metadata;
	}
	$asset_path = EXTRACHILL_BOOKING_PLUGIN_DIR . 'build/venue-settings/booking-settings.asset.php';
	if ( ! file_exists( $asset_path ) ) {
		return $metadata;
	}
	$asset = require $asset_path;
	wp_register_script(
		'extrachill-booking-settings',
		EXTRACHILL_BOOKING_PLUGIN_URL . 'build/venue-settings/booking-settings.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);
	// WordPress preserves viewScript order when enqueuing the block's scripts.
	$metadata['viewScript'] = array_merge( array( 'extrachill-booking-settings' ), (array) ( $metadata['viewScript'] ?? array() ) );
	return $metadata;
}
add_filter( 'block_type_metadata', 'extrachill_events_booking_settings_metadata' );

/**
 * Render the unchanged opt-in booking CTA in the venue archive header.
 *
 * @param WP_Term $term Venue term.
 */
function extrachill_events_render_venue_archive_booking_action( $term ) {
	$booking_config = ( new \ExtraChillEvents\Core\VenueBookingConfig() )->get_public_projection( (int) $term->term_id );
	if ( is_wp_error( $booking_config ) || empty( $booking_config['enabled'] ) ) {
		return;
	}
	$booking_url = \ExtraChillEvents\Core\VenueBookingEmbed::booking_url( $term );
	if ( ! $booking_url ) {
		return;
	}
	?>
	<p class="venue-booking-cta"><a class="button-1 button-medium" href="<?php echo esc_url( $booking_url ); ?>"><?php esc_html_e( 'Submit a booking inquiry', 'extrachill-events' ); ?></a>
	<?php if ( function_exists( 'extrachill_events_docs_url' ) ) : ?>
		<a href="<?php echo esc_url( extrachill_events_docs_url( 'venue-booking-inquiries' ) ); ?>"><?php esc_html_e( 'How booking inquiries work', 'extrachill-events' ); ?></a>
	<?php endif; ?>
	</p>
	<?php
}
add_action( 'extrachill_events_venue_archive_actions', 'extrachill_events_render_venue_archive_booking_action' );

/** Render the inquiry form beneath the venue calendar. */
function extrachill_events_render_venue_archive_booking_inquiry( $term ) {
	?>
	<div class="page-content">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted block with an integer term identifier.
		echo do_blocks( '<!-- wp:extrachill/venue-booking-inquiry {"venueId":' . absint( $term->term_id ) . '} /-->' );
		?>
	</div>
	<?php
}
add_action( 'extrachill_events_venue_archive_after_calendar', 'extrachill_events_render_venue_archive_booking_inquiry' );
