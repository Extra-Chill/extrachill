<?php
/**
 * Venue booking inquiry block render.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\VenueBookingConfig;

defined( 'ABSPATH' ) || exit;

$venue_id = absint( $attributes['venueId'] ?? 0 );
if ( $venue_id < 1 && function_exists( 'extrachill_events_get_venue_archive_term' ) ) {
	$archive_venue = extrachill_events_get_venue_archive_term();
	$venue_id      = $archive_venue ? (int) $archive_venue->term_id : 0;
}
$venue = $venue_id > 0 ? get_term( $venue_id, 'venue' ) : null;
if ( ! $venue instanceof WP_Term || 'venue' !== $venue->taxonomy || ! class_exists( VenueBookingConfig::class ) ) {
	return;
}

$booking_config = ( new VenueBookingConfig() )->get( $venue_id );
if ( is_wp_error( $booking_config ) || empty( $booking_config['enabled'] ) ) {
	return;
}

$supported_types = array( 'text', 'textarea', 'email', 'phone', 'number', 'select', 'checkbox', 'url' );
foreach ( $booking_config['intake']['fields'] as $field ) {
	if ( ! in_array( $field['type'], $supported_types, true ) ) {
		return;
	}
}

$venue_data = function_exists( 'data_machine_events_get_venue_data' ) ? data_machine_events_get_venue_data( $venue_id ) : array();
$address    = function_exists( 'data_machine_events_get_venue_address' ) ? data_machine_events_get_venue_address( $venue_id, $venue_data ) : '';
$instance   = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ec-booking-' ) : 'ec-booking-' . $venue_id;
$logged_in  = is_user_logged_in();
if ( $logged_in ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
}
if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

$public_config = array(
	'instanceId'    => $instance,
	'endpoint'      => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-inquiries' ),
	'restNonce'     => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
	'authenticated' => $logged_in,
	'heading'       => sanitize_text_field( (string) ( $attributes['heading'] ?? __( 'Booking inquiries', 'extrachill-events' ) ) ),
	'buttonLabel'   => sanitize_text_field( (string) ( $attributes['buttonLabel'] ?? __( 'Send booking inquiry', 'extrachill-events' ) ) ),
	'revision'      => (int) $booking_config['revision'],
	'venue'         => array(
		'id'          => $venue_id,
		'name'        => $venue->name,
		'description' => wp_strip_all_tags( term_description( $venue_id ) ),
		'address'     => $address,
	),
	'requirements'  => array_values( $booking_config['public_requirements'] ),
	'spaces'        => array_values( $booking_config['spaces'] ),
	'fields'        => array_values( $booking_config['intake']['fields'] ),
	'consent'       => $booking_config['consent'],
);

$json = wp_json_encode( $public_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
if ( false === $json ) {
	return;
}
?>
<section <?php echo get_block_wrapper_attributes( array( 'class' => 'ec-venue-booking-inquiry' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes block wrapper attributes. ?>>
	<div data-booking-app></div>
	<script type="application/json"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX flags make the script payload inert. ?></script>
	<div data-booking-turnstile>
		<?php
		if ( function_exists( 'ec_render_turnstile_widget' ) ) {
			echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'always' ) ) );
		} else {
			esc_html_e( 'Security challenge unavailable. Please contact the venue directly.', 'extrachill-events' );
		}
		?>
	</div>
</section>
