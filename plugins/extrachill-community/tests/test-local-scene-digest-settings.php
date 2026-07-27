<?php
/** Standalone contract test for the Community digest entity mapping. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['_test_filters'] = array();

function add_filter( $hook, $callback ) {
	$GLOBALS['_test_filters'][ $hook ][] = $callback;
}

require __DIR__ . '/../inc/core/local-scene-digest-settings.php';

$callback = $GLOBALS['_test_filters']['extrachill_users_entity_subscription_entities'][0] ?? null;
$result   = is_callable( $callback ) ? $callback( array( 'artist' => 'artist' ) ) : array();
$passed   = isset( $result['local_scene_digest'] )
	&& 'location' === $result['local_scene_digest']
	&& 'artist' === $result['artist'];

if ( ! $passed ) {
	fwrite( STDERR, "FAIL: Community did not register the Local Scene digest entity mapping.\n" );
	exit( 1 );
}

echo "PASS: Community registers the shared Local Scene digest entity mapping.\n";
