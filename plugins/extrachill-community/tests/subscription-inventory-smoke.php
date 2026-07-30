<?php
/** Standalone contract test for subscription inventory enrichment. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

class WP_Term {
	public $term_id;
	public $name;

	public function __construct( $term_id, $name ) {
		$this->term_id = $term_id;
		$this->name    = $name;
	}
}

function add_action() {}
function wp_register_ability( $name, $definition ) {
	$GLOBALS['_test_abilities'][ $name ] = $definition;
}
function __( $text ) {
	return $text;
}
function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) );
}
function sanitize_title( $value ) {
	return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $value ), '-' ) );
}
function ec_get_blog_id( $key ) {
	return array( 'main' => 1, 'artist' => 4, 'events' => 7 )[ $key ] ?? 0;
}
function switch_to_blog( $blog_id ) {
	$GLOBALS['_test_blog'] = $blog_id;
}
function restore_current_blog() {
	$GLOBALS['_test_blog'] = 2;
}
function get_term_by( $field, $slug, $taxonomy ) {
	if ( 'missing' === $slug ) {
		return false;
	}
	return new WP_Term( 12, ucwords( str_replace( '-', ' ', $slug ) ) );
}
function get_term_link( $term ) {
	return 'https://site.example.com/' . strtolower( str_replace( ' ', '-', $term->name ) ) . '/';
}
function is_wp_error() {
	return false;
}
function get_term_meta() {
	return 44;
}
function absint( $value ) {
	return abs( (int) $value );
}
function get_permalink( $post_id ) {
	return 44 === $post_id ? 'https://artist.example.com/kid-lake/' : false;
}

require __DIR__ . '/../inc/core/subscription-inventory.php';

extrachill_community_register_subscription_inventory_ability();
$ability = $GLOBALS['_test_abilities']['extrachill/community-resolve-subscription-entities'] ?? null;
$result  = extrachill_community_resolve_subscription_entities(
	array(
		'identities' => array(
			array( 'entity_type' => 'artist', 'taxonomy' => 'artist', 'slug' => 'kid-lake' ),
			array( 'entity_type' => 'venue', 'taxonomy' => 'venue', 'slug' => 'missing' ),
		),
	)
);

$passed = is_array( $ability )
	&& 'is_user_logged_in' === $ability['permission_callback']
	&& true === $result['entities'][0]['resolved']
	&& 'Kid Lake' === $result['entities'][0]['name']
	&& 'https://artist.example.com/kid-lake/' === $result['entities'][0]['url']
	&& false === $result['entities'][1]['resolved']
	&& 'missing' === $result['entities'][1]['slug']
	&& 2 === $GLOBALS['_test_blog'];

if ( ! $passed ) {
	fwrite( STDERR, "FAIL: Subscription inventory enrichment contract is invalid.\n" );
	exit( 1 );
}

echo "PASS: Subscription inventory enriches canonical entities and preserves unknown identities.\n";
