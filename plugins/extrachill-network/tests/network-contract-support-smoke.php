<?php
/**
 * Contract smoke for the shared network test support (#446).
 *
 * Proves two things:
 *   1. tests/support/network-contract.php loads the real blog-ids.php on bare
 *      host PHP and exposes the production network contract.
 *   2. tests/support/phpstan-network-contract.stub declares exactly the
 *      functions (with matching parameter names) and constant values that
 *      blog-ids.php defines, so the static stub cannot drift silently.
 *
 * Run: php tests/network-contract-support-smoke.php
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

require_once __DIR__ . '/support/network-contract.php';

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	++$failures;
	fwrite( STDERR, "FAIL: {$message}\n" );
};

// 1. Runtime contract comes from the real source.
$assert( function_exists( 'ec_get_blog_id' ), 'ec_get_blog_id() is defined' );
$assert( 1 === ec_get_blog_id( 'main' ), 'main resolves to blog 1' );
$assert( 7 === ec_get_blog_id( 'events' ), 'events resolves to blog 7' );
$assert( 13 === ec_get_blog_id( 'link_pages' ), 'link_pages resolves to blog 13' );
$assert( null === ec_get_blog_id( 'stream' ), 'decommissioned/unknown keys resolve to null' );
$assert( 'https://extrachill.com' === ec_get_site_url( 'main' ), 'main site URL is the production domain' );
$assert( 'https://community.extrachill.com' === ec_get_site_url( 'community' ), 'community site URL is the production domain' );
$assert( 'artist' === ec_get_blog_slug_by_id( 4 ), 'reverse lookup maps blog 4 to artist' );
$assert( 12114 === EC_PLATFORM_ARTIST_ID, 'platform artist ID falls back to production value' );
$assert( ! ec_link_pages_site_cutover_enabled(), 'link pages cutover defaults to off' );
$GLOBALS['ec_test_network_site_options'][ EC_LINK_PAGES_SITE_CUTOVER_OPTION ] = '1';
$assert( ec_link_pages_site_cutover_enabled(), 'cutover reads the fixture network option' );
$assert( 13 === ec_filter_link_page_storage_blog_id( 0 ), 'cutover on stores link pages on blog 13' );
unset( $GLOBALS['ec_test_network_site_options'][ EC_LINK_PAGES_SITE_CUTOVER_OPTION ] );

$source_file = dirname( __DIR__ ) . '/inc/core/blog-ids.php';
$included    = array_map( 'realpath', get_included_files() );
$assert( in_array( realpath( $source_file ), $included, true ), 'support loads inc/core/blog-ids.php itself (no copy)' );

// 2. Static stub parity with the source, compared by tokens.
/**
 * @return array{functions: array<string, string[]>, constants: array<string, string>}
 */
function ecn_contract_shape( string $file ): array {
	$tokens    = token_get_all( (string) file_get_contents( $file ) );
	$functions = array();
	$constants = array();
	$count     = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( T_FUNCTION === $token[0] ) {
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}
			if ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) {
				continue;
			}
			$name   = $tokens[ $j ][1];
			$params = array();
			$depth  = 0;
			for ( $k = $j + 1; $k < $count; $k++ ) {
				if ( '(' === $tokens[ $k ] ) {
					++$depth;
				} elseif ( ')' === $tokens[ $k ] ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( is_array( $tokens[ $k ] ) && T_VARIABLE === $tokens[ $k ][0] && 1 === $depth ) {
					$params[] = $tokens[ $k ][1];
				}
			}
			$functions[ $name ] = $params;
			continue;
		}

		if ( T_STRING === $token[0] && 'define' === strtolower( $token[1] ) ) {
			$args = array();
			for ( $k = $i + 1; $k < $count && ';' !== $tokens[ $k ] && count( $args ) < 2; $k++ ) {
				if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_CONSTANT_ENCAPSED_STRING, T_LNUMBER ), true ) ) {
					$args[] = trim( $tokens[ $k ][1], "'\"" );
				} elseif ( is_array( $tokens[ $k ] ) && T_VARIABLE === $tokens[ $k ][0] ) {
					$args[] = '$dynamic';
				}
			}
			if ( 2 === count( $args ) ) {
				$constants[ $args[0] ] = $args[1];
			}
		}
	}

	ksort( $functions );
	ksort( $constants );

	return array(
		'functions' => $functions,
		'constants' => $constants,
	);
}

$source = ecn_contract_shape( $source_file );
$stub   = ecn_contract_shape( __DIR__ . '/support/phpstan-network-contract.stub' );

$assert( $source['functions'] === $stub['functions'], 'PHPStan stub declares the same functions and parameters as blog-ids.php' );

// EC_PLATFORM_ARTIST_ID is dynamic in the source (network option with a
// production fallback); compare the fallback literal instead.
$source_constants = $source['constants'];
if ( isset( $source_constants['EC_PLATFORM_ARTIST_ID'] ) && '$dynamic' === $source_constants['EC_PLATFORM_ARTIST_ID'] ) {
	$source_constants['EC_PLATFORM_ARTIST_ID'] = (string) EC_PLATFORM_ARTIST_ID;
}
$assert( $source_constants === $stub['constants'], 'PHPStan stub declares the same constants and values as blog-ids.php' );

if ( $source['functions'] !== $stub['functions'] ) {
	fwrite( STDERR, 'source functions: ' . json_encode( $source['functions'] ) . "\nstub functions:   " . json_encode( $stub['functions'] ) . "\n" );
}
if ( $source_constants !== $stub['constants'] ) {
	fwrite( STDERR, 'source constants: ' . json_encode( $source_constants ) . "\nstub constants:   " . json_encode( $stub['constants'] ) . "\n" );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} network contract support check(s) failed.\n" );
	exit( 1 );
}

echo "Network contract support checks passed.\n";
