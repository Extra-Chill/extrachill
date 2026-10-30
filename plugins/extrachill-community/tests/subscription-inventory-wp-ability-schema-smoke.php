<?php
/** Runtime schema coverage using WordPress core's WP_Ability validator. */

if ( ! defined( 'ABSPATH' ) ) {
	$wordpress_root = $argv[1] ?? getenv( 'WP_ROOT' ) ?: '';
	if ( '' === $wordpress_root || ! file_exists( $wordpress_root . '/wp-load.php' ) ) {
		fwrite( STDERR, "Usage: php tests/subscription-inventory-wp-ability-schema-smoke.php /path/to/wordpress\n" );
		exit( 1 );
	}

	define( 'SHORTINIT', true );
	define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
	require $wordpress_root . '/wp-load.php';
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
ini_set( 'log_errors', '0' );

require_once ABSPATH . WPINC . '/rest-api.php';
require_once ABSPATH . WPINC . '/abilities-api/class-wp-ability.php';

$GLOBALS['_subscription_schema_abilities'] = array();

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return true;
	}
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['_subscription_schema_abilities'][ $name ] = new WP_Ability( $name, $args );
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return $GLOBALS['_subscription_schema_abilities'][ $name ] ?? null;
	}
}

require dirname( __DIR__ ) . '/inc/core/subscription-inventory.php';
extrachill_community_register_subscription_inventory_ability();

$ability = wp_get_ability( 'extrachill/community-resolve-subscription-entities' );

function subscription_schema_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

subscription_schema_assert( $ability instanceof WP_Ability, 'Subscription projection must register as a WP_Ability.' );

$identities = array(
	array( 'entity_type' => 'artist', 'taxonomy' => 'artist', 'slug' => 'kid-lake' ),
	array( 'entity_type' => 'festival', 'taxonomy' => 'festival', 'slug' => 'high-water' ),
	array( 'entity_type' => 'venue', 'taxonomy' => 'venue', 'slug' => 'music-farm' ),
	array( 'entity_type' => 'location', 'taxonomy' => 'location', 'slug' => 'charleston-sc' ),
	array( 'entity_type' => 'local_scene_digest', 'taxonomy' => 'location', 'slug' => 'charleston-sc' ),
);
$valid_input = array(
	'schema_version' => '1',
	'identities'     => $identities,
);

subscription_schema_assert( true === $ability->validate_input( $valid_input ), 'Version one must accept every supported identity pair.' );

$invalid_inputs   = array();
$invalid_inputs[] = array( 'schema_version' => '2', 'identities' => $identities );
$invalid_inputs[] = array( 'schema_version' => '1', 'identities' => array() );
$invalid_inputs[] = array( 'schema_version' => '1', 'identities' => array_fill( 0, 101, $identities[0] ) );
$invalid_inputs[] = array( 'schema_version' => '1', 'identities' => array( $identities[0], $identities[0] ) );
$invalid_inputs[] = array( 'schema_version' => '1', 'identities' => array( array( 'entity_type' => 'artist', 'taxonomy' => 'venue', 'slug' => 'kid-lake' ) ) );
$invalid_inputs[] = array( 'schema_version' => '1', 'identities' => array( array( 'entity_type' => 'artist', 'taxonomy' => 'artist', 'slug' => 'Kid Lake' ) ) );
$invalid_inputs[] = array( 'schema_version' => '1', 'identities' => array( $identities[0] ), 'extra' => true );

foreach ( $invalid_inputs as $invalid_input ) {
	subscription_schema_assert( is_wp_error( $ability->validate_input( $invalid_input ) ), 'Core validation must reject every malformed input contract.' );
}

$valid_output = array(
	'schema_version' => '1',
	'entities'       => array(
		array_merge( $identities[0], array( 'status' => 'resolved', 'name' => 'Kid Lake', 'url' => 'https://artist.example.com/kid-lake/', 'resolved' => true ) ),
		array_merge( $identities[1], array( 'status' => 'not_found', 'name' => '', 'url' => '', 'resolved' => false ) ),
		array_merge( $identities[2], array( 'status' => 'provider_unavailable', 'name' => '', 'url' => '', 'resolved' => false ) ),
		array_merge( $identities[3], array( 'status' => 'provider_error', 'name' => '', 'url' => '', 'resolved' => false ) ),
		array_merge( $identities[4], array( 'status' => 'malformed_response', 'name' => '', 'url' => '', 'resolved' => false ) ),
	),
);
$output_schema = $ability->get_output_schema();

subscription_schema_assert( true === rest_validate_value_from_schema( $valid_output, $output_schema, 'output' ), 'Core validation must accept every explicit output status.' );

$invalid_output                              = $valid_output;
$invalid_output['entities'][0]['resolved']   = false;
subscription_schema_assert( is_wp_error( rest_validate_value_from_schema( $invalid_output, $output_schema, 'output' ) ), 'Resolved presentation must require resolved=true.' );
$invalid_output                              = $valid_output;
$invalid_output['entities'][1]['name']       = 'Stale name';
subscription_schema_assert( is_wp_error( rest_validate_value_from_schema( $invalid_output, $output_schema, 'output' ) ), 'Unresolved presentation must reject stale owner data.' );
$invalid_output                              = $valid_output;
$invalid_output['entities'][2]['extra']      = true;
subscription_schema_assert( is_wp_error( rest_validate_value_from_schema( $invalid_output, $output_schema, 'output' ) ), 'Output rows must reject additional fields.' );
$invalid_output                              = $valid_output;
$invalid_output['schema_version']            = '2';
subscription_schema_assert( is_wp_error( rest_validate_value_from_schema( $invalid_output, $output_schema, 'output' ) ), 'Output must reject unknown schema versions.' );

fwrite( STDOUT, "PASS: WordPress validates the exact versioned subscription projection schemas.\n" );
