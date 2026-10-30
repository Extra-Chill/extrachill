<?php
/**
 * Runs the worktree resolver against installed main, Events, and Wire boots.
 *
 * @package ExtraChillNetwork
 */

$wp_path    = getenv( 'WP_CLI_RUNTIME_PATH' );
$candidates = array_filter( array( $wp_path, '/var/www/extrachill.com', '/var/www/html', '/wordpress' ) );
$wp_path    = '';
foreach ( $candidates as $candidate ) {
	$check = sprintf( 'wp --path=%s --allow-root --skip-plugins --skip-themes core is-installed 2>/dev/null', escapeshellarg( $candidate ) );
	exec( $check, $ignored, $status );
	if ( 0 === $status ) {
		$wp_path = $candidate;
		break;
	}
}
if ( '' === $wp_path ) {
	throw new RuntimeException( 'Frontend path runtime tests require an installed WordPress path.' );
}

$probe = __DIR__ . '/FrontendPathResolverRuntimeProbe.php';
$sites = array(
	'extrachill.com'        => 'post',
	'events.extrachill.com' => 'data_machine_events',
	'wire.extrachill.com'   => 'festival_wire',
);
foreach ( $sites as $site => $post_type ) {
	$output  = array();
	$status  = 0;
	$command = sprintf(
		'EC_FRONTEND_PATH_RESOLVER_EXPECTED_TYPE=%s wp --path=%s --url=%s --allow-root --skip-plugins=extrachill-network eval-file %s 2>&1',
		escapeshellarg( $post_type ),
		escapeshellarg( $wp_path ),
		escapeshellarg( $site ),
		escapeshellarg( $probe )
	);
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		throw new RuntimeException( sprintf( 'Runtime resolver probe failed for %s: %s', $site, implode( "\n", $output ) ) );
	}
}

$transport_probe = __DIR__ . '/FrontendPathResolverTransportProbe.php';
$transport_command = sprintf(
	'wp --path=%s --url=%s --allow-root --skip-plugins=extrachill-network eval-file %s 2>&1',
	escapeshellarg( $wp_path ),
	escapeshellarg( 'extrachill.com' ),
	escapeshellarg( $transport_probe )
);
$output = array();
exec( $transport_command, $output, $status );
if ( 0 !== $status ) {
	throw new RuntimeException( sprintf( 'Large JSON POST transport probe failed: %s', implode( "\n", $output ) ) );
}

fwrite( STDOUT, "FrontendPathResolverRuntimeTest passed.\n" );
