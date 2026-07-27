<?php
/**
 * Focused contracts for concise Extra Chill CLI agent guidance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/inc/AgentsMdSection.php';

use ExtraChill\CLI\AgentsMdSection;

$prefix   = 'host wp --url=https://community.example.test --path=/srv/wordpress';
$output   = AgentsMdSection::render( $prefix );
$failures = array();

$check = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$routes = array(
	'extrachill platform health',
	'extrachill analytics summary',
	'extrachill events --help',
	'extrachill venues --help',
	'extrachill artists --help',
	'extrachill users --help',
	'extrachill community --help',
	'extrachill content --help',
	'extrachill newsletter --help',
	'extrachill analytics errors',
	'extrachill analytics 404 summary',
	'extrachill cache status',
);

foreach ( $routes as $route ) {
	$check( false !== strpos( $output, "`{$prefix} {$route}`" ), "Missing default route: {$route}" );
}

$check( false !== strpos( $output, "`{$prefix} extrachill --help`" ), 'Top-level live discovery command must be present.' );
$check( false !== strpos( $output, "`{$prefix} extrachill <namespace> --help`" ), 'Nested live discovery command must be present.' );
$check( false !== strpos( $output, 'Live `--help` is authoritative' ), 'Guidance must make live help authoritative.' );
$check( false !== strpos( $output, 'Use `--url=<site-url>`' ), 'Guidance must explain multisite targeting.' );
$check( false === strpos( $output, 'publish-campaign' ), 'Guidance must not exhaustively enumerate command actions.' );
$check( 30 >= substr_count( $output, "\n" ) + 1, 'Guidance must remain bounded to 30 lines.' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "AgentsMdSection tests passed.\n" );
