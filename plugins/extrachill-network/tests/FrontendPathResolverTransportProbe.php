<?php
/**
 * Exercises one real large JSON POST through the cross-site HTTP helper.
 *
 * @package ExtraChillNetwork
 */

require_once dirname( __DIR__ ) . '/inc/core/blog-ids.php';
require_once dirname( __DIR__ ) . '/inc/core/frontend-path-resolver.php';
require_once dirname( __DIR__ ) . '/inc/core/cross-site-rest.php';

$paths  = array_fill( 0, EC_FRONTEND_PATH_RESOLVER_MAX_PATHS, '/' . str_repeat( 'a', 100 ) );
$result = ec_cross_site_rest_request_http(
	'events',
	'POST',
	'/wp/v2/posts',
	array(
		'body'    => array( 'paths' => $paths ),
		'timeout' => 5,
	)
);

if ( ! is_wp_error( $result ) ) {
	throw new RuntimeException( 'Expected the unauthenticated core POST route to reject the transport probe.' );
}

$error_data = $result->get_error_data();
if ( 'rest_cannot_create' !== $result->get_error_code() || ! is_array( $error_data ) || 401 !== (int) ( $error_data['status'] ?? 0 ) ) {
	throw new RuntimeException( 'Large JSON POST transport probe did not reach the expected WordPress route.' );
}

fwrite( STDOUT, "FrontendPathResolverTransportProbe passed.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test probe writing to STDOUT.
