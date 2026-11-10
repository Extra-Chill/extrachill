<?php
/** Load the shared booking fake without its retired Events schema dependency. */
$harness = dirname( __DIR__, 3 ) . '/extrachill-booking/tests/Support/BookingTestHarness.php';
$source = file_get_contents( $harness );
$source = preg_replace( '~^require_once .*?/inc/Core/VenueSchema\.php\x27;\R~m', '', $source );
// Preserve the shared harness's relative paths while keeping this adapter in Events.
$source = str_replace( '__DIR__', var_export( dirname( $harness ), true ), $source );
eval( '?>' . $source );
unset( $harness, $source );
