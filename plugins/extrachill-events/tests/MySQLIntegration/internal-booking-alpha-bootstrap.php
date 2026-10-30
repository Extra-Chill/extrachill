<?php
/** Provision the canonical Events site before the managed alpha test starts. */

if ( ! is_multisite() ) {
	throw new RuntimeException( 'The internal booking calendar alpha requires multisite.' );
}

while ( ! get_site( 7 ) ) {
	$next_id = count( get_sites( array( 'fields' => 'ids' ) ) ) + 1;
	$created = wpmu_create_blog( 'site-' . $next_id . '.example.org', '/', 'Test Site ' . $next_id, 1 );
	if ( is_wp_error( $created ) || (int) $created > 7 ) {
		throw new RuntimeException( 'Unable to provision the canonical Events test site.' );
	}
}

switch_to_blog( 7 );
try {
	$booking_schema = ExtraChillEvents\Core\BookingSchema::install();
	if ( is_wp_error( $booking_schema ) ) {
		throw new RuntimeException( $booking_schema->get_error_code() . ': ' . wp_json_encode( $booking_schema->get_error_data() ) );
	}
	DataMachineEvents\Core\EventDatesTable::create_table();
	( new DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex() )->create_table();
	if ( class_exists( 'ActionScheduler_StoreSchema' ) && class_exists( 'ActionScheduler_LoggerSchema' ) ) {
		( new ActionScheduler_StoreSchema() )->register_tables();
		( new ActionScheduler_LoggerSchema() )->register_tables();
	}
} finally {
	restore_current_blog();
}
