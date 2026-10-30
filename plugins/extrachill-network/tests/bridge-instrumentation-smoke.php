<?php
/**
 * Standalone tests for bridge instrumentation render contexts.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_NETWORK_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_NETWORK_PLUGIN_URL', 'https://example.com/wp-content/plugins/extrachill-network/' );

$GLOBALS['bridge_context'] = array(
	'admin'      => false,
	'singular'   => false,
	'front_page' => false,
	'home'       => false,
	'preview'    => false,
);
$GLOBALS['bridge_enqueued'] = array();

function is_admin() {
	return $GLOBALS['bridge_context']['admin'];
}

function is_singular() {
	return $GLOBALS['bridge_context']['singular'];
}

function is_front_page() {
	return $GLOBALS['bridge_context']['front_page'];
}

function is_home() {
	return $GLOBALS['bridge_context']['home'];
}

function is_preview() {
	return $GLOBALS['bridge_context']['preview'];
}

function wp_enqueue_script( $handle ) {
	$GLOBALS['bridge_enqueued'][] = $handle;
}

function wp_localize_script() {}
function rest_url( $path ) {
	return 'https://example.com/wp-json/' . $path;
}
function get_the_ID() {
	return 0;
}
function extrachill_get_current_site_key() {
	return 'main';
}
function add_action() {}

require dirname( __DIR__ ) . '/inc/cross-site-links/bridge-instrumentation.php';

function bridge_check_context( array $context ): bool {
	$GLOBALS['bridge_context']  = array_merge( $GLOBALS['bridge_context'], $context );
	$GLOBALS['bridge_enqueued'] = array();
	extrachill_bridge_enqueue_instrumentation();
	return in_array( 'extrachill-bridge-instrumentation', $GLOBALS['bridge_enqueued'], true );
}

$failures = 0;
function bridge_check( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	++$failures;
}

bridge_check( 'singular views enqueue instrumentation', bridge_check_context( array( 'singular' => true ) ) );
bridge_check( 'front pages enqueue instrumentation', bridge_check_context( array( 'singular' => false, 'front_page' => true ) ) );
bridge_check( 'posts home enqueues instrumentation', bridge_check_context( array( 'front_page' => false, 'home' => true ) ) );
bridge_check( 'ordinary archives remain excluded', ! bridge_check_context( array( 'home' => false ) ) );
bridge_check( 'previews remain excluded', ! bridge_check_context( array( 'front_page' => true, 'preview' => true ) ) );
bridge_check( 'admin remains excluded', ! bridge_check_context( array( 'preview' => false, 'admin' => true ) ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All bridge instrumentation tests passed.\n";
exit( 0 );
