<?php
/**
 * Venue booking inquiry block render.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\VenueBookingConfig;

defined( 'ABSPATH' ) || exit;

$events_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : 0;
$venue_id       = absint( $attributes['venueId'] ?? 0 );
if ( $venue_id < 1 && (int) get_current_blog_id() === $events_blog_id && is_tax( 'venue' ) ) {
	$archive_venue = get_queried_object();
	$venue_id      = $archive_venue instanceof WP_Term && 'venue' === $archive_venue->taxonomy ? (int) $archive_venue->term_id : 0;
}
if ( $events_blog_id < 1 || $venue_id < 1 || ! class_exists( VenueBookingConfig::class ) ) {
	return;
}

$canonical = ( static function () use ( $events_blog_id, $venue_id ) {
	$switched = (int) get_current_blog_id() !== $events_blog_id;
	if ( $switched ) {
		if ( ! is_multisite() || ! get_site( $events_blog_id ) ) {
			return null;
		}
		switch_to_blog( $events_blog_id );
	}

	try {
		$venue = get_term( $venue_id, 'venue' );
		if ( ! $venue instanceof WP_Term || 'venue' !== $venue->taxonomy ) {
			return null;
		}

		$booking_config = ( new VenueBookingConfig() )->get_public_projection( $venue_id );
		if ( is_wp_error( $booking_config ) || empty( $booking_config['enabled'] ) ) {
			return null;
		}

		$supported_types = array( 'text', 'textarea', 'email', 'phone', 'number', 'select', 'checkbox', 'url', 'url_list' );
		foreach ( $booking_config['fields'] as $field ) {
			if ( ! in_array( $field['type'], $supported_types, true ) ) {
				return null;
			}
		}

		$profile  = function_exists( 'data_machine_events_get_venue_profile' ) ? data_machine_events_get_venue_profile( $venue_id ) : array();
		$logo_url = is_array( $profile ) ? (string) ( $profile['logo_url'] ?? '' ) : '';
		$logo_url = filter_var( $logo_url, FILTER_VALIDATE_URL ) ? $logo_url : '';

		return array(
			'booking_config' => $booking_config,
			'venue'          => array(
				'id'          => $venue_id,
				'name'        => $venue->name,
				'description' => wp_strip_all_tags( $venue->description ),
				'logoUrl'     => $logo_url,
			),
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
} )();
if ( ! is_array( $canonical ) ) {
	return;
}

$booking_config = $canonical['booking_config'];
$instance       = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ec-booking-' ) : 'ec-booking-' . $venue_id;
$heading_level  = absint( $attributes['headingLevel'] ?? 2 );
$heading_level  = $heading_level >= 1 && $heading_level <= 6 ? $heading_level : 2;
$logged_in      = is_user_logged_in();
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}
nocache_headers();
if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

$public_config = array(
	'instanceId'           => $instance,
	'headingLevel'         => $heading_level,
	'endpoint'             => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-inquiries' ),
	'availabilityEndpoint' => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-availability' ),
	'followThrough'        => array(
		'status'          => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-inquiries/follow-through/status' ),
		'correction'      => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-inquiries/follow-through/correction' ),
		'withdrawal'      => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-inquiries/follow-through/withdrawal' ),
		'receiptRecovery' => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-inquiries/follow-through/receipt-recovery' ),
	),
	'restNonce'            => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
	'buttonLabel'          => sanitize_text_field( (string) ( $attributes['buttonLabel'] ?? __( 'Send booking inquiry', 'extrachill-events' ) ) ),
	'revision'             => (int) $booking_config['revision'],
	'venue'                => $canonical['venue'],
	'spaces'               => array_values( $booking_config['spaces'] ),
	'fields'               => array_values( $booking_config['fields'] ),
	'consent'              => $booking_config['consent'],
	'attachments'          => $booking_config['attachments'],
);

$json = wp_json_encode( $public_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
if ( false === $json ) {
	return;
}
$wrapper_extra = array(
	'class'           => 'ec-venue-booking-inquiry',
	'aria-labelledby' => $instance . '-heading',
);
if ( (int) get_current_blog_id() === $events_blog_id && is_tax( 'venue' ) ) {
	$wrapper_extra['id'] = 'booking-inquiry';
}
$wrapper_attributes = get_block_wrapper_attributes( $wrapper_extra );
?>
<section <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes block wrapper attributes. ?>>
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
