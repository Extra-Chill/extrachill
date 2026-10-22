<?php
/**
 * Regression: optional object inputs need a schema-level default so Core can
 * normalize omitted/null input before validation (#360).
 *
 * Capture real registrations without executing callbacks or requiring WordPress.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$abilities = array();

function __( $text, $domain = '' ) {
	return $text;
}

function add_action( ...$args ) {}
function add_filter( ...$args ) {}

function wp_has_ability( $name ) {
	return isset( $GLOBALS['abilities'][ $name ] );
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['abilities'][ $name ] = $args;
}

require_once __DIR__ . '/../inc/core/experiments.php';

foreach ( glob( __DIR__ . '/../inc/Abilities/*.php' ) as $file ) {
	require_once $file;
}

foreach ( get_declared_classes() as $class ) {
	if ( str_starts_with( $class, 'ExtraChillNetwork\\Abilities\\' ) && method_exists( $class, 'register' ) ) {
		$reflection = new ReflectionClass( $class );
		$reflection->newInstanceWithoutConstructor()->register();
	}
}

require_once __DIR__ . '/../inc/NetworkStats/bootstrap.php';
\ExtraChillNetwork\NetworkStats\register_network_stats_ability();

$checked = array();
foreach ( $abilities as $name => $args ) {
	$schema = $args['input_schema'] ?? array();
	if ( ( $schema['type'] ?? null ) !== 'object' || ! empty( $schema['required'] ) ) {
		continue;
	}

	if ( ! array_key_exists( 'default', $schema ) || array() !== $schema['default'] ) {
		fwrite( STDERR, "FAIL: {$name} must default optional object input to array() for null input normalization.\n" );
		exit( 1 );
	}
	$checked[] = $name;
}

// Guard against accidentally dropping coverage of the originally affected abilities.
foreach ( array( 'get-ad-policy', 'get-network-stats', 'mail-site-id', 'network-media-list', 'regenerate-og-card' ) as $slug ) {
	if ( ! in_array( 'extrachill/' . $slug, $checked, true ) ) {
		fwrite( STDERR, "FAIL: missing optional input coverage for {$slug}.\n" );
		exit( 1 );
	}
}

echo 'PASS: optional object input defaults (' . count( $checked ) . " abilities).\n";
