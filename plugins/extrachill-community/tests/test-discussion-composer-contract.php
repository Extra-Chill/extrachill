<?php
/**
 * Focused tests for the deployment-discoverable composer contract.
 *
 * Run: php tests/test-discussion-composer-contract.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_options']        = array();
$GLOBALS['_test_option_updates'] = 0;
$GLOBALS['_test_actions']        = array();

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['_test_actions'][ $hook ][ $priority ][] = $callback;
}
function run_test_action( $hook ) {
	$callbacks = $GLOBALS['_test_actions'][ $hook ] ?? array();
	ksort( $callbacks );

	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $callback ) {
			call_user_func( $callback );
		}
	}
}
function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text ) {
	return $text;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['_test_options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['_test_options'][ $key ] = $value;
	++$GLOBALS['_test_option_updates'];
	return true;
}
function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) );
}
function sanitize_title( $value ) {
	return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $value ), '-' ) );
}
function wp_unslash( $value ) {
	return $value;
}
function get_taxonomy( $taxonomy ) {
	if ( ! in_array( $taxonomy, array( 'artist', 'festival', 'location' ), true ) ) {
		return false;
	}

	return (object) array( 'show_in_rest' => true );
}
function is_object_in_taxonomy( $post_type, $taxonomy ) {
	return 'topic' === $post_type && in_array( $taxonomy, array( 'artist', 'festival', 'location' ), true );
}
function get_term_by( $field, $slug, $taxonomy ) {
	return (object) array(
		'slug'    => $slug,
		'term_id' => 1,
	);
}
function is_wp_error() {
	return false;
}

require dirname( __DIR__ ) . '/inc/content/editor/composer-term-picker.php';

$failures = 0;
function check( $label, $condition ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: $label\n";
		return;
	}

	echo "FAIL: $label\n";
	++$failures;
}

$option            = extrachill_community_discussion_composer_contract_option();
$contract          = extrachill_community_discussion_composer_contract();
$picker_taxonomies = array_column( extrachill_community_term_picker_taxonomies(), 'taxonomy' );

check( 'contract is absent before initial publication', null === get_option( $option, null ) );
check( 'marker taxonomies exactly match the composer taxonomy source', $picker_taxonomies === $contract['supported_taxonomies'] );
check(
	'publisher is not registered before init',
	! in_array( 'extrachill_community_publish_discussion_composer_contract', $GLOBALS['_test_actions']['plugins_loaded'][20] ?? array(), true )
);
check(
	'publisher is registered on init at priority 20',
	in_array( 'extrachill_community_publish_discussion_composer_contract', $GLOBALS['_test_actions']['init'][20] ?? array(), true )
);

run_test_action( 'plugins_loaded' );
check( 'plugins_loaded does not publish the contract before translations are safe', null === get_option( $option, null ) );

run_test_action( 'init' );
check( 'initial init lifecycle publishes the contract', $contract === get_option( $option ) );
check( 'published option exactly matches the live definition', $contract === get_option( $option ) );
check( 'initial publication performs one option write', 1 === $GLOBALS['_test_option_updates'] );

run_test_action( 'init' );
check( 'idempotent publication performs no additional write', 1 === $GLOBALS['_test_option_updates'] );

$GLOBALS['_test_options'][ $option ] = array(
	'schema_version' => 0,
);
run_test_action( 'init' );
check( 'init lifecycle upgrades the old contract version', $contract === get_option( $option ) );
check( 'upgrade stores the complete current contract', $contract === get_option( $option ) );
check( 'upgrade performs exactly one additional write', 2 === $GLOBALS['_test_option_updates'] );

$keys  = $contract['query_parameters'];
$query = array(
	$keys['action']   => $contract['action'],
	$keys['taxonomy'] => 'artist',
	$keys['slug']     => 'phish',
);
check( 'marker query keys resolve through the live resolver', null !== extrachill_community_get_discussion_composer_state( $query ) );

foreach ( $contract['supported_taxonomies'] as $taxonomy ) {
	$query[ $keys['taxonomy'] ] = $taxonomy;
	check( "marker taxonomy {$taxonomy} is accepted by the live resolver", null !== extrachill_community_get_discussion_composer_state( $query ) );
}

$query[ $keys['taxonomy'] ] = 'post_tag';
check( 'taxonomy absent from marker is rejected by the live resolver', null === extrachill_community_get_discussion_composer_state( $query ) );
check( 'publication does not require bbPress functions', ! function_exists( 'bbp_get_topic_id' ) );
check( 'publication uses a site option without network activation APIs', ! function_exists( 'is_plugin_active_for_network' ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All discussion composer contract tests passed.\n";
exit( 0 );
