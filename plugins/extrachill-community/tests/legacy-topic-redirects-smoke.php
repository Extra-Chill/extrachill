<?php
/**
 * Focused tests for legacy Community topic redirects.
 *
 * Run: php tests/legacy-topic-redirects-smoke.php
 */

define( 'ABSPATH', __DIR__ );
define( 'OBJECT', 'OBJECT' );

$GLOBALS['_test_actions']         = array();
$GLOBALS['_test_blog_id']         = 2;
$GLOBALS['_test_is_404']          = true;
$GLOBALS['_test_is_admin']        = false;
$GLOBALS['_test_is_ajax']         = false;
$GLOBALS['_test_current_posts']   = array();
$GLOBALS['_test_old_slug_posts']  = array();
$GLOBALS['_test_switched_blogs']  = array();
$GLOBALS['_test_restored_blogs']  = 0;
$GLOBALS['_test_query_vars']      = array();
$GLOBALS['_test_local_posts']     = array();
$GLOBALS['_test_main_posts']      = array();
$_SERVER['REQUEST_METHOD']        = 'GET';
$_SERVER['REQUEST_URI']           = '/';

function add_action( $tag, $callback, $priority = 10 ) {
	$GLOBALS['_test_actions'][ $tag ][ $priority ][] = $callback;
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['_test_filters'][ $tag ][ $priority ][] = $callback;
}

function extrachill_community_is_community_site(): bool {
	return 2 === (int) $GLOBALS['_test_blog_id'];
}

function is_admin() {
	return $GLOBALS['_test_is_admin'];
}

function wp_doing_ajax() {
	return $GLOBALS['_test_is_ajax'];
}

function is_404() {
	return $GLOBALS['_test_is_404'];
}

function sanitize_text_field( $value ) {
	return (string) $value;
}

function wp_unslash( $value ) {
	return $value;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_title( $title ) {
	return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( $title, '/' ) ) );
}

function ec_get_blog_id( $site ) {
	return 'main' === $site ? 1 : 2;
}

function ec_get_site_url( $site ) {
	return 'main' === $site ? 'https://extrachill.com' : 'https://community.extrachill.com';
}

function get_current_blog_id() {
	return $GLOBALS['_test_blog_id'];
}

function get_query_var( $var, $default = '' ) {
	return $GLOBALS['_test_query_vars'][ $var ] ?? $default;
}

function get_post( $post = null ) {
	if ( is_object( $post ) ) {
		return $post;
	}

	$posts = 1 === (int) $GLOBALS['_test_blog_id'] ? $GLOBALS['_test_main_posts'] : $GLOBALS['_test_local_posts'];
	return $posts[ (int) $post ] ?? null;
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['_test_switched_blogs'][] = $blog_id;
	$GLOBALS['_test_blog_id']          = $blog_id;
}

function restore_current_blog() {
	$GLOBALS['_test_restored_blogs']++;
	$GLOBALS['_test_blog_id'] = 2;
}

function get_page_by_path( $slug, $output = OBJECT, $post_type = 'page' ) {
	return $GLOBALS['_test_current_posts'][ $slug ] ?? null;
}

function get_post_status( $post ) {
	return is_object( $post ) ? $post->post_status : 'publish';
}

function get_posts( $args ) {
	return $GLOBALS['_test_old_slug_posts'][ $args['meta_value'] ] ?? array();
}

function get_permalink( $post ) {
	$slug = is_object( $post ) ? $post->post_name : $GLOBALS['_test_old_slug_posts']['permalinks'][ $post ];
	return 'https://extrachill.com/' . $slug . '/';
}

function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
	$GLOBALS['_test_redirect'] = compact( 'location', 'status', 'x_redirect_by' );
	return true;
}

function reset_test_request( $uri = '/t/example-post/' ) {
	$GLOBALS['_test_blog_id']        = 2;
	$GLOBALS['_test_is_404']         = true;
	$GLOBALS['_test_is_admin']       = false;
	$GLOBALS['_test_is_ajax']        = false;
	$GLOBALS['_test_current_posts']  = array();
	$GLOBALS['_test_old_slug_posts'] = array();
	$GLOBALS['_test_query_vars']     = array();
	$GLOBALS['_test_local_posts']    = array();
	$GLOBALS['_test_main_posts']     = array();
	$GLOBALS['_test_switched_blogs'] = array();
	unset( $GLOBALS['_test_redirect'] );
	$_SERVER['REQUEST_METHOD']       = 'GET';
	$_SERVER['REQUEST_URI']          = $uri;
}

require __DIR__ . '/../inc/core/legacy-topic-redirects.php';

$failures = 0;
function check( $label, $condition ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: $label\n";
		return;
	}

	echo "FAIL: $label\n";
	$failures++;
}

check(
	'redirect handler registered before core canonical redirects',
	in_array( 'extrachill_community_maybe_redirect_legacy_topic', $GLOBALS['_test_actions']['template_redirect'][9], true )
);
check(
	'main-site host is registered for safe redirects',
	in_array( 'extrachill.com', extrachill_community_allow_main_site_redirect_host( array( 'community.extrachill.com' ), 'extrachill.com' ), true )
);
check(
	'unrelated external host is not allowed',
	! in_array( 'example.com', extrachill_community_allow_main_site_redirect_host( array(), 'example.com' ), true )
);

reset_test_request( '/t/40-songs-about-cats/?utm_source=google' );
$GLOBALS['_test_current_posts']['40-songs-about-cats'] = (object) array(
	'post_name'   => '40-songs-about-cats',
	'post_status' => 'publish',
);
check(
	'published matching blog post resolves to its canonical URL',
	'https://extrachill.com/40-songs-about-cats/' === extrachill_community_get_legacy_topic_redirect_url()
);
check( 'main-site lookup restores the Community blog', 2 === $GLOBALS['_test_blog_id'] );

reset_test_request();
$GLOBALS['_test_is_404'] = false;
check( 'valid Community topic is never redirected', '' === extrachill_community_get_legacy_topic_redirect_url() );

reset_test_request( '/not-a-topic/example-post/' );
check( 'unrelated 404 is never redirected', '' === extrachill_community_get_legacy_topic_redirect_url() );

reset_test_request();
$GLOBALS['_test_blog_id'] = 1;
check( 'main-site request is never redirected', '' === extrachill_community_get_legacy_topic_redirect_url() );

reset_test_request();
$GLOBALS['_test_current_posts']['example-post'] = (object) array(
	'post_name'   => 'example-post',
	'post_status' => 'draft',
);
check( 'draft blog post is never exposed', '' === extrachill_community_get_legacy_topic_redirect_url() );

reset_test_request( '/t/old-article-slug/' );
$GLOBALS['_test_old_slug_posts']['old-article-slug'] = array( 42 );
$GLOBALS['_test_old_slug_posts']['permalinks'][42]    = 'current-article-slug';
check(
	'WordPress old slug resolves to the current canonical permalink',
	'https://extrachill.com/current-article-slug/' === extrachill_community_get_legacy_topic_redirect_url()
);

reset_test_request();
$_SERVER['REQUEST_METHOD'] = 'POST';
check( 'non-idempotent requests are never redirected', '' === extrachill_community_get_legacy_topic_redirect_url() );

$frederick_permalink = 'https://extrachill.com/frederick-the-younger-gold-light-and-fortune-teller/';

reset_test_request( '/?p=8445' );
$GLOBALS['_test_query_vars']['p']  = '8445';
$GLOBALS['_test_main_posts'][8445] = (object) array(
	'post_type'   => 'post',
	'post_status' => 'publish',
	'post_name'   => 'frederick-the-younger-gold-light-and-fortune-teller',
);
check(
	'sync-era shortlink to a published main post resolves to its canonical URL',
	$frederick_permalink === extrachill_community_get_legacy_shortlink_redirect_url()
);
check( 'shortlink lookup restores the Community blog', 2 === $GLOBALS['_test_blog_id'] );
check(
	'sync-era shortlink switch targets the main blog',
	array( 1 ) === $GLOBALS['_test_switched_blogs']
);

reset_test_request( '/?p=8454' );
$GLOBALS['_test_query_vars']['p']    = '8454';
$GLOBALS['_test_main_posts'][8454]   = (object) array(
	'post_type'   => 'attachment',
	'post_status' => 'inherit',
	'post_parent' => 8445,
);
$GLOBALS['_test_main_posts'][8445]   = (object) array(
	'post_type'   => 'post',
	'post_status' => 'publish',
	'post_name'   => 'frederick-the-younger-gold-light-and-fortune-teller',
);
check(
	'sync-era attachment shortlink resolves to its published parent permalink',
	$frederick_permalink === extrachill_community_get_legacy_shortlink_redirect_url()
);

reset_test_request( '/?p=8454' );
$GLOBALS['_test_query_vars']['p']  = '8454';
$GLOBALS['_test_main_posts'][8454] = (object) array(
	'post_type'   => 'attachment',
	'post_status' => 'inherit',
	'post_parent' => 7800,
);
$GLOBALS['_test_main_posts'][7800] = (object) array(
	'post_type'   => 'post',
	'post_status' => 'draft',
	'post_name'   => 'unpublished-parent',
);
check( 'attachment with an unpublished parent is never exposed', '' === extrachill_community_get_legacy_shortlink_redirect_url() );

reset_test_request( '/?p=999999' );
$GLOBALS['_test_query_vars']['p'] = '999999';
check( 'shortlink with no matching main-site post falls through to the standing 404', '' === extrachill_community_get_legacy_shortlink_redirect_url() );
extrachill_community_maybe_redirect_legacy_topic();
check( 'fall-through shortlink request never emits a redirect', ! isset( $GLOBALS['_test_redirect'] ) );

reset_test_request( '/?p=555' );
$GLOBALS['_test_query_vars']['p']  = '555';
$GLOBALS['_test_local_posts'][555] = (object) array(
	'post_type'   => 'post',
	'post_status' => 'publish',
	'post_name'   => 'community-post',
);
$GLOBALS['_test_main_posts'][555]  = (object) array(
	'post_type'   => 'post',
	'post_status' => 'publish',
	'post_name'   => 'main-post',
);
check( 'local Community post ID collision is never redirected away', '' === extrachill_community_get_legacy_shortlink_redirect_url() );

reset_test_request( '/foo/?p=8445' );
$GLOBALS['_test_query_vars']['p']  = '8445';
$GLOBALS['_test_main_posts'][8445] = (object) array(
	'post_type'   => 'post',
	'post_status' => 'publish',
	'post_name'   => 'frederick-the-younger-gold-light-and-fortune-teller',
);
check( 'shortlink on a non-root path is never redirected', '' === extrachill_community_get_legacy_shortlink_redirect_url() );

reset_test_request( '/?p=8445' );
$GLOBALS['_test_query_vars']['p']  = '8445';
$GLOBALS['_test_main_posts'][8445] = (object) array(
	'post_type'   => 'post',
	'post_status' => 'publish',
	'post_name'   => 'frederick-the-younger-gold-light-and-fortune-teller',
);
$GLOBALS['_test_is_404']           = false;
check( 'valid Community shortlink is never redirected', '' === extrachill_community_get_legacy_shortlink_redirect_url() );

reset_test_request( '/?p=8445' );
$GLOBALS['_test_query_vars']['p']  = '8445';
$GLOBALS['_test_main_posts'][8445] = (object) array(
	'post_type'   => 'post',
	'post_status' => 'publish',
	'post_name'   => 'frederick-the-younger-gold-light-and-fortune-teller',
);
$_SERVER['REQUEST_METHOD']         = 'POST';
check( 'non-idempotent shortlink requests are never redirected', '' === extrachill_community_get_legacy_shortlink_redirect_url() );

reset_test_request( '/?p=8445' );
$GLOBALS['_test_query_vars']['p'] = 'not-a-number';
check( 'non-numeric p query var is never redirected', '' === extrachill_community_get_legacy_shortlink_redirect_url() );

echo "\n";
if ( $failures > 0 ) {
	echo "$failures test(s) FAILED.\n";
	exit( 1 );
}

echo "All legacy-topic redirect tests passed.\n";
exit( 0 );
