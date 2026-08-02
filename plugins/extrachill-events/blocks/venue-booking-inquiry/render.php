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
if ( $venue_id < 1 && (int) get_current_blog_id() === $events_blog_id && function_exists( 'extrachill_events_get_venue_archive_term' ) ) {
	$archive_venue = extrachill_events_get_venue_archive_term();
	$venue_id      = $archive_venue ? (int) $archive_venue->term_id : 0;
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
		if ( is_wp_error( $booking_config ) || ( empty( $booking_config['enabled'] ) && empty( $booking_config['booking_guide']['entries'] ) ) ) {
			return null;
		}

		$supported_types = array( 'text', 'textarea', 'email', 'phone', 'number', 'select', 'checkbox', 'url', 'url_list' );
		foreach ( $booking_config['fields'] as $field ) {
			if ( ! in_array( $field['type'], $supported_types, true ) ) {
				return null;
			}
		}

		if ( function_exists( 'data_machine_events_get_venue_data' ) && function_exists( 'data_machine_events_get_venue_address' ) ) {
			$venue_data = data_machine_events_get_venue_data( $venue_id );
			$address    = data_machine_events_get_venue_address( $venue_id, $venue_data );
		} else {
			$street     = (string) get_term_meta( $venue_id, '_venue_address', true );
			$city       = (string) get_term_meta( $venue_id, '_venue_city', true );
			$state      = (string) get_term_meta( $venue_id, '_venue_state', true );
			$zip        = (string) get_term_meta( $venue_id, '_venue_zip', true );
			$city_state = implode( ', ', array_filter( array( $city, $state ) ) );
			$address    = implode( ', ', array_filter( array( $street, $city_state, $zip ) ) );
		}

		return array(
			'booking_config' => $booking_config,
			'venue'          => array(
				'id'          => $venue_id,
				'name'        => $venue->name,
				'description' => wp_strip_all_tags( $venue->description ),
				'address'     => $address,
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

$booking_config       = $canonical['booking_config'];
$instance             = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ec-booking-' ) : 'ec-booking-' . $venue_id;
$logged_in            = is_user_logged_in();
$form_enabled         = ! empty( $booking_config['enabled'] );
$guide_entries        = $booking_config['booking_guide']['entries'];
$manage_link_page_url = function_exists( 'ec_get_site_url' ) ? trailingslashit( ec_get_site_url( 'artist' ) ) . 'manage-link-page/' : '';
$link_page_count      = $logged_in && function_exists( 'ec_get_link_page_count_for_user' ) ? ec_get_link_page_count_for_user( get_current_user_id() ) : 0;
$link_page_url        = $manage_link_page_url;
if ( ! $logged_in && '' !== $manage_link_page_url && function_exists( 'ec_users_login_url_with_redirect' ) && function_exists( 'ec_get_site_url' ) ) {
	$link_page_url = ec_users_login_url_with_redirect( trailingslashit( ec_get_site_url( 'community' ) ) . 'login/', $manage_link_page_url );
}
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}
nocache_headers();
if ( $form_enabled ) {
	if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
		ec_enqueue_turnstile_script();
	}
}

$public_config = array(
	'instanceId'           => $instance,
	'endpoint'             => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-inquiries' ),
	'availabilityEndpoint' => rest_url( 'extrachill/v1/venues/' . $venue_id . '/booking-availability' ),
	'restNonce'            => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
	'authenticated'        => $logged_in,
	'heading'              => sanitize_text_field( (string) ( $attributes['heading'] ?? __( 'Booking inquiries', 'extrachill-events' ) ) ),
	'buttonLabel'          => sanitize_text_field( (string) ( $attributes['buttonLabel'] ?? __( 'Send booking inquiry', 'extrachill-events' ) ) ),
	'revision'             => (int) $booking_config['revision'],
	'venue'                => $canonical['venue'],
	'requirements'         => array_values( $booking_config['public_requirements'] ),
	'spaces'               => array_values( $booking_config['spaces'] ),
	'fields'               => array_values( $booking_config['fields'] ),
	'presentation'         => $booking_config['presentation'],
	'consent'              => $booking_config['consent'],
	'linkPage'             => array(
		'url'           => (string) $link_page_url,
		'hasPage'       => $link_page_count > 0,
		'authenticated' => $logged_in,
	),
);

$json             = $form_enabled ? wp_json_encode( $public_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) : '';
$guide_heading_id = $instance . '-guide-title';
if ( $form_enabled && false === $json ) {
	return;
}
?>
<section <?php echo get_block_wrapper_attributes( array( 'class' => 'ec-venue-booking-inquiry' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes block wrapper attributes. ?>>
	<?php if ( ! empty( $guide_entries ) ) : ?>
		<div class="ec-booking-guide" aria-labelledby="<?php echo esc_attr( $guide_heading_id ); ?>">
			<h2 id="<?php echo esc_attr( $guide_heading_id ); ?>"><?php esc_html_e( 'Booking guide', 'extrachill-events' ); ?></h2>
			<p><?php esc_html_e( 'Venue-provided information to review before sending a booking inquiry.', 'extrachill-events' ); ?></p>
			<div class="ec-booking-guide__entries">
				<?php foreach ( $guide_entries as $entry ) : ?>
					<article class="ec-booking-guide__entry" data-guide-key="<?php echo esc_attr( $entry['key'] ); ?>">
						<h3><?php echo esc_html( $entry['title'] ); ?></h3>
						<?php echo wp_kses_post( wpautop( esc_html( $entry['body'] ) ) ); ?>
					</article>
				<?php endforeach; ?>
			</div>
			<?php if ( $form_enabled ) : ?>
				<p class="ec-booking-guide__communication"><?php esc_html_e( 'For details not covered here, send an inquiry below so communication stays with the booking request.', 'extrachill-events' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php if ( $form_enabled ) : ?>
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
	<?php endif; ?>
</section>
