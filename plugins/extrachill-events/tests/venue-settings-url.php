<?php
/** Standalone canonical URL compatibility test. */
define( 'ABSPATH', __DIR__ );
function ec_get_blog_id( $site ) { return $GLOBALS['events_blog_id']; }
function home_url( $path ) { return 'https://fallback.example' . $path; }
function get_home_url( $blog_id, $path ) { return 'https://events.example' . $path; }
function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function add_query_arg( $args, $url ) {
	$parts = explode( '#', $url, 2 );
	$base = explode( '?', $parts[0], 2 );
	$query = array();
	parse_str( $base[1] ?? '', $query );
	return $base[0] . '?' . http_build_query( array_merge( $query, $args ) ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
}
require dirname( __DIR__ ) . '/inc/core/venue-settings.php';
require dirname( __DIR__ ) . '/inc/core/booking-console.php';
foreach ( array( 0, 7 ) as $events_blog_id ) {
	$GLOBALS['events_blog_id'] = $events_blog_id;
	foreach ( array( array( 0, 0 ), array( 44, 0 ), array( 44, 91 ) ) as [ $venue_id, $booking_id ] ) {
		// The original console implementation, frozen to detect output drift.
		$base_url = $events_blog_id > 0 ? get_home_url( $events_blog_id, '/venue-settings/' ) : home_url( '/venue-settings/' );
		$args = array();
		if ( $venue_id > 0 ) {
			$args['venue_id'] = $venue_id;
		}
		if ( $booking_id > 0 ) {
			$args['booking_id'] = $booking_id;
		}
		$old = ( $args ? add_query_arg( $args, $base_url ) : $base_url ) . '#tab-calendar';
		if ( $old !== ec_events_get_booking_console_url( $venue_id, $booking_id ) ) {
			throw new RuntimeException( 'Console URL changed.' );
		}
		if ( ! $booking_id && $old !== ec_events_get_venue_settings_url( $venue_id, 'calendar' ) ) {
			throw new RuntimeException( 'Venue URL changed.' );
		}
		if ( str_replace( '#tab-calendar', '#tab-claims', ec_events_get_booking_console_url( $venue_id ) ) !== ec_events_get_venue_claim_review_url( $venue_id ) ) {
			throw new RuntimeException( 'Claim review URL changed.' );
		}
	}
}
echo "Venue settings URLs are byte-identical.\n";
