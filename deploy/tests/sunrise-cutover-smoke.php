<?php
/**
 * Behavioral coverage for the sunrise drop-in (deploy/sunrise.php).
 *
 * The drop-in calls exit() on the www canonicalization path, so every scenario
 * runs in a fresh child PHP process. The child prints "SCENARIO_OK <name>" as
 * its final line; the parent asserts the exit code and that marker. CLI SAPI
 * cannot record header() calls, so the parent pins the exact 301 construction
 * in the artifact source instead of introspecting the response.
 */

$scenario = $argv[1] ?? null;

if ( null === $scenario ) {
	$scenarios = array(
		'apex',
		'www',
		'apex_missing_site',
		'other_host',
	);
	$failures  = 0;
	foreach ( $scenarios as $name ) {
		$output = array();
		$code   = 0;
		exec(
			escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $name ) . ' 2>&1',
			$output,
			$code
		);
		if ( 0 !== $code || ! in_array( 'SCENARIO_OK ' . $name, $output, true ) ) {
			++$failures;
			fwrite( STDERR, "FAIL: scenario {$name} (exit {$code})\n" . implode( "\n", $output ) . "\n" );
		}
	}
	if ( $failures ) {
		fwrite( STDERR, "{$failures} sunrise scenario(s) failed.\n" );
		exit( 1 );
	}

	// CLI cannot observe header(), so pin the exact apex-301 construction.
	$artifact   = file_get_contents( dirname( __DIR__, 2 ) . '/deploy/sunrise.php' );
	$redirect   = "header( 'Location: https://extrachill.link' . \$ec_request_uri, true, 301 );";
	$assert_pin = static function ( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, $message . PHP_EOL );
			exit( 1 );
		}
	};
	$assert_pin( false !== strpos( (string) $artifact, $redirect ), 'The www canonicalization must emit a 301 redirect to the apex.' );

	fwrite( STDOUT, "Sunrise scenarios passed.\n" );
	exit( 0 );
}

// ---------------------------------------------------------------------------
// Child scenario runner.
// ---------------------------------------------------------------------------

define( 'MULTISITE', true );

$GLOBALS['ec_test_filters'] = array();
$GLOBALS['ec_test_sites']   = array(
	13 => (object) array(
		'blog_id'  => 13,
		'deleted'  => 0,
		'archived' => 0,
		'spam'     => 0,
	),
);

function get_network( $id ) {
	return (object) array( 'id' => $id );
}

function get_site( $id ) {
	return $GLOBALS['ec_test_sites'][ $id ] ?? null;
}

function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['ec_test_filters'][] = array( $hook, $callback, $priority );
}

function ec_sunrise_child_fail( $message ) {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

if ( 'apex_missing_site' === $scenario ) {
	unset( $GLOBALS['ec_test_sites'][13] );
}

$hosts                  = array(
	'www'        => 'www.extrachill.link',
	'other_host' => 'artist.extrachill.com',
);
$_SERVER['HTTP_HOST']   = $hosts[ $scenario ] ?? 'extrachill.link';
$_SERVER['REQUEST_URI'] = '/some-slug/?x=1';

if ( 'www' === $scenario ) {
	// sunrise exits mid-file on this path; validate from the shutdown handler.
	register_shutdown_function(
		static function () {
			$blog_id = $GLOBALS['blog_id'] ?? null;
			if ( ! empty( $blog_id ) || ! empty( $GLOBALS['ec_test_filters'] ) ) {
				fwrite( STDERR, "FAIL: the www redirect must not map a blog or register rewrites.\n" );
				return;
			}
			fwrite( STDOUT, 'SCENARIO_OK www' . PHP_EOL );
		}
	);
}

require dirname( __DIR__, 2 ) . '/deploy/sunrise.php';

// Reached only when sunrise did not exit: assert mapping state.
foreach ( headers_list() as $header ) {
	if ( 0 === stripos( $header, 'Location:' ) ) {
		ec_sunrise_child_fail( "FAIL: unexpected redirect header in {$scenario}: {$header}" );
	}
}

if ( ! empty( $GLOBALS['ec_test_filters'] ) ) {
	ec_sunrise_child_fail( "FAIL: {$scenario} must not register any rewrite filter." );
}

switch ( $scenario ) {
	case 'apex':
		if ( 13 !== $blog_id ) {
			ec_sunrise_child_fail( "FAIL: apex must map to the Link Pages blog 13, got {$blog_id}." );
		}
		if ( 13 !== $current_blog->blog_id ) {
			ec_sunrise_child_fail( 'FAIL: apex must set current_blog to blog 13.' );
		}
		break;
	case 'apex_missing_site':
	case 'other_host':
		if ( isset( $blog_id ) || isset( $current_blog ) ) {
			ec_sunrise_child_fail( "FAIL: {$scenario} must leave blog resolution to WordPress core." );
		}
		break;
	default:
		ec_sunrise_child_fail( "FAIL: unknown scenario {$scenario} reached the post-include assertions." );
}

fwrite( STDOUT, 'SCENARIO_OK ' . $scenario . PHP_EOL );
