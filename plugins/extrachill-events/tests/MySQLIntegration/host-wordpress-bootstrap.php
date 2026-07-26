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
