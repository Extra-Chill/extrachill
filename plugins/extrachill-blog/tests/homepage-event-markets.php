<?php
/**
 * Focused tests for homepage event-market ordering.
 *
 * Run with: php tests/homepage-event-markets.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$test_logged_in = false;
$test_settings  = array();

function get_posts() {
	return array();
}

function is_user_logged_in() {
	global $test_logged_in;
	return $test_logged_in;
}

function wp_has_ability( $name ) {
	return 'extrachill/get-user-settings' === $name;
}

function wp_get_ability( $name ) {
	return new class() {
		public function execute() {
			global $test_settings;
			return $test_settings;
		}
	};
}

function is_wp_error() {
	return false;
}

require_once dirname( __DIR__ ) . '/inc/home/homepage-queries.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

$global = array();
for ( $index = 1; $index <= 8; ++$index ) {
	$global[] = array(
		'slug'  => 'market-' . $index,
		'name'  => 'Market ' . $index,
		'count' => 10 - $index,
	);
}

$existing = array(
	'slug'  => 'market-3',
	'name'  => 'Market 3',
	'count' => 7,
);
$result   = extrachill_blog_prioritize_event_market( $global, $existing );

assert_same( 'market-3', $result[0]['slug'], 'Existing preferred market should move to the front.' );
assert_same( 8, count( $result ), 'Existing preferred market should preserve eight rows.' );
assert_same( 1, count( array_filter( $result, fn( $row ) => 'market-3' === $row['slug'] ) ), 'Existing preferred market should not be duplicated.' );

$outside = array(
	'slug'  => 'preferred-market',
	'name'  => 'Preferred Market',
	'count' => 2,
);
$result  = extrachill_blog_prioritize_event_market( $global, $outside );

assert_same( 'preferred-market', $result[0]['slug'], 'Outside preferred market should lead the list.' );
assert_same( 8, count( $result ), 'Outside preferred market should replace the eighth global row.' );
assert_same( 'market-7', $result[7]['slug'], 'Global order should be retained after the preferred market.' );

$result = extrachill_blog_prioritize_event_market( $global, array() );
assert_same( $global, $result, 'Missing preference should retain global markets unchanged.' );

assert_same( '', extrachill_blog_get_default_event_location_slug(), 'Anonymous requests should not read a preference.' );

$test_logged_in = true;
$test_settings  = array();
assert_same( '', extrachill_blog_get_default_event_location_slug(), 'Missing dependency field should fail open.' );

$test_settings = array(
	'default_event_location' => array(
		'slug' => 'new-york-city',
	),
);
assert_same( 'new-york-city', extrachill_blog_get_default_event_location_slug(), 'Resolved canonical preference slug should pass through unchanged.' );

fwrite( STDOUT, "Homepage event-market tests passed.\n" );
