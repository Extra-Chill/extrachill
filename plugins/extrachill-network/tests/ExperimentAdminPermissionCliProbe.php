<?php
/**
 * Standalone WP-CLI experiment admin permission probe.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );

function add_action() {}
function current_user_can() {
	return false;
}

require dirname( __DIR__ ) . '/inc/core/experiments.php';

if ( ! extrachill_experiment_admin_permission() ) {
	fwrite( STDERR, "WP-CLI user zero was denied experiment administration.\n" );
	exit( 1 );
}

fwrite( STDOUT, "Experiment WP-CLI permission probe passed.\n" );
exit( 0 );
