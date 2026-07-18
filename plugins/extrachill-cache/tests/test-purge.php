<?php
/**
 * Focused tests for post lifecycle cache invalidation.
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Standalone test harness intentionally stubs WordPress functions and writes CLI output directly.

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['extrachill_cache_test_hooks']       = array();
$GLOBALS['extrachill_cache_test_post_types']  = array();
$GLOBALS['extrachill_cache_test_removed']     = array();
$GLOBALS['extrachill_cache_test_actions']     = array();
$GLOBALS['extrachill_cache_test_blog_id']     = 1;
$GLOBALS['extrachill_cache_test_can_edit']    = true;
$GLOBALS['extrachill_cache_test_doing_cron']  = false;
$GLOBALS['extrachill_cache_test_post_objects'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['extrachill_cache_test_hooks'][ $hook ] = array( $callback, $priority, $accepted_args );
}

function get_post_status_object( $status ) {
	$public_statuses = array( 'publish', 'public-custom' );

	if ( in_array( $status, $public_statuses, true ) ) {
		return (object) array( 'public' => true );
	}

	$private_statuses = array( 'auto-draft', 'draft', 'pending', 'private', 'future', 'trash', 'inherit' );
	if ( in_array( $status, $private_statuses, true ) ) {
		return (object) array( 'public' => false );
	}

	return null;
}

function get_post_type( $post_id ) {
	return $GLOBALS['extrachill_cache_test_post_types'][ $post_id ] ?? 'post';
}

function get_post( $post_id ) {
	return $GLOBALS['extrachill_cache_test_post_objects'][ $post_id ] ?? null;
}

function current_user_can( $capability, $post_id ) {
	return $GLOBALS['extrachill_cache_test_can_edit'];
}

function wp_doing_cron() {
	return $GLOBALS['extrachill_cache_test_doing_cron'];
}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_current_blog_id() {
	return $GLOBALS['extrachill_cache_test_blog_id'];
}

function extrachill_cache_blog_dir( $blog_id ) {
	return '/cache/blog-' . $blog_id;
}

function extrachill_cache_rrmdir( $dir ) {
	$GLOBALS['extrachill_cache_test_removed'][] = $dir;
}

function do_action( $hook, ...$args ) {
	$GLOBALS['extrachill_cache_test_actions'][] = array( $hook, $args );
}

function extrachill_cache_delete_url( $url, $blog_id ) {
}

function extrachill_cache_base_dir() {
	return '/cache';
}

require dirname( __DIR__ ) . '/inc/purge.php';

function extrachill_cache_test_reset( $blog_id = 1 ) {
	$GLOBALS['extrachill_cache_test_removed']    = array();
	$GLOBALS['extrachill_cache_test_actions']    = array();
	$GLOBALS['extrachill_cache_test_blog_id']    = $blog_id;
	$GLOBALS['extrachill_cache_test_can_edit']   = true;
	$GLOBALS['extrachill_cache_test_doing_cron'] = false;
}

function extrachill_cache_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function extrachill_cache_test_post( $id, $status = 'draft', $type = 'post' ) {
	$GLOBALS['extrachill_cache_test_post_types'][ $id ] = $type;

	return (object) array(
		'ID'          => $id,
		'post_status' => $status,
		'post_type'   => $type,
	);
}

function extrachill_cache_test_transition( $old_status, $new_status, $post_id = 1 ) {
	extrachill_cache_purge_on_post_transition( $new_status, $old_status, extrachill_cache_test_post( $post_id, $new_status ) );
}

function extrachill_cache_test_assert_purges( $expected, $message, $blog_id = 1 ) {
	$dirs = $expected ? array( '/cache/blog-' . $blog_id ) : array();
	extrachill_cache_test_assert_same( $dirs, $GLOBALS['extrachill_cache_test_removed'], $message );
}

extrachill_cache_test_assert_same(
	array( 'extrachill_cache_purge_on_post_transition', 10, 3 ),
	$GLOBALS['extrachill_cache_test_hooks']['transition_post_status'],
	'transition hook receives both statuses and the post object'
);
extrachill_cache_test_assert_same(
	array( 'extrachill_cache_purge_on_post_delete', 10, 2 ),
	$GLOBALS['extrachill_cache_test_hooks']['before_delete_post'],
	'deletion hook receives the pre-delete post object'
);

$private_transitions = array(
	'initial draft creation' => array( 'new', 'draft' ),
	'draft update'           => array( 'draft', 'draft' ),
	'draft to pending'       => array( 'draft', 'pending' ),
	'pending update'         => array( 'pending', 'pending' ),
	'pending to draft'       => array( 'pending', 'draft' ),
	'private to trash'       => array( 'private', 'trash' ),
);

foreach ( $private_transitions as $message => $statuses ) {
	extrachill_cache_test_reset();
	extrachill_cache_test_transition( $statuses[0], $statuses[1] );
	extrachill_cache_test_assert_purges( false, $message . ' does not purge' );
}

$public_transitions = array(
	'initial publish creation' => array( 'new', 'publish' ),
	'pending to publish'     => array( 'pending', 'publish' ),
	'published update'       => array( 'publish', 'publish' ),
	'publish to draft'       => array( 'publish', 'draft' ),
	'publish to trash'       => array( 'publish', 'trash' ),
	'custom public status'   => array( 'draft', 'public-custom' ),
);

foreach ( $public_transitions as $message => $statuses ) {
	extrachill_cache_test_reset();
	extrachill_cache_test_transition( $statuses[0], $statuses[1] );
	extrachill_cache_test_assert_purges( true, $message . ' purges' );
}

extrachill_cache_test_reset();
$GLOBALS['extrachill_cache_test_post_types'][2] = 'revision';
extrachill_cache_purge_on_post_transition( 'publish', 'draft', extrachill_cache_test_post( 2, 'publish', 'revision' ) );
extrachill_cache_test_assert_purges( false, 'revision transition does not purge' );

extrachill_cache_test_reset();
extrachill_cache_purge_on_post_delete( 3, extrachill_cache_test_post( 3, 'draft' ) );
extrachill_cache_test_assert_purges( false, 'non-public deletion does not purge' );

extrachill_cache_test_reset();
extrachill_cache_purge_on_post_delete( 4, extrachill_cache_test_post( 4, 'publish' ) );
extrachill_cache_test_assert_purges( true, 'published deletion purges' );

extrachill_cache_test_reset();
$GLOBALS['extrachill_cache_test_post_objects'][5] = extrachill_cache_test_post( 5, 'publish' );
extrachill_cache_purge_on_post_delete( 5 );
extrachill_cache_test_assert_purges( true, 'published deletion resolves the post on older WordPress versions' );

extrachill_cache_test_reset( 7 );
extrachill_cache_test_transition( 'pending', 'publish', 6 );
extrachill_cache_test_assert_purges( true, 'public transition purges only the current blog', 7 );
extrachill_cache_test_assert_same(
	array( array( 'extrachill_cache_purged', array( 7 ) ) ),
	$GLOBALS['extrachill_cache_test_actions'],
	'purge action identifies only the current blog'
);

extrachill_cache_test_reset();
define( 'DOING_AUTOSAVE', true );
extrachill_cache_test_transition( 'publish', 'publish', 7 );
extrachill_cache_test_assert_purges( false, 'native autosave does not purge' );

fwrite( STDOUT, "All purge tests passed.\n" );
