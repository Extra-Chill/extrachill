<?php
/** Bootstrap the native-process WordPress concurrency proof. */

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
require_once __DIR__ . '/booking-attachment-mysql-bootstrap.php';
require_once getenv( 'WP_TESTS_DIR' ) . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/extrachill-events.php';
	}
);

require getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';

$dme_directory = rtrim( (string) getenv( 'DME_PLUGIN_DIR' ), '/\\' );
if ( '' !== $dme_directory ) {
	foreach (
		array(
			'/inc/Core/EventDatesTable.php',
			'/inc/Core/Event_Post_Type.php',
			'/inc/Core/Venue_Taxonomy.php',
			'/inc/Abilities/AbilityCategories.php',
			'/inc/Abilities/VenueIntervalOverlapAbilities.php',
			'/inc/public-api.php',
		) as $relative_path
	) {
		$file = $dme_directory . $relative_path;
		if ( ! is_file( $file ) ) {
			throw new RuntimeException( 'The native hold proof requires the current DME public overlap contract: ' . $relative_path );
		}
		require_once $file;
	}
	DataMachineEvents\Core\EventDatesTable::create_table();
	if ( ! DataMachineEvents\Core\EventDatesTable::table_exists() ) {
		throw new RuntimeException( 'The native hold proof requires the DME event dates table.' );
	}
	if ( ! function_exists( 'data_machine_events_query_venue_interval_overlaps' ) ) {
		throw new RuntimeException( 'The native hold proof requires the DME venue interval-overlap function.' );
	}
}
