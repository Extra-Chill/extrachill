<?php
/** Standalone P2b ownership guard. Run: php tests/booking-boundary.php */
$root = dirname( __DIR__ );
$owned = json_decode( file_get_contents( __DIR__ . '/fixtures/booking-owned-paths.json' ), true, 512, JSON_THROW_ON_ERROR );
// P3 only: three shared dependencies, the network block and its two registration references.
$allow = array(
 'inc/Core/BookingAttachmentPolicy.php',
 'inc/Core/BookingAttachmentReadiness.php',
 'inc/Core/VenueBookingConfig.php',
 'blocks/venue-booking-inquiry/',
 'extrachill-events-network-blocks.php',
 'tests/NetworkBookingBlockColdTest.php',
);
$violations = array();
foreach ( $owned as $path ) {
 if ( in_array( $path, $allow, true ) || str_starts_with( $path, 'blocks/venue-booking-inquiry/' ) ) { continue; }
 if ( file_exists( $root . '/' . $path ) ) { $violations[] = 'Booking-owned file remains: ' . $path; }
}
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
 $path = substr( $file->getPathname(), strlen( $root ) + 1 );
 if ( preg_match( '#^(vendor|node_modules|build|tests|docs)/#', $path ) || str_ends_with( $path, '.test.js' ) || ! in_array( $file->getExtension(), array( 'php', 'js' ), true ) || in_array( $path, $allow, true ) || str_starts_with( $path, 'blocks/venue-booking-inquiry/' ) ) { continue; }
 if ( preg_match_all( '/\b(?:Booking\w*|VenueBooking\w*|ArtistBookingInquiry\w*|TicketSettlement\w*|ShowSettlement\w*|TicketReconciliation\w*|LocalBooking\w*|ec_events_get_booking_\w*)\b/', file_get_contents( $file->getPathname() ), $matches ) ) {
  $violations[] = $path . ': ' . implode( ', ', array_unique( $matches[0] ) );
 }
}
if ( $violations ) { fwrite( STDERR, implode( "\n", $violations ) . "\n" ); exit( 1 ); }
echo "Booking boundary passed.\n";
