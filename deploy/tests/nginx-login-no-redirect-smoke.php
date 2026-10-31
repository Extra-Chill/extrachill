<?php
/**
 * Regression guard for #346: nginx must never hard-redirect between /login
 * and /login/. Subsites canonicalize /login/ to /login in WordPress, so an
 * nginx `/login` -> `/login/` redirect loops forever and the wp_login rate
 * limit turns the loop into 429s. Both forms must be rate-limited and passed
 * to WordPress.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

$root     = dirname( __DIR__, 2 );
$configs  = array(
	'deploy/nginx/sites-enabled/extrachill',
	'docs/nginx/server-snippet.conf',
);
$failures = array();

function nlr_assert( bool $ok, string $label ): void {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

function nlr_location_body( string $conf, string $path ): ?string {
	$pattern = '/location\s*=\s*' . preg_quote( $path, '/' ) . '\s*\{([^}]*)\}/';
	return preg_match( $pattern, $conf, $m ) ? $m[1] : null;
}

foreach ( $configs as $relative ) {
	$conf = (string) file_get_contents( $root . '/' . $relative );
	nlr_assert( '' !== $conf, "{$relative} is readable" );

	foreach ( array( '/login', '/login/' ) as $path ) {
		$body = nlr_location_body( $conf, $path );
		nlr_assert( null !== $body, "{$relative}: location = {$path} exists" );
		if ( null === $body ) {
			continue;
		}
		nlr_assert( 1 !== preg_match( '/\breturn\s+30[0-9]\b/', $body ), "{$relative}: location = {$path} does not redirect" );
		nlr_assert( 1 !== preg_match( '/\brewrite\b[^;]*\b(redirect|permanent)\b/', $body ), "{$relative}: location = {$path} does not rewrite-redirect" );
		nlr_assert( str_contains( $body, 'limit_req zone=wp_login' ), "{$relative}: location = {$path} is rate-limited by wp_login" );
		nlr_assert( str_contains( $body, '/index.php?$args' ), "{$relative}: location = {$path} hands off to WordPress" );
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, count( $failures ) . " failure(s).\n" );
	exit( 1 );
}

echo "All nginx login redirect checks passed.\n";
