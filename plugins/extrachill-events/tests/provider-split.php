<?php
/**
 * Standalone provider-split guard (no WordPress or MySQL).
 *
 * Venue infrastructure must load on its own from VenueProvider, and booking
 * must only build on it: BookingProvider loads no venue-owned file, and the
 * plugin registers the venue provider before the booking provider.
 *
 * Run: php tests/provider-split.php
 */

$root     = dirname( __DIR__ );
$failures = array();

$venue   = file_get_contents( $root . '/inc/Providers/VenueProvider.php' );
$booking = file_get_contents( $root . '/../extrachill-booking/inc/Providers/BookingProvider.php' );
$plugin  = file_get_contents( $root . '/inc/Core/Plugin.php' );
$owned   = json_decode( file_get_contents( __DIR__ . '/fixtures/booking-owned-paths.json' ), true, 512, JSON_THROW_ON_ERROR );

$required_venue = array(
	'VenueSchema',
	'VenueMembershipRepository',
	'VenueAuthorization',
	'VenueMembershipService',
	'VenueInvitationToken',
	'VenueOnboardingRepository',
	'VenueOnboardingService',
	'VenueCalendarFeed',
	'VenueCalendarFeedSync',
	'VenueInvitationDeliveryWorker',
	'VenueLock',
	'VenueProfile',
	'CanonicalEventPublicationGuard',
);
foreach ( $required_venue as $class ) {
	if ( false === strpos( $venue, "'" . $class . "'" ) ) {
		$failures[] = "VenueProvider does not load {$class}.";
	}
	if ( preg_match( '#inc/Core/' . $class . '\.php#', $booking ) ) {
		$failures[] = "BookingProvider loads venue-owned {$class}.";
	}
}

foreach ( array( 'VenueInvitationDeliveryWorker::register()', 'VenueCalendarFeedSync::register()', 'new \\ExtraChillEvents\\Core\\CanonicalEventPublicationGuard()' ) as $hook ) {
	if ( false === strpos( $venue, $hook ) ) {
		$failures[] = "VenueProvider does not register {$hook}.";
	}
	if ( false !== strpos( $booking, $hook ) ) {
		$failures[] = "BookingProvider registers venue-owned {$hook}.";
	}
}

if ( preg_match( '/\b(?:Booking\w*|VenueBooking\w*|TicketSettlement\w*|ShowSettlement\w*)\b/', $venue, $match ) ) {
	$failures[] = "VenueProvider references booking symbol {$match[0]}.";
}

if ( preg_match_all( '/VenueProvider::(\w+)\(/', $booking, $calls ) && array( 'register' ) !== array_values( array_unique( $calls[1] ) ) ) {
	$failures[] = 'BookingProvider may only call VenueProvider::register().';
}

preg_match_all( "#require_once EXTRACHILL_EVENTS_PLUGIN_DIR \\. '([^']+)'#", $booking, $loads );
foreach ( $loads[1] as $path ) {
	if ( ! in_array( $path, $owned, true ) ) {
		$failures[] = "BookingProvider loads {$path}, which is not in tests/fixtures/booking-owned-paths.json.";
	}
}

$venue_at   = strpos( $plugin, 'VenueProvider::register()' );
$booking_at = strpos( $plugin, 'BookingProvider::register()' );
if ( false === $venue_at || false !== $booking_at ) {
	$failures[] = 'Plugin must register VenueProvider before BookingProvider.';
}

if ( $failures ) {
	fwrite( STDERR, "Provider split violations:\n" . implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "Venue and booking providers are split correctly.\n";
