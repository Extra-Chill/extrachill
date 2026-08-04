<?php
/**
 * Focused coverage for artist activity ordering and item validation.
 *
 * Run with: php tests/artist-activity.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Post {}

function add_action() {}
function add_filter() {}
function __($text) { return $text; }
function get_option() { return 'F j, Y'; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function sanitize_html_class( $class ) { return preg_replace( '/[^A-Za-z0-9_-]/', '-', $class ); }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }

require_once dirname( __DIR__ ) . '/inc/archive/artist-pillar.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

function assert_true( $actual, $message ) {
	if ( ! $actual ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

$valid = extrachill_blog_build_artist_activity_item(
	'New show',
	'https://events.example.test/show/',
	'2026-07-14T20:00:00+00:00',
	'Events',
	'July 14, 2026',
	'Example Venue, 8:00 pm',
	'upcoming'
);
assert_same( '2026-07-14T20:00:00+00:00', $valid['date'], 'Activity items should preserve a normalized chronological date.' );
assert_same( 'Example Venue, 8:00 pm', $valid['context'], 'Event activity items should retain Events-owned venue and time context.' );
assert_same( 'upcoming', $valid['timing'], 'Event activity items should retain Events-owned timing context.' );
assert_same( null, extrachill_blog_build_artist_activity_item( '', 'https://example.test/', '2026-07-14', 'Events' ), 'Items without a title must be omitted.' );
assert_same( null, extrachill_blog_build_artist_activity_item( 'Missing date', 'https://example.test/', '', 'Events' ), 'Items without a date must be omitted.' );

$relationships = array(
	'venue'    => array( 'name' => 'The <Venue>', 'slug' => 'the-venue', 'url' => 'https://events.example.test/venue/the-venue?label="unsafe"' ),
	'location' => array( 'name' => 'Charleston', 'slug' => 'charleston', 'url' => 'https://events.example.test/location/charleston', 'display' => 'Charleston & Coast' ),
	'festival' => array( 'name' => 'Test Fest', 'slug' => 'test-fest', 'url' => 'https://events.example.test/festival/test-fest' ),
);
$event = extrachill_blog_build_artist_activity_item( 'Event', 'https://events.example.test/event', '2026-07-14', 'Events', '', '', '', $relationships );
assert_same( $relationships, $event['relationships'], 'Events relationship objects must remain source-owned activity data.' );
$badges = extrachill_blog_get_artist_activity_badges( $event['relationships'] );
assert_same( 3, count( $badges ), 'Venue, location, and festival relationships should each render a badge.' );
assert_same( 'The <Venue>', $badges[0]['label'], 'Venue badge labels should preserve source data for escaped rendering.' );
assert_same( 'Charleston & Coast', $badges[1]['label'], 'Location display labels should take precedence when Events provides one.' );
assert_same( 'taxonomy-badge festival-badge festival-test-fest', $badges[2]['class'], 'Festival badges should use the established taxonomy classes.' );
ob_start();
extrachill_blog_render_artist_activity_badges( $relationships );
$badge_markup = ob_get_clean();
assert_true( false !== strpos( $badge_markup, 'The &lt;Venue&gt;' ), 'Badge labels must be HTML escaped.' );
assert_true( false !== strpos( $badge_markup, 'label=&quot;unsafe&quot;' ), 'Badge URLs must be attribute escaped.' );
assert_true( false !== strpos( $badge_markup, 'class="taxonomy-badge venue-badge venue-the-venue"' ), 'Badge classes must be attribute escaped.' );

$partial_badges = extrachill_blog_get_artist_activity_badges(
	array(
		'venue'    => null,
		'location' => array( 'name' => 'Austin', 'url' => 'https://events.example.test/location/austin' ),
		'festival' => array( 'name' => 'No Link Fest', 'slug' => 'no-link-fest' ),
	)
);
assert_same( 1, count( $partial_badges ), 'Null and incomplete relationships must be omitted.' );
assert_same( 'taxonomy-badge location-badge', $partial_badges[0]['class'], 'A relationship without a slug must retain its established base class.' );
assert_same( array(), extrachill_blog_get_artist_activity_badges( array( 'venue' => 'invalid' ) ), 'Malformed relationship data must be omitted.' );
assert_same( array(), extrachill_blog_build_artist_activity_item( 'Coverage', 'https://example.test/coverage', '2026-07-14', 'Editorial coverage' )['relationships'], 'Non-event activity rows must not gain relationship badges.' );

function bbp_get_topic_permalink( $topic_id ) { return 'https://community.example.test/t/canonical-topic-' . $topic_id; }

$topic = new WP_Post();
$topic->ID = 42;
assert_same( 'https://community.example.test/t/canonical-topic-42', extrachill_blog_get_artist_community_topic_permalink( $topic ), 'Community activity must use the canonical bbPress topic permalink.' );

$artist_pillar_source         = file_get_contents( dirname( __DIR__ ) . '/inc/archive/artist-pillar.php' );
$festival_subscriptions_source = file_get_contents( dirname( __DIR__ ) . '/inc/archive/festival-subscriptions.php' );
assert_same( 2, substr_count( $artist_pillar_source, 'class="button-1 button-medium entity-pillar-subscription-button"' ), 'Artist preference controls should use theme button classes.' );
assert_true( false !== strpos( $artist_pillar_source, 'Artist preferences' ), 'Artist preferences should use one coherent heading.' );
assert_true( false !== strpos( $artist_pillar_source, "ec_get_site_url( 'docs' )" ), 'Artist preferences should resolve documentation through the network site registry.' );
assert_true( false === strpos( $artist_pillar_source, 'entity-pillar-preferences__row' ), 'Artist preferences should not render nested notice rows.' );
assert_same( 2, substr_count( $festival_subscriptions_source, 'class="button-1 button-medium entity-pillar-subscription-button"' ), 'Festival subscription controls should use theme button classes.' );

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
