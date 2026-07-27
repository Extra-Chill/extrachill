<?php
/** Provision the canonical Events site and its site-scoped booking schema. */

if ( ! is_multisite() ) {
	throw new RuntimeException( 'The booking schema integration test requires multisite.' );
}

while ( ! get_site( 7 ) ) {
	$next_id = get_sites( array( 'count' => true ) ) + 1;
	$created = wpmu_create_blog( 'site-' . $next_id . '.example.org', '/', 'Test Site ' . $next_id, 1 );
	if ( is_wp_error( $created ) || (int) $created > 7 ) {
		throw new RuntimeException( 'Unable to provision the Events multisite test fixture.' );
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingSchema.php';
switch_to_blog( 7 );
try {
	$booking_schema = ExtraChillEvents\Core\BookingSchema::install();
	if ( true !== $booking_schema ) {
		$failure = is_wp_error( $booking_schema ) ? $booking_schema->get_error_code() . ': ' . wp_json_encode( $booking_schema->get_error_data() ) : 'unknown error';
		throw new RuntimeException( 'Unable to provision the Events booking schema: ' . $failure );
	}
} finally {
	restore_current_blog();
}
