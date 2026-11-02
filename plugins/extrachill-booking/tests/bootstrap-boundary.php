<?php
// phpcs:disable -- Standalone process harness: rewrites fixture source and evaluates it in child PHP processes.
/** Standalone combined and mixed-deploy bootstrap proof. */
$events = dirname( __DIR__, 2 ) . '/extrachill-events';
$fixture = file_get_contents( $events . '/tests/fixtures/bootstrap-matrix.php' );
$fixture = str_replace( "require dirname( __DIR__, 2 ) . '/extrachill-events.php';", "require " . var_export( $events . '/extrachill-events.php', true ) . ";\nrequire " . var_export( dirname( __DIR__ ) . '/extrachill-booking.php', true ) . ";\nforeach ( \$GLOBALS['bootstrap_matrix_hooks'] as \$hook ) { if ( 'plugins_loaded' === \$hook[0] && 5 === \$hook[2] ) { call_user_func( \$hook[1] ); } }", $fixture );
$fixture = str_replace( "__DIR__ . '/'", var_export( $events . '/tests/fixtures/', true ), $fixture );
$fixture = str_replace( "'booking_registered'       =>", "'booking_block_registration' => in_array( 'extrachill_events_register_booking_blocks', array_column( \$GLOBALS['bootstrap_matrix_hooks'], 1 ), true ),\n'booking_filters' => array_values( array_intersect( array( 'extrachill_events_ability_classes', 'extrachill_events_venue_settings_login_redirect', 'extrachill_events_venue_settings_venue_data', 'extrachill_events_venue_settings_boot_data' ), array_column( \$GLOBALS['bootstrap_matrix_hooks'], 0 ) ) ),\n'booking_registered'       =>", $fixture );
$temp = tempnam( sys_get_temp_dir(), 'booking-bootstrap-' );
file_put_contents( $temp, $fixture );
exec( PHP_BINARY . ' ' . escapeshellarg( $temp ) . ' normal', $output, $status );
unlink( $temp );
$result = json_decode( implode( "\n", $output ), true );
if ( $status || empty( $result['booking_registered'] ) || count( $result['booking_filters'] ?? array() ) !== 4 || empty( $result['booking_block_registration'] ) || $result['provider_failures'] ) {
 throw new RuntimeException( 'Combined bootstrap failed: ' . json_encode( $result ) );
}
// Separate process: old Events owns booking. New booking must attach no domain hooks.
$guard = '<?php define("ABSPATH", __DIR__); function plugin_dir_url($f){return "";} function add_action(...$a){$GLOBALS["hooks"][]=$a;} function register_activation_hook(){} class OldBooking {} class Venue {} class_alias("OldBooking", "ExtraChillEvents\\Providers\\BookingProvider"); class_alias("Venue", "ExtraChillEvents\\Providers\\VenueProvider"); require ' . var_export( dirname( __DIR__ ) . '/extrachill-booking.php', true ) . '; extrachill_booking_bootstrap(); if(count($GLOBALS["hooks"])!==1){exit(1);}';
$temp = tempnam( sys_get_temp_dir(), 'booking-guard-' );
file_put_contents( $temp, $guard );
passthru( PHP_BINARY . ' ' . escapeshellarg( $temp ), $status );
unlink( $temp );
if ( $status ) { throw new RuntimeException( 'Mixed-deploy guard failed' ); }
// No Events active: the same entrypoint must load and leave the domain inert.
$guard = str_replace( 'class_alias("Venue", "ExtraChillEvents\\Providers\\VenueProvider");', '', $guard );
$guard = str_replace( 'class_alias("OldBooking", "ExtraChillEvents\\Providers\\BookingProvider");', '', $guard );
$temp = tempnam( sys_get_temp_dir(), 'booking-no-events-' );
file_put_contents( $temp, $guard );
passthru( PHP_BINARY . ' ' . escapeshellarg( $temp ), $status );
unlink( $temp );
if ( $status ) { throw new RuntimeException( 'Events-inactive guard failed' ); }
echo "Combined bootstrap, mixed-deploy and Events-inactive guards passed.\n";
