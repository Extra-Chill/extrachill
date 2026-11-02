<?php
/**
 * Standalone cold-process contract for the P3 overlap and takeover.
 *
 * @package ExtraChillBooking\Tests
 */

require_once __DIR__ . '/fixtures/network-block-bootstrap.inc';

$booking_dir = dirname( __DIR__ );
$events_dir  = dirname( $booking_dir ) . '/extrachill-events';
require_once $booking_dir . '/extrachill-booking-network-blocks.php';

/**
 * Fail the standalone process on a broken contract.
 *
 * @param bool   $condition Expected true condition.
 * @param string $message Failure description.
 * @throws RuntimeException On a broken contract.
 */
function ec_booking_network_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only test assertion.
	}
}

$headers = get_plugin_data( $booking_dir . '/extrachill-booking-network-blocks.php' );
$main    = get_plugin_data( $booking_dir . '/extrachill-booking.php' );
ec_booking_network_check( 'Extra Chill Booking Network Blocks' === $headers['Plugin Name'], 'Plugin name' );
ec_booking_network_check( 'true' === $headers['Network'], 'Network header' );
ec_booking_network_check( $main['Version'] === $headers['Version'], 'Version matches booking' );
ec_booking_network_check( 'extrachill-booking' === $headers['Text Domain'], 'Text domain' );
ec_booking_network_check( 20 === $GLOBALS['booking_network_actions']['init'][0][1], 'Late init priority' );
ec_booking_network_check( 'extrachill_booking_activate_network_blocks' === $GLOBALS['booking_network_activation'], 'Activation hook' );
ec_booking_network_check( null === extrachill_booking_network_blocks_activation_error( true ), 'Valid activation' );
ec_booking_network_check( 'network_activation_required' === extrachill_booking_network_blocks_activation_error( false )->get_error_code(), 'Site activation rejected' );
$GLOBALS['booking_network_multisite'] = false;
ec_booking_network_check( 'network_activation_required' === extrachill_booking_network_blocks_activation_error( true )->get_error_code(), 'Single-site activation rejected' );
$GLOBALS['booking_network_multisite'] = true;
foreach ( array( 'extrachill-network/extrachill-network.php', 'extrachill-api/extrachill-api.php' ) as $missing ) {
	$GLOBALS['booking_network_plugins'] = array_diff( $GLOBALS['booking_network_required'], array( $missing ) );
	ec_booking_network_check( 'network_dependency_required' === extrachill_booking_network_blocks_activation_error( true )->get_error_code(), 'Missing network dependency rejected' );
}
try {
	extrachill_booking_activate_network_blocks( true );
	throw new LogicException( 'Activation must die without dependencies' );
} catch ( RuntimeException $error ) {
	ec_booking_network_check( false !== strpos( $error->getMessage(), 'Network-activate' ), 'Activation dies clearly' );
}
$GLOBALS['booking_network_plugins'] = $GLOBALS['booking_network_required'];

// Use a temporary compiled fixture only if the build is absent; never alter assets.
$build_dir = $events_dir . '/build/venue-booking-inquiry';
$created   = array();
$dirs      = array();
try {
	foreach ( array( $events_dir . '/build', $build_dir ) as $directory ) {
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Cold CLI fixture.
			$dirs[] = $directory;
		}
	}
	if ( ! file_exists( $build_dir . '/block.json' ) ) {
		copy( $events_dir . '/blocks/venue-booking-inquiry/block.json', $build_dir . '/block.json' );
		$created[] = $build_dir . '/block.json';
	}

	// Booking alone takes over from the same Events build directory.
	extrachill_booking_register_network_blocks();
	ec_booking_network_check( array( $build_dir ) === $GLOBALS['booking_network_registrations'], 'Takeover uses Events build' );
	ec_booking_network_check( WP_Block_Type_Registry::get_instance()->is_registered( 'extrachill/venue-booking-inquiry' ), 'Stable block name' );
	extrachill_booking_register_network_blocks();
	ec_booking_network_check( 1 === count( $GLOBALS['booking_network_registrations'] ), 'Pre-registered block is untouched' );

	// Loading both files shares the exact same class declarations (require_once).
	require_once $events_dir . '/extrachill-events-network-blocks.php';
	foreach ( array( 'BookingAttachmentPolicy', 'BookingAttachmentReadiness', 'VenueBookingConfig' ) as $class ) {
		$reflection = new ReflectionClass( 'ExtraChillEvents\\Core\\' . $class );
		ec_booking_network_check( realpath( $events_dir . '/inc/Core/' . $class . '.php' ) === $reflection->getFileName(), 'Shared Events class path' );
	}
	$GLOBALS['booking_network_registrations'] = array();
	$GLOBALS['booking_network_registered']    = array();
	// Run the callbacks in actual init priority order, Events first.
	usort(
		$GLOBALS['booking_network_actions']['init'],
		static function ( $a, $b ) {
			return $a[1] <=> $b[1];
		}
	);
	foreach ( $GLOBALS['booking_network_actions']['init'] as $init_callback ) {
		call_user_func( $init_callback[0] );
	}
	ec_booking_network_check( array( $build_dir ) === $GLOBALS['booking_network_registrations'], 'Overlap registers exactly once' );
	ec_booking_network_check( 0 === $GLOBALS['booking_network_wrong'], 'No incorrect usage notices' );

	define( 'EXTRACHILL_EVENTS_PLUGIN_FILE', $events_dir . '/extrachill-events.php' );
	$GLOBALS['booking_network_events_site']   = true;
	$GLOBALS['booking_network_registered']    = array();
	$GLOBALS['booking_network_registrations'] = array();
	extrachill_booking_register_network_blocks();
	ec_booking_network_check( array() === $GLOBALS['booking_network_registrations'], 'Events site skipped' );
} finally {
	foreach ( $created as $file ) {
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only generated CLI fixture.
	}
	foreach ( array_reverse( $dirs ) as $directory ) {
		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only generated CLI fixture.
	}
}
echo "Booking network block handover passed.\n";
