<?php
/**
 * Regression guard for #1705: extrachill.link is a native multisite domain
 * (blog 13) with no sunrise.php drop-in, so nginx owns the
 * www.extrachill.link -> extrachill.link canonical redirect.
 *
 * - A dedicated server block for www.extrachill.link 301s to the apex on
 *   both HTTP and HTTPS.
 * - The main WordPress server blocks no longer list www.extrachill.link, so
 *   www can never be served by PHP directly.
 * - extrachill.link itself still reaches WordPress.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

$root     = dirname( __DIR__, 2 );
$relative = 'deploy/nginx/sites-enabled/extrachill';
$conf     = (string) file_get_contents( $root . '/' . $relative );
$failures = array();

function nlw_assert( bool $ok, string $label ): void {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

/**
 * Split the config into top-level server blocks (brace-balanced).
 *
 * @return string[]
 */
function nlw_server_blocks( string $conf ): array {
	$blocks = array();
	$offset = 0;
	while ( preg_match( '/^server\s*\{/m', $conf, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$start = $m[0][1];
		$depth = 0;
		$len   = strlen( $conf );
		for ( $i = $start; $i < $len; $i++ ) {
			if ( '{' === $conf[ $i ] ) {
				++$depth;
			} elseif ( '}' === $conf[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
			}
		}
		$blocks[] = substr( $conf, $start, $i - $start + 1 );
		$offset   = $i + 1;
	}
	return $blocks;
}

function nlw_server_names( string $block ): array {
	return preg_match( '/^\s*server_name\s+([^;]+);/m', $block, $m )
		? preg_split( '/\s+/', trim( $m[1] ) )
		: array();
}

nlw_assert( '' !== $conf, "{$relative} is readable" );

$www_blocks  = array();
$apex_blocks = array();
foreach ( nlw_server_blocks( $conf ) as $block ) {
	$names = nlw_server_names( $block );
	if ( in_array( 'www.extrachill.link', $names, true ) ) {
		$www_blocks[] = $block;
	}
	if ( in_array( 'extrachill.link', $names, true ) ) {
		$apex_blocks[] = $block;
	}
}

nlw_assert( 1 === count( $www_blocks ), 'exactly one server block names www.extrachill.link' );
$www = $www_blocks[0] ?? '';
nlw_assert( array( 'www.extrachill.link' ) === nlw_server_names( $www ), 'the www block names only www.extrachill.link' );
nlw_assert( (bool) preg_match( '/listen\s+80;/', $www ) && (bool) preg_match( '/listen\s+443\s+ssl/', $www ), 'the www block listens on 80 and 443' );
nlw_assert( (bool) preg_match( '/return\s+301\s+https:\/\/extrachill\.link\$request_uri;/', $www ), 'the www block 301s to https://extrachill.link$request_uri' );
nlw_assert( false === strpos( $www, 'fastcgi_pass' ), 'the www block never reaches PHP' );

nlw_assert( count( $apex_blocks ) >= 2, 'extrachill.link is still served by the main HTTP and HTTPS blocks' );
nlw_assert( array() !== array_filter( $apex_blocks, static fn( $b ) => false !== strpos( $b, 'fastcgi_pass' ) ), 'extrachill.link reaches WordPress' );

if ( $failures ) {
	fwrite( STDERR, count( $failures ) . " assertion(s) failed.\n" );
	exit( 1 );
}
echo "nginx www.extrachill.link redirect contract holds.\n";
