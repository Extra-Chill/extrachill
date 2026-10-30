<?php
/**
 * Registration-level regression coverage for optional object input (issue #70).
 *
 * Core normalizes null input to the schema default before validation. Inspect
 * the real registration definitions without requiring WordPress or Sendy.
 *
 * Run with: php tests/optional-ability-input-smoke.php
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

$registration_callbacks = array();
$abilities              = array();

function add_action( $hook, $callback ) {
	if ( 'wp_abilities_api_init' === $hook ) {
		$GLOBALS['registration_callbacks'][] = $callback;
	}
}

function __( $text, $domain = '' ) {
	return $text;
}

function wp_register_ability( $name, $definition ) {
	$GLOBALS['abilities'][ $name ] = $definition;
}

// Discover all ability modules, including any added in the future. The loader
// itself also loads unrelated campaign runtime code, so leave it out here.
foreach ( glob( dirname( __DIR__ ) . '/inc/core/abilities/*.php' ) as $file ) {
	if ( 'register.php' !== basename( $file ) ) {
		require_once $file;
	}
}
foreach ( $registration_callbacks as $callback ) {
	$callback();
}

$checked = array();
foreach ( $abilities as $name => $definition ) {
	$schema = $definition['input_schema'] ?? array();
	if ( 'object' !== ( $schema['type'] ?? null ) || ! empty( $schema['required'] ) ) {
		continue;
	}
	if ( ! array_key_exists( 'default', $schema ) || array() !== $schema['default'] ) {
		fwrite( STDERR, "FAIL: {$name} needs an empty-array input_schema default for null input.\n" );
		exit( 1 );
	}
	$checked[] = $name;
}

// Prevent discovery failures from silently turning this check into a no-op.
foreach ( array(
	'extrachill/newsletter-funnel-metrics',
	'extrachill/newsletter-subscriber-stats',
	'extrachill/get-newsletter-settings',
	'extrachill/update-newsletter-settings',
	'extrachill/list-campaigns',
) as $name ) {
	if ( ! in_array( $name, $checked, true ) ) {
		fwrite( STDERR, "FAIL: optional-input ability {$name} was not checked.\n" );
		exit( 1 );
	}
}

printf( "PASS: %d optional-object ability schemas declare empty-array defaults.\n", count( $checked ) );
