<?php
/**
 * Test-environment declaration guard.
 *
 * The Homeboy WordPress extension runs every tests/**\/*-smoke.php on bare host
 * PHP unless the component declares it in `wordpress_test_paths`. A smoke that
 * bootstraps WordPress through wp-load.php cannot run that way: it prints its
 * usage line and exits 1, turning the full suite red on clean main. This guard
 * fails when such a smoke is not declared, or when a declaration points at a
 * file that no longer exists.
 */

$component_root = dirname( __DIR__ );

function wordpress_test_paths_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

$config = json_decode( (string) file_get_contents( $component_root . '/homeboy.json' ), true );
if ( ! is_array( $config ) ) {
	wordpress_test_paths_fail( 'homeboy.json is not valid JSON.' );
}

$declared = $config['extensions']['wordpress']['settings']['wordpress_test_paths'] ?? array();
if ( ! is_array( $declared ) ) {
	wordpress_test_paths_fail( 'wordpress_test_paths must be an array.' );
}

foreach ( $declared as $path ) {
	if ( ! is_string( $path ) || ! is_file( $component_root . '/' . $path ) ) {
		wordpress_test_paths_fail( 'wordpress_test_paths declares a missing file: ' . var_export( $path, true ) );
	}
}

$needs_wordpress = array();
$smokes          = glob( __DIR__ . '/{,*/,*/*/}*-smoke.php', GLOB_BRACE );
foreach ( $smokes ? $smokes : array() as $smoke ) {
	if ( __FILE__ === $smoke ) {
		continue;
	}
	if ( false !== strpos( (string) file_get_contents( $smoke ), 'wp-load.php' ) ) {
		$needs_wordpress[] = substr( $smoke, strlen( $component_root ) + 1 );
	}
}

if ( empty( $needs_wordpress ) ) {
	wordpress_test_paths_fail( 'expected at least one booted-WordPress smoke; the scan found none.' );
}

$undeclared = array_diff( $needs_wordpress, $declared );
if ( ! empty( $undeclared ) ) {
	wordpress_test_paths_fail(
		"smokes that bootstrap wp-load.php must be listed in homeboy.json wordpress_test_paths:\n  " . implode( "\n  ", $undeclared )
	);
}

echo 'PASS: ' . count( $needs_wordpress ) . " booted-WordPress smokes are declared in wordpress_test_paths\n";
