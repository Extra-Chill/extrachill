<?php
/**
 * Provision the canonical Events site and its site-scoped booking schema.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

if ( ! is_multisite() ) {
	throw new RuntimeException( 'The booking schema integration test requires multisite.' );
}

if ( ! class_exists( 'DataMachineEvents\\Core\\EventDatesTable' ) ) {
	throw new RuntimeException( 'The activated Data Machine Events dependency is required.' );
}
DataMachineEvents\Core\EventDatesTable::create_table();

$factory          = new WP_UnitTest_Factory();
$fixture_site_ids = array();
register_shutdown_function(
	static function () use ( &$fixture_site_ids ): void {
		global $wpdb;

		foreach ( array_reverse( $fixture_site_ids ) as $site_id ) {
			$prefix = $wpdb->get_blog_prefix( $site_id );
			wp_delete_site( $site_id );

			// Core deletes its own site tables; remove site-scoped plugin tables too.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture cleanup must discover plugin tables.
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			foreach ( $tables as $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture cleanup for database-returned table names.
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		}
	}
);
while ( ! get_site( 7 ) ) {
	$created = $factory->blog->create();
	if ( is_wp_error( $created ) || (int) $created > 7 ) {
		throw new RuntimeException( 'Unable to provision the Events multisite test fixture.' );
	}
	$fixture_site_ids[] = (int) $created;
}
if ( ! wp_is_site_initialized( 7 ) ) {
	throw new RuntimeException( 'The Events multisite test fixture was not initialized.' );
}

require_once dirname( __DIR__, 2 ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__, 2 ) . '/inc/Core/PromoterAuthoritySchema.php';
switch_to_blog( 7 );
try {
	DataMachineEvents\Core\EventDatesTable::create_table();
	$booking_schema = ExtraChillEvents\Core\BookingSchema::install();
	if ( true !== $booking_schema ) {
		$failure = is_wp_error( $booking_schema ) ? $booking_schema->get_error_code() . ': ' . wp_json_encode( $booking_schema->get_error_data() ) : 'unknown error';
		throw new RuntimeException( 'Unable to provision the Events booking schema: ' . $failure );
	}
	$promoter_schema = ExtraChillEvents\Core\PromoterAuthoritySchema::install();
	if ( true !== $promoter_schema ) {
		$failure = is_wp_error( $promoter_schema ) ? $promoter_schema->get_error_code() . ': ' . wp_json_encode( $promoter_schema->get_error_data() ) : 'unknown error';
		throw new RuntimeException( 'Unable to provision the Events promoter authority schema: ' . $failure );
	}
} finally {
	restore_current_blog();
}
