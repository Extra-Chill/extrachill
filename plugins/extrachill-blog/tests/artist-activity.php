<?php
/**
 * Focused coverage for artist activity ordering and item validation.
 *
 * Run with: php tests/artist-activity.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function add_action() {}
function add_filter() {}
function __($text) { return $text; }
function get_option() { return 'F j, Y'; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }

require_once dirname( __DIR__ ) . '/inc/archive/artist-pillar.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

$valid = extrachill_blog_build_artist_activity_item(
	'New show',
	'https://events.example.test/show/',
	'2026-07-14T20:00:00+00:00',
	'Events'
);
assert_same( '2026-07-14T20:00:00+00:00', $valid['date'], 'Activity items should preserve a normalized chronological date.' );
assert_same( null, extrachill_blog_build_artist_activity_item( '', 'https://example.test/', '2026-07-14', 'Events' ), 'Items without a title must be omitted.' );
assert_same( null, extrachill_blog_build_artist_activity_item( 'Missing date', 'https://example.test/', '', 'Events' ), 'Items without a date must be omitted.' );

$sorted = extrachill_blog_sort_artist_activity(
	array(
		array( 'title' => 'Older', 'timestamp' => 100 ),
		array( 'title' => 'Newest', 'timestamp' => 300 ),
		array( 'title' => 'Middle', 'timestamp' => 200 ),
	)
);
assert_same( 'Newest', $sorted[0]['title'], 'Artist activity must sort newest first.' );
assert_same( 'Older', $sorted[2]['title'], 'Artist activity must retain the oldest item last.' );

fwrite( STDOUT, "Artist activity tests passed.\n" );
