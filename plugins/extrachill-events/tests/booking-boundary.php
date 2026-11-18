<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI guard runs without WordPress loaded.
/** Standalone booking ownership guard. Run: php tests/booking-boundary.php */
$root       = dirname( __DIR__ );
$owned      = json_decode( file_get_contents( __DIR__ . '/fixtures/booking-owned-paths.json' ), true, 512, JSON_THROW_ON_ERROR );
$violations = array();
foreach ( $owned as $owned_path ) {
	if ( file_exists( $root . '/' . $owned_path ) ) {
		$violations[] = 'Booking-owned file remains: ' . $owned_path; }
}
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	$rel_path = substr( $file->getPathname(), strlen( $root ) + 1 );
	if ( preg_match( '#^(vendor|node_modules|build|tests|docs)/#', $rel_path ) || str_ends_with( $rel_path, '.test.js' ) || ! in_array( $file->getExtension(), array( 'php', 'js' ), true ) ) {
		continue; }
	if ( preg_match_all( '/\b(?:Booking\w*|VenueBooking\w*|ArtistBookingInquiry\w*|TicketSettlement\w*|ShowSettlement\w*|TicketReconciliation\w*|LocalBooking\w*|ec_events_get_booking_\w*)\b/', file_get_contents( $file->getPathname() ), $matches ) ) {
		$violations[] = $rel_path . ': ' . implode( ', ', array_unique( $matches[0] ) );
	}
}
if ( $violations ) {
	fwrite( STDERR, implode( "\n", $violations ) . "\n" );
	exit( 1 ); }
echo "Booking boundary passed.\n";
