<?php
/** Standalone booking dependency guard. Run: php tests/booking-boundary.php */
$root = dirname( __DIR__ );
$owned = json_decode( file_get_contents( __DIR__ . '/fixtures/booking-owned-paths.json' ), true, 512, JSON_THROW_ON_ERROR );
// Temporary P1d exceptions: remove individually as registry/lifecycle work lands.
$exceptions = array(
	'inc/Core/Plugin.php', // Composition root: single booking entry point.
	'extrachill-events.php', // Composition root: provider include.
	'extrachill-events-network-blocks.php', // P3: network block extraction.
	'inc/Providers/LifecycleProvider.php', // Remaining schema lifecycle extraction.
	'inc/Providers/AbilitiesProvider.php', // Remaining ability lifecycle extraction.
	'blocks/venue-settings/render.php', // Remaining render-data registry work.
	'inc/Core/VenueLinkPages.php', // Remaining console URL registry work.
	'inc/home/feature-cards.php', // Remaining console URL registry work.
);
$violations = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	$path = substr( $file->getPathname(), strlen( $root ) + 1 );
	if ( preg_match( '#^(vendor|node_modules|build|tests|docs)/#', $path ) || 'php' !== $file->getExtension() || in_array( $path, $owned, true ) || in_array( $path, $exceptions, true ) ) {
		continue;
	}
	if ( preg_match_all( '/\b(?:Booking\w*|VenueBooking\w*|ArtistBookingInquiry\w*|TicketSettlement\w*|ShowSettlement\w*|TicketReconciliation\w*|LocalBooking\w*|ec_events_get_booking_\w*)\b/', file_get_contents( $file->getPathname() ), $matches ) ) {
		$violations[] = $path . ': ' . implode( ', ', array_unique( $matches[0] ) );
	}
}
if ( $violations ) {
	fwrite( STDERR, "Booking boundary violations:\n" . implode( "\n", $violations ) . "\n" );
	exit( 1 );
}
echo "Booking boundary passed.\n";
