<?php
/**
 * Guard: standalone smokes that need WordPress core boot from the runner's
 * core tree, never from an installed site.
 *
 * The Homeboy WordPress extension runs tests/**\/*-smoke.php on host PHP and
 * hands them a pristine core source tree in HOMEBOY_WORDPRESS_CORE_ROOT. That
 * tree has no wp-config.php or database, so a smoke that requires wp-load.php
 * (or only reads a WordPress root from argv / WP_ROOT) cannot run there. It
 * printed a usage line and exited 1, which turned the full suite red on clean
 * main. Core-dependent smokes go through tests/support/wordpress-core-bootstrap.php.
 */

$tests_dir = __DIR__;
$bootstrap = $tests_dir . '/support/wordpress-core-bootstrap.php';

function standalone_core_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

// 1. No standalone smoke boots an installed site or bypasses the shared bootstrap.
$offenders  = array();
$core_users = 0;
$smokes     = glob( $tests_dir . '/{,*/,*/*/}*-smoke.php', GLOB_BRACE );
foreach ( $smokes ? $smokes : array() as $smoke ) {
	if ( __FILE__ === $smoke ) {
		continue;
	}
	$source = (string) file_get_contents( $smoke );
	$rel    = substr( $smoke, strlen( dirname( $tests_dir ) ) + 1 );
	if ( false !== strpos( $source, 'wp-load.php' ) ) {
		$offenders[] = "{$rel} requires wp-load.php";
	}
	if ( false !== strpos( $source, 'support/wordpress-core-bootstrap.php' ) ) {
		++$core_users;
	}
}
if ( $offenders ) {
	standalone_core_fail( "standalone smokes must load core through tests/support/wordpress-core-bootstrap.php:\n  " . implode( "\n  ", $offenders ) );
}
if ( 0 === $core_users ) {
	standalone_core_fail( 'expected at least one smoke to use the shared core bootstrap.' );
}

// 2. Without a core root the bootstrap fails loudly and names the variable.
$php     = escapeshellarg( PHP_BINARY );
$command = 'env -u HOMEBOY_WORDPRESS_CORE_ROOT -u WP_ROOT ' . $php . ' ' . escapeshellarg( $bootstrap ) . ' 2>&1';
exec( $command, $output, $status );
if ( 1 !== $status || false === strpos( implode( "\n", $output ), 'HOMEBOY_WORDPRESS_CORE_ROOT' ) ) {
	standalone_core_fail( 'bootstrap without a core root must exit 1 naming HOMEBOY_WORDPRESS_CORE_ROOT; got exit ' . $status . ': ' . implode( ' ', $output ) );
}

// 3. With the runner's core tree it loads core without a database.
$core_root = (string) getenv( 'HOMEBOY_WORDPRESS_CORE_ROOT' );
if ( '' === $core_root ) {
	echo "PASS: core-dependent smokes use the shared bootstrap (core load check needs HOMEBOY_WORDPRESS_CORE_ROOT; not set)\n";
	exit( 0 );
}

$probe = tempnam( sys_get_temp_dir(), 'ec-core-bootstrap-' );
file_put_contents(
	$probe,
	'<?php require ' . var_export( $bootstrap, true ) . ';' .
	' require_once ABSPATH . WPINC . "/rest-api.php";' .
	' echo ( ! isset( $GLOBALS["wpdb"] ) && "UTF-8" === get_option( "blog_charset" ) && "2026-01-02" === mysql2date( "Y-m-d", "2026-01-02 03:04:05" ) && function_exists( "rest_validate_value_from_schema" ) ) ? "CORE_OK" : "CORE_BAD";'
);
$output = array();
exec( $php . ' ' . escapeshellarg( $probe ) . ' 2>&1', $output, $status );
unlink( $probe );
if ( 0 !== $status || 'CORE_OK' !== trim( implode( "\n", $output ) ) ) {
	standalone_core_fail( 'bootstrap must load core from HOMEBOY_WORDPRESS_CORE_ROOT without a database; got exit ' . $status . ': ' . implode( ' ', $output ) );
}

echo "PASS: core-dependent smokes boot WordPress core from HOMEBOY_WORDPRESS_CORE_ROOT without a database\n";
