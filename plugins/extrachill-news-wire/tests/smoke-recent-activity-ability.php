<?php
/**
 * Focused smoke coverage for the public recent Wire activity ability.
 *
 * Run with: php tests/smoke-recent-activity-ability.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Post {
	public $ID;
	public $post_status;
	public $post_password;

	public function __construct( $id, $status = 'publish', $password = '' ) {
		$this->ID            = $id;
		$this->post_status   = $status;
		$this->post_password = $password;
	}
}

$ability = array();
$posts   = array();
$query   = array();

function add_action() {}
function __( $text ) { return $text; }
function wp_register_ability_category() {}
function wp_has_ability_category() { return false; }
function wp_register_ability( $name, $args ) { global $ability; $ability = compact( 'name', 'args' ); }
function get_posts( $args ) {
	global $posts, $query;
	$query = $args;
	return array_slice(
		array_values(
			array_filter(
				$posts,
				static function ( $post ) use ( $args ) {
					return $post->post_status === $args['post_status'];
				}
			)
		),
		0,
		$args['posts_per_page']
	);
}
function get_post_status( $post ) { return $post->post_status; }
function get_permalink( $post ) { return 'https://wire.extrachill.com/festival-wire/item-' . $post->ID . '/'; }
function get_the_title( $post ) { return 'Wire item ' . $post->ID; }
function get_post_time( $format, $gmt, $post ) { return sprintf( '2026-08-%02dT12:00:00+00:00', $post->ID ); }
function get_the_excerpt( $post ) { return 1 === $post->ID ? 'Projected summary.' : ''; }
function get_the_post_thumbnail_url( $post ) { return 1 === $post->ID ? 'https://wire.extrachill.com/item-1.jpg' : false; }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }

require dirname( __DIR__ ) . '/includes/recent-activity-ability.php';

function expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

ec_news_wire_register_recent_activity_ability();

expect( 'extrachill-news-wire/get-recent-activity' === $ability['name'], 'The owner ability name must remain stable.' );
expect( '__return_true' === $ability['args']['permission_callback'], 'The projection must be publicly readable.' );
expect( true === $ability['args']['meta']['annotations']['readonly'], 'The ability must declare read-only behavior.' );
expect( 20 === $ability['args']['input_schema']['properties']['limit']['maximum'], 'The input schema must enforce the strict maximum.' );
expect( false === $ability['args']['input_schema']['additionalProperties'], 'The input schema must reject unknown fields.' );

$schema      = $ability['args']['output_schema'];
$item_schema = $schema['properties']['items']['items'];
$item_keys   = array( 'canonical_url', 'title', 'timestamp', 'image', 'summary', 'source', 'type' );

expect( array( 'schema_version', 'items' ) === $schema['required'], 'The envelope schema must be exact.' );
expect( false === $schema['additionalProperties'], 'The envelope must reject unknown fields.' );
expect( $item_keys === $item_schema['required'], 'Every version-one item field must be required.' );
expect( $item_keys === array_keys( $item_schema['properties'] ), 'The item schema must expose only documented fields.' );
expect( false === $item_schema['additionalProperties'], 'Items must reject unknown fields.' );
expect( 20 === $schema['properties']['items']['maxItems'], 'The output schema must enforce the strict maximum.' );

$result = ec_news_wire_get_recent_activity();
expect( array( 'schema_version', 'items' ) === array_keys( $result ), 'Empty output must preserve the exact envelope.' );
expect( array() === $result['items'], 'No public posts must produce an empty item list.' );

$posts  = array( new WP_Post( 1 ), new WP_Post( 2, 'draft' ), new WP_Post( 3, 'private' ), new WP_Post( 4, 'publish', 'secret' ) );
$result = ec_news_wire_get_recent_activity( array( 'limit' => 5 ) );

expect( 1 === count( $result['items'] ), 'Unpublished and password-protected items must never appear in the public projection.' );
expect( 'publish' === $query['post_status'], 'The owner query must request only public items.' );
expect( false === $query['has_password'], 'The owner query must exclude password-protected items.' );
expect( 'https://wire.extrachill.com/festival-wire/item-1/' === $result['items'][0]['canonical_url'], 'Canonical links must come from WordPress permalinks.' );
expect( $item_keys === array_keys( $result['items'][0] ), 'Returned items must match the exact schema.' );
expect( 'Projected summary.' === $result['items'][0]['summary'], 'Available summaries must be projected.' );
expect( 'https://wire.extrachill.com/item-1.jpg' === $result['items'][0]['image'], 'Available images must be projected.' );

$posts = array();
for ( $id = 1; $id <= 25; $id++ ) {
	$posts[] = new WP_Post( $id );
}
$result = ec_news_wire_get_recent_activity( array( 'limit' => 100 ) );

expect( 20 === $query['posts_per_page'], 'Direct callback use must clamp the query to the strict maximum.' );
expect( 20 === count( $result['items'] ), 'Direct callback use must never return more than the strict maximum.' );
expect( null === $result['items'][1]['summary'], 'Unavailable summaries must have a stable null value.' );
expect( null === $result['items'][1]['image'], 'Unavailable images must have a stable null value.' );

print "Recent Wire activity ability smoke test passed.\n";
