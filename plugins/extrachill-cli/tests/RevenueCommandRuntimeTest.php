<?php
/**
 * Exercises revenue argument validation through a real WP-CLI dispatcher.
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
	throw new RuntimeException( 'RevenueCommand runtime tests require an installed WordPress path.' );
}

$bootstrap = __DIR__ . '/RevenueCommandRuntimeBootstrap.php';
$commands  = array(
	'fetch --start=2026-05-01 --end=2026-05-31 --period=2026-05 --periods=\'[{"period":"2026-05","start_date":"2026-05-01","end_date":"2026-05-31"}]\' --site-id=42 --hostname=extrachill.com --mode=additive --snapshot=test --dry-run',
	'pages --period=2026-05 --period-start=2026-05-01 --period-end=2026-05-31 --batch=test --blog-id=1 --hostname=extrachill.com --cohort=resolved --min-views=1000 --sort-by=revenue --order=desc --limit=25 --format=json',
	'rollup --group-by=both --period=2026-05 --period-start=2026-05-01 --period-end=2026-05-31 --batch=test --hostname=extrachill.com --limit=100 --format=json',
	'arc --include-alltime --format=json',
	'diagnose --period=2026-05 --period-start=2026-05-01 --period-end=2026-05-31 --batch=test --blog-id=1 --hostname=extrachill.com --format=json',
);

foreach ( $commands as $command ) {
	$runtime_command = sprintf(
		'wp --path=%s --allow-root --skip-plugins --require=%s extrachill analytics revenue %s 2>&1',
		escapeshellarg( $wp_path ),
		escapeshellarg( $bootstrap ),
		$command
	);
	exec( $runtime_command, $output, $exit_code );
	$output = implode( "\n", $output );

	if ( false !== strpos( $output, 'Parameter errors:' ) || false !== strpos( $output, 'unknown --' ) ) {
		throw new RuntimeException( sprintf( 'WP-CLI rejected documented revenue options for "%s": %s', $command, $output ) );
	}
	if ( false === strpos( $output, 'ability not found' ) ) {
		throw new RuntimeException( sprintf( 'Revenue command "%s" did not reach its Ability boundary: %s', $command, $output ) );
	}
	if ( 0 === $exit_code ) {
		throw new RuntimeException( sprintf( 'Revenue command "%s" unexpectedly succeeded without its Ability.', $command ) );
	}
}

fwrite( STDOUT, "RevenueCommand runtime tests passed.\n" );
