<?php
/**
 * Focused tests for artist memberships on public Community profiles.
 *
 * Run: php tests/test-public-profile-artist-memberships.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_artist_ids']      = array();
$GLOBALS['_test_posts']           = array();
$GLOBALS['_test_blog_id']         = 2;
$GLOBALS['_test_blog_stack']      = array();
$GLOBALS['_test_permalink_host']  = 'artist.extrachill.com';
$GLOBALS['_test_display_user_id'] = 10;

function absint( $value ) {
	return abs( (int) $value );
}

function ec_get_artists_for_user( $user_id ) {
	return $GLOBALS['_test_artist_ids'];
}

function ec_get_blog_id( $site ) {
	return 'artist' === $site ? 4 : null;
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['_test_blog_stack'][] = $GLOBALS['_test_blog_id'];
	$GLOBALS['_test_blog_id']      = $blog_id;
}

function restore_current_blog() {
	$GLOBALS['_test_blog_id'] = array_pop( $GLOBALS['_test_blog_stack'] );
}

function get_post( $post_id ) {
	return $GLOBALS['_test_posts'][ $post_id ] ?? null;
}

function get_permalink( $post ) {
	return sprintf( 'https://%s/artists/%s/', $GLOBALS['_test_permalink_host'], $post->post_name );
}

function bbp_get_displayed_user_id() {
	return $GLOBALS['_test_display_user_id'];
}

function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text ) {
	return esc_html( $text );
}

require __DIR__ . '/../inc/user-profiles/public-profile.php';

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

$GLOBALS['_test_artist_ids'] = array( 101, 102, 103 );
$GLOBALS['_test_posts']      = array(
	101 => (object) array(
		'post_title'  => 'The Example Band',
		'post_name'   => 'the-example-band',
		'post_status' => 'publish',
	),
	103 => (object) array(
		'post_title'  => 'Unpublished Artist',
		'post_name'   => 'unpublished-artist',
		'post_status' => 'draft',
	),
);

$memberships = extrachill_community_get_artist_memberships( 10 );

check(
	'published artist uses the canonical artist-profile permalink',
	array( 'The Example Band' => 'https://artist.extrachill.com/artists/the-example-band/' ) === $memberships
);
check( 'missing and unpublished artist posts are omitted', 1 === count( $memberships ) );
check( 'artist lookup restores the original Community blog', 2 === $GLOBALS['_test_blog_id'] && array() === $GLOBALS['_test_blog_stack'] );

$GLOBALS['_test_permalink_host'] = 'artist.example.com';
$memberships                     = extrachill_community_get_artist_memberships( 10 );
check(
	'canonical permalink preserves a custom artist domain',
	'https://artist.example.com/artists/the-example-band/' === $memberships['The Example Band']
);

$GLOBALS['_test_posts'][101]->post_title = '<script>alert("name")</script>';
$GLOBALS['_test_posts'][101]->post_name  = 'artist" onclick="alert(1)';

ob_start();
extrachill_community_display_identity_line();
$rendered_profile = ob_get_clean();

check( 'rendered profile escapes artist names', false === strpos( $rendered_profile, '<script>' ) );
check( 'rendered profile escapes canonical artist URLs', false === strpos( $rendered_profile, 'href="https://artist.example.com/artists/artist" onclick=' ) );
check( 'rendered profile includes the membership identity line', false !== strpos( $rendered_profile, 'Member of:' ) );
check( 'rendered profile smoke restores the Community blog', 2 === $GLOBALS['_test_blog_id'] && array() === $GLOBALS['_test_blog_stack'] );

echo "\n";
if ( $failures > 0 ) {
	echo "$failures test(s) FAILED.\n";
	exit( 1 );
}

echo "All public-profile artist membership tests passed.\n";
exit( 0 );
