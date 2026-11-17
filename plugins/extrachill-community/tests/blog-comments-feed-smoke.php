<?php
/**
 * Regression coverage for issue #1567: the bare /blog-comments page (no
 * user_id) must show the logged-in visitor's own comments, or a login prompt
 * for logged-out visitors, instead of "User ID not provided."
 *
 * Run: php tests/blog-comments-feed-smoke.php
 *
 * @package ExtraChillCommunity
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_current_user'] = 0;

function add_filter() {}
function get_userdata() {
	return false;
}
function absint( $value ) {
	return abs( (int) $value );
}
function is_user_logged_in() {
	return $GLOBALS['_test_current_user'] > 0;
}
function get_current_user_id() {
	return $GLOBALS['_test_current_user'];
}
function ec_get_site_url( $key ) {
	return 'community' === $key ? 'https://community.extrachill.com' : 'https://extrachill.com';
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
function add_query_arg( $key, $value, $url ) {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
}
function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES );
}
function esc_html__( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}

require __DIR__ . '/../inc/content/main-site-comments.php';

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

check( 'render function exists', function_exists( 'extrachill_community_render_blog_comments_feed' ) );

// Logged out, no user_id: friendly login prompt that returns to the feed.
$GLOBALS['_test_current_user'] = 0;
$html                          = extrachill_community_render_blog_comments_feed( 0, 'https://community.extrachill.com/blog-comments' );
check( 'logged-out bare feed no longer says "User ID not provided"', ! str_contains( $html, 'User ID not provided' ) );
check( 'logged-out bare feed prompts to log in', str_contains( $html, 'Log in to see the comments' ) );
check( 'logged-out bare feed links to community login with redirect back', str_contains( $html, 'href="https://community.extrachill.com/login?redirect_to=' . rawurlencode( 'https://community.extrachill.com/blog-comments' ) . '"' ) );

// Source-level: the template routes through the render helper and the old message is gone.
$template = (string) file_get_contents( __DIR__ . '/../page-templates/main-blog-comments-feed.php' );
check( 'template renders via extrachill_community_render_blog_comments_feed()', str_contains( $template, 'extrachill_community_render_blog_comments_feed(' ) );
check( 'template no longer prints "User ID not provided."', ! str_contains( $template, 'User ID not provided' ) );

// Routing: logged-in bare feed and explicit user_id both reach the comment query.
// The real query needs multisite, so stop at ec_get_blog_id() and assert we got there.
function ec_get_blog_id() {
	return 0;
}

$GLOBALS['_test_current_user'] = 42;
$html                          = extrachill_community_render_blog_comments_feed( 0, 'https://community.extrachill.com/blog-comments' );
check( 'logged-in bare feed queries the current user\'s comments', str_contains( $html, 'Main site not configured.' ) );

$GLOBALS['_test_current_user'] = 0;
$html                          = extrachill_community_render_blog_comments_feed( '510' );
check( 'explicit user_id queries that user\'s comments when logged out', str_contains( $html, 'Main site not configured.' ) );

if ( $failures > 0 ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}

echo "\nAll blog comments feed checks passed.\n";
