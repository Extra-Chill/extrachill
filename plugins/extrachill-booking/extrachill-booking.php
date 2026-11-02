<?php
/**
 * Plugin Name: Extra Chill Booking
 * Description: Venue booking operations for Extra Chill.
 * Version: 0.2.0
 * Requires PHP: 8.4
 * Requires Plugins: extrachill-events
 * Text Domain: extrachill-booking
 * License: GPL-2.0-or-later
 *
 * @package ExtraChillBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_BOOKING_PLUGIN_DIR', __DIR__ . '/' );
define( 'EXTRACHILL_BOOKING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Load after all active plugin entry files, before schema and abilities. */
function extrachill_booking_bootstrap() {
	if ( ! class_exists( '\\ExtraChillEvents\\Providers\\VenueProvider' )
		|| class_exists( '\\ExtraChillEvents\\Providers\\BookingProvider', false ) ) {
		return;
	}
	require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Providers/BookingProvider.php';
	return \ExtraChillEvents\Providers\BookingProvider::register();
}
add_action( 'plugins_loaded', 'extrachill_booking_bootstrap', 5 );

register_activation_hook( __FILE__, static function () {
	if ( true !== extrachill_booking_bootstrap() ) {
		return;
	}
	if ( class_exists( '\\ExtraChillEvents\\Core\\VenueSchema' )
		&& class_exists( '\\ExtraChillEvents\\Core\\BookingSchema', false ) ) {
		\ExtraChillEvents\Core\VenueSchema::install();
		\ExtraChillEvents\Core\BookingSchema::install();
	}
} );
