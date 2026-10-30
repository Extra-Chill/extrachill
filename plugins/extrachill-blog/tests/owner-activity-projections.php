<?php
/** Focused consumer coverage for Community and Wire activity projections. */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {}
class WP_Post {}
class WP_Term {
	public $slug = 'test-artist';
}

$projection_filters  = array();
$projection_response = array();
$projection_request  = array();
$projection_throw    = false;

function add_action() {}
function __( $text ) { return $text; }
function get_posts() { return array(); }
function absint( $value ) { return abs( (int) $value ); }
function get_option() { return 'F j, Y'; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function human_time_diff( $from, $to ) { return (string) ( $to - $from ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_filter( $hook, $callback ) {
	$GLOBALS['projection_filters'][ $hook ][] = $callback;
}
function remove_filter( $hook, $callback ) {
	$GLOBALS['projection_filters'][ $hook ] = array_values(
		array_filter(
			$GLOBALS['projection_filters'][ $hook ] ?? array(),
			static function ( $registered ) use ( $callback ) {
				return $registered !== $callback;
			}
		)
	);
}
function ec_cross_site_rest_request( $site_key, $method, $path, $args ) {
	$GLOBALS['projection_request'] = compact( 'site_key', 'method', 'path', 'args' );
	if ( $GLOBALS['projection_throw'] ) {
		throw new RuntimeException( 'Transport failed.' );
	}
	return $GLOBALS['projection_response'];
}

require dirname( __DIR__ ) . '/inc/core/cross-site-abilities.php';
require dirname( __DIR__ ) . '/inc/archive/artist-pillar.php';
require dirname( __DIR__ ) . '/inc/home/homepage-queries.php';

function projection_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['projection_response'] = array(
	'schema_version' => '1',
	'items'          => array(
		array(
			'canonical_url' => 'https://community.extrachill.com/topic/test/',
			'title'         => 'Test discussion',
			'timestamp'     => '2026-08-06T12:00:00+00:00',
			'activity_type' => 'discussion',
			'actor'         => array(
				'display_name' => 'Test User',
				'profile_url'  => null,
			),
			'relationships' => array(
				'forum'   => array(
					'name'          => 'Music',
					'slug'          => 'music',
					'canonical_url' => 'https://community.extrachill.com/forums/music/',
				),
				'artists' => array(),
			),
		),
	),
);
$community = extrachill_blog_get_artist_community_activity( new WP_Term() );
projection_assert( 1 === count( $community ), 'Community success should map one activity item.' );
projection_assert( 'Community discussion' === $community[0]['source'], 'Community mapping should preserve the Blog source label.' );
projection_assert( 'community' === $GLOBALS['projection_request']['site_key'], 'Community requests must target the owner site.' );
projection_assert( array( 'artist_slug' => 'test-artist', 'limit' => 4 ) === $GLOBALS['projection_request']['args']['query']['input'], 'Artist filtering must be delegated to the owner contract.' );
projection_assert( empty( $GLOBALS['projection_filters']['ec_cross_site_use_http_loopback'] ), 'Successful dispatch must remove its temporary filter.' );

$GLOBALS['projection_response'] = array(
	'schema_version' => '1',
	'items'          => array(
		array(
			'canonical_url' => 'https://wire.extrachill.com/festival-wire/test/',
			'title'         => 'Test dispatch',
			'timestamp'     => '2026-08-06T12:00:00+00:00',
			'image'         => null,
			'summary'       => null,
			'source'        => 'extra-chill-news-wire',
			'type'          => 'festival-wire',
		),
	),
);
$wire = extrachill_blog_get_wire_latest( 4 );
projection_assert( 1 === count( $wire ), 'Wire success should map one homepage item.' );
projection_assert( 'Test dispatch' === $wire[0]['title'], 'Wire mapping should preserve the established title field.' );
projection_assert( 'wire' === $GLOBALS['projection_request']['site_key'], 'Wire requests must target the owner site.' );
projection_assert( false !== strpos( $GLOBALS['projection_request']['path'], 'extrachill-news-wire/get-recent-activity/run' ), 'Wire requests must execute the merged owner Ability.' );

$GLOBALS['projection_response'] = array( 'schema_version' => '1', 'items' => array() );
projection_assert( array() === extrachill_blog_get_wire_latest(), 'A versioned empty Wire result should remain empty.' );
projection_assert( array() === extrachill_blog_get_artist_community_activity( new WP_Term() ), 'A versioned empty Community result should remain empty.' );

$GLOBALS['projection_response'] = new WP_Error();
projection_assert( array() === extrachill_blog_get_wire_latest(), 'An unavailable Wire owner should fail closed.' );
projection_assert( array() === extrachill_blog_get_artist_community_activity( new WP_Term() ), 'An unavailable Community owner should fail closed.' );

$GLOBALS['projection_response'] = array( 'schema_version' => '2', 'items' => array() );
projection_assert( array() === extrachill_blog_get_wire_latest(), 'An unsupported Wire schema should fail closed.' );
$GLOBALS['projection_response'] = array( 'schema_version' => '1', 'items' => array( array( 'title' => 'Incomplete' ) ) );
projection_assert( array() === extrachill_blog_get_wire_latest(), 'Malformed Wire items should be omitted.' );
$GLOBALS['projection_response'] = array( 'schema_version' => '1', 'items' => array( array( 'title' => 'Incomplete' ) ) );
projection_assert( array() === extrachill_blog_get_artist_community_activity( new WP_Term() ), 'Malformed Community items should be omitted.' );

$GLOBALS['projection_throw'] = true;
try {
	extrachill_blog_request_cross_site_ability( 'wire', 'test/throw', array() );
	projection_assert( false, 'Thrown transport errors should propagate.' );
} catch ( RuntimeException $error ) {
	projection_assert( 'Transport failed.' === $error->getMessage(), 'The original transport exception should propagate.' );
}
projection_assert( empty( $GLOBALS['projection_filters']['ec_cross_site_use_http_loopback'] ), 'Thrown dispatch must remove its temporary filter.' );

try {
	extrachill_blog_get_artist_platform_activity( new WP_Term() );
	projection_assert( false, 'Thrown Artist Platform requests should propagate.' );
} catch ( RuntimeException $error ) {
	projection_assert( 'Transport failed.' === $error->getMessage(), 'The Artist Platform request should preserve the transport exception.' );
}
projection_assert( empty( $GLOBALS['projection_filters']['ec_cross_site_use_http_loopback'] ), 'Thrown Artist Platform dispatch must remove its temporary filter.' );

$artist_source = file_get_contents( dirname( __DIR__ ) . '/inc/archive/artist-pillar.php' );
$home_source   = file_get_contents( dirname( __DIR__ ) . '/inc/home/homepage-queries.php' );
projection_assert( false === strpos( $artist_source, "'post_type'           => 'topic'" ), 'Blog must not query Community topic storage.' );
projection_assert( false === strpos( $artist_source, 'bbp_get_topic_permalink' ), 'Blog must not call Community permalink helpers.' );
projection_assert( false === strpos( $artist_source, 'switch_to_blog' ), 'Artist activity must not switch directly into sibling sites.' );
projection_assert( false === strpos( $home_source, "'post_type'   => 'festival_wire'" ), 'Blog must not query Wire storage.' );
projection_assert( false === strpos( $home_source, 'switch_to_blog' ), 'Homepage activity must not switch directly into Wire.' );

fwrite( STDOUT, "Owner activity projection tests passed.\n" );
