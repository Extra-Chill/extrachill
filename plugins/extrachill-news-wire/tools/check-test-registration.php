<?php
/**
 * Ensure every standalone smoke is registered in Homeboy and Composer.
 *
 * Run: php tools/check-test-registration.php
 *
 * @package ExtraChillNewsWire
 */

$root     = dirname( __DIR__ );
$manifest = json_decode( file_get_contents( $root . '/homeboy-test-manifest.json' ), true );
$composer = json_decode( file_get_contents( $root . '/composer.json' ), true );
$files    = glob( $root . '/tests/smoke-*.php' );
$errors   = array();

if ( 'homeboy/test-manifest/v1' !== ( $manifest['schema'] ?? null ) || ! is_array( $manifest['tests'] ?? null ) ) {
	fwrite( STDERR, "Invalid Homeboy test manifest.\n" );
	exit( 1 );
}

if ( empty( $files ) ) {
	fwrite( STDERR, "No smoke tests found.\n" );
	exit( 1 );
}

$script   = $composer['scripts']['test'] ?? '';
$commands = is_string( $script ) ? array_map( 'trim', explode( '&&', $script ) ) : array();
foreach ( $files as $file ) {
	$path = 'tests/' . basename( $file );
	if ( 'standalone-php' !== ( $manifest['tests'][ $path ]['environment'] ?? null ) ) {
		$errors[] = "{$path} must be declared as standalone-php in homeboy-test-manifest.json.";
	}
	if ( ! in_array( 'php ' . $path, $commands, true ) ) {
		$errors[] = "{$path} must be executed by composer test.";
	}
}

foreach ( $manifest['tests'] as $path => $config ) {
	if ( ! in_array( $root . '/' . $path, $files, true ) ) {
		$errors[] = "Stale or unexpected manifest entry: {$path}.";
	}
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

printf( "%d smoke tests registered in Homeboy and Composer.\n", count( $files ) );
