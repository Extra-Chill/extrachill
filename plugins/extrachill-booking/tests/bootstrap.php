<?php
/**
 * PHPUnit bootstrap: reuse the Events harness, then point booking at its own tree.
 *
 * @package ExtraChillBooking
 */

require_once dirname( __DIR__, 2 ) . '/extrachill-events/tests/bootstrap.php';

if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_DIR' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/extrachill-events/' );
}
if ( ! defined( 'EXTRACHILL_BOOKING_PLUGIN_DIR' ) ) {
	define( 'EXTRACHILL_BOOKING_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
