<?php
/**
 * Apple Sign-In network config helpers (Extra-Chill/extrachill#431).
 *
 * Run: php tests/apple-oauth-config-smoke.php
 *
 * @package ExtraChillNetwork
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ec_test_site_options'] = array();

function get_site_option( $key, $default = false ) {
	return $GLOBALS['ec_test_site_options'][ $key ] ?? $default;
}

require_once dirname( __DIR__ ) . '/inc/core/oauth-helpers.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
};
$set    = static function ( array $options ) {
	$GLOBALS['ec_test_site_options'] = $options;
};

// Nothing set: no audiences, nothing configured.
$assert( array() === ec_get_apple_audiences(), 'No Apple options must yield no audiences.' );
$assert( ! ec_is_apple_oauth_configured(), 'Apple must be off with no audience.' );
$assert( ! ec_is_apple_server_credentials_configured(), 'Server credentials must be off with nothing set.' );

// Bundle ID alone enables native sign-in, not server credentials.
$set( array( 'extrachill_apple_bundle_id' => '  com.extrachill.app ' ) );
$assert( array( 'com.extrachill.app' ) === ec_get_apple_audiences(), 'The bundle ID must be a trimmed audience.' );
$assert( ec_is_apple_oauth_configured(), 'A bundle ID alone must enable Apple sign-in.' );
$assert( ! ec_is_apple_server_credentials_configured(), 'A bundle ID alone must not count as server credentials.' );

// Services ID alone is also an accepted audience.
$set( array( 'extrachill_apple_client_id' => 'com.extrachill.auth' ) );
$assert( array( 'com.extrachill.auth' ) === ec_get_apple_audiences(), 'The Services ID must be an audience.' );
$assert( ec_is_apple_oauth_configured(), 'A Services ID alone must enable Apple sign-in.' );

// Both, de-duplicated, bundle ID first; whitespace-only values ignored.
$set(
	array(
		'extrachill_apple_bundle_id' => 'com.extrachill.app',
		'extrachill_apple_client_id' => 'com.extrachill.app',
	)
);
$assert( array( 'com.extrachill.app' ) === ec_get_apple_audiences(), 'Duplicate audiences must collapse.' );
$set(
	array(
		'extrachill_apple_bundle_id' => '   ',
		'extrachill_apple_client_id' => 'com.extrachill.auth',
	)
);
$assert( array( 'com.extrachill.auth' ) === ec_get_apple_audiences(), 'Whitespace-only audiences must be dropped.' );
$set(
	array(
		'extrachill_apple_bundle_id' => 'com.extrachill.app',
		'extrachill_apple_client_id' => 'com.extrachill.auth',
	)
);
$assert( array( 'com.extrachill.app', 'com.extrachill.auth' ) === ec_get_apple_audiences(), 'Both audiences must be accepted.' );

// Server credentials keep the four-field requirement.
$full = array(
	'extrachill_apple_client_id'   => 'com.extrachill.auth',
	'extrachill_apple_team_id'     => 'TEAMID1234',
	'extrachill_apple_key_id'      => 'KEYID12345',
	'extrachill_apple_private_key' => '-----BEGIN PRIVATE KEY-----',
);
$set( $full );
$assert( ec_is_apple_server_credentials_configured(), 'All four server credentials must count as configured.' );
foreach ( array_keys( $full ) as $missing ) {
	$partial = $full;
	unset( $partial[ $missing ] );
	$set( $partial );
	$assert( ! ec_is_apple_server_credentials_configured(), "Server credentials must require {$missing}." );
}

echo "Apple OAuth config smoke passed.\n";
