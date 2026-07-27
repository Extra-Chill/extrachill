<?php
/**
 * PHPUnit bootstrap for Extra Chill API integration tests.
 *
 * @package ExtraChill\API\Tests
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir ) {
	$tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $tests_dir . '/includes/functions.php';

/** Load the plugin inside the WordPress test runtime. */
function extrachill_api_load_test_plugin() {
	require dirname( __DIR__ ) . '/extrachill-api.php';
}

tests_add_filter( 'muplugins_loaded', 'extrachill_api_load_test_plugin' );

require $tests_dir . '/includes/bootstrap.php';
