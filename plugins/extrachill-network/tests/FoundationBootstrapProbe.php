<?php
/**
 * Child-process probe for the independently bootable foundation.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'EXTRACHILL_NETWORK_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

function get_site_option( $name, $default = false ) {
	unset( $name );
	return $default;
}

function add_action( ...$args ) {
	unset( $args );
}

function add_filter( ...$args ) {
	unset( $args );
}

function apply_filters( $name, $value ) {
	unset( $name );
	return $value;
}

require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Foundation/bootstrap.php';

extrachill_network_boot_foundation();
extrachill_network_boot_foundation();

$required_functions = array(
	'ec_get_blog_ids',
	'ec_get_site_url',
	'ec_cross_site_rest_request',
	'ec_cross_site_build_service_assertion_headers',
	'ec_resolve_frontend_paths',
	'ec_send_email',
);

foreach ( $required_functions as $function ) {
	if ( ! function_exists( $function ) ) {
		fwrite( STDERR, "Missing foundation API: {$function}\n" );
		exit( 1 );
	}
}

echo "PASS: foundation boots independently and idempotently\n";
