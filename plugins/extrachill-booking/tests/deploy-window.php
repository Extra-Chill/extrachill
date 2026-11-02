<?php
/** Standalone old-Events-adapter then new-Booking bootstrap regression. */
// phpcs:disable -- Isolated process harness rewrites fixture source, as in bootstrap-boundary.php.
$events  = dirname( __DIR__, 2 ) . '/extrachill-events';
$booking = dirname( __DIR__ );
$fixture = file_get_contents( $events . '/tests/fixtures/bootstrap-matrix.php' );
// Separate physical files are essential: require_once cannot hide a redeclaration.
$legacy = sys_get_temp_dir() . '/booking-deploy-' . bin2hex( random_bytes( 8 ) );
mkdir( $legacy . '/inc/Core', 0777, true );
foreach ( array( 'BookingAttachmentPolicy', 'BookingAttachmentReadiness', 'VenueBookingConfig' ) as $class ) {
 copy( $booking . '/inc/Core/' . $class . '.php', $legacy . '/inc/Core/' . $class . '.php' );
}
copy( __DIR__ . '/fixtures/legacy-events/inquiry-adapter.php', $legacy . '/inquiry-adapter.php' );
$load = 'require ' . var_export( $legacy . '/inquiry-adapter.php', true ) . ";\n";
$load .= 'require ' . var_export( $events . '/extrachill-events.php', true ) . ";\n";
$load .= 'require ' . var_export( $booking . '/extrachill-booking.php', true ) . ";\n";
$load .= 'foreach ( $GLOBALS["bootstrap_matrix_hooks"] as $hook ) { if ( "plugins_loaded" === $hook[0] && 5 === $hook[2] ) { call_user_func( $hook[1] ); } }';
$fixture = str_replace( "require dirname( __DIR__, 2 ) . '/extrachill-events.php';", $load, $fixture );
$fixture = str_replace( "__DIR__ . '/'", var_export( $events . '/tests/fixtures/', true ), $fixture );
file_put_contents( $legacy . '/runtime.php', $fixture );
exec( PHP_BINARY . ' ' . escapeshellarg( $legacy . '/runtime.php' ) . ' normal', $output, $status );
foreach ( glob( $legacy . '/inc/Core/*.php' ) as $file ) { unlink( $file ); }
rmdir( $legacy . '/inc/Core' );
rmdir( $legacy . '/inc' );
unlink( $legacy . '/inquiry-adapter.php' );
unlink( $legacy . '/runtime.php' );
rmdir( $legacy );
$result = json_decode( implode( "\n", $output ), true );
if ( $status || empty( $result['booking_registered'] ) || $result['provider_failures'] ) {
 throw new RuntimeException( 'Deploy-window bootstrap failed: ' . json_encode( $result ) );
}
// Verify site affinity and duplicate registration independently of WordPress.
define( 'ABSPATH', __DIR__ );
define( 'EXTRACHILL_BOOKING_PLUGIN_DIR', $booking . '/' );
function add_action() {}
function add_filter() {}
function ec_is_events_site() { return $GLOBALS['events_site']; }
class WP_Block_Type_Registry {
 public $registered = false;
 public static function get_instance() { static $registry; return $registry ?? ( $registry = new self() ); }
 public function is_registered( $name ) { return $this->registered; }
}
function register_block_type( $directory ) {
 if ( $directory !== EXTRACHILL_BOOKING_PLUGIN_DIR . 'build/venue-booking-inquiry' ) { throw new RuntimeException( 'Wrong block owner' ); }
 $GLOBALS['registrations']++;
 WP_Block_Type_Registry::get_instance()->registered = true;
}
require $booking . '/inc/core/booking-ui.php';
$GLOBALS['registrations'] = 0;
$GLOBALS['events_site'] = false;
extrachill_events_register_booking_blocks();
if ( $GLOBALS['registrations'] ) { throw new RuntimeException( 'Registered outside events' ); }
$GLOBALS['events_site'] = true;
extrachill_events_register_booking_blocks();
extrachill_events_register_booking_blocks();
if ( 1 !== $GLOBALS['registrations'] ) { throw new RuntimeException( 'Missing or duplicate block registration (build Booking first)' ); }
echo "Old Events adapter then new Booking bootstrap and duplicate block guard passed.\n";
