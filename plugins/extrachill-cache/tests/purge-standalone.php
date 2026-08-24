<?php
/**
 * Standalone tests for post lifecycle cache invalidation.
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Standalone test harness intentionally stubs WordPress functions and writes CLI output directly.

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['extrachill_cache_test_hooks']        = array();
$GLOBALS['extrachill_cache_test_post_types']   = array();
$GLOBALS['extrachill_cache_test_removed']      = array();
$GLOBALS['extrachill_cache_test_deleted_urls'] = array();
$GLOBALS['extrachill_cache_test_actions']      = array();
$GLOBALS['extrachill_cache_test_blog_id']      = 1;
$GLOBALS['extrachill_cache_test_can_edit']     = true;
$GLOBALS['extrachill_cache_test_cap_checks']   = array();
$GLOBALS['extrachill_cache_test_doing_cron']   = false;
$GLOBALS['extrachill_cache_test_post_objects'] = array();
$GLOBALS['extrachill_cache_test_autosaves']    = array();
$GLOBALS['extrachill_cache_test_revisions']    = array();
$GLOBALS['extrachill_cache_test_filter_urls']  = null;

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
	if ( is_object( $post_id ) ) {
		return $post_id->post_type;
	}

	return $GLOBALS['extrachill_cache_test_post_types'][ $post_id ] ?? 'post';
}

function get_post( $post_id ) {
	return $GLOBALS['extrachill_cache_test_post_objects'][ $post_id ] ?? null;
}

function current_user_can( $capability, $post_id ) {
	$GLOBALS['extrachill_cache_test_cap_checks'][] = array( $capability, $post_id );

	return $GLOBALS['extrachill_cache_test_can_edit'];
}

function wp_doing_cron() {
	return $GLOBALS['extrachill_cache_test_doing_cron'];
}

function wp_is_post_autosave( $post_id ) {
	return in_array( $post_id, $GLOBALS['extrachill_cache_test_autosaves'], true ) ? $post_id : false;
}

function wp_is_post_revision( $post_id ) {
	return in_array( $post_id, $GLOBALS['extrachill_cache_test_revisions'], true ) ? $post_id : false;
}

function apply_filters( $hook, $value, ...$args ) {
	if ( 'extrachill_cache_post_change_urls' === $hook ) {
		return $GLOBALS['extrachill_cache_test_filter_urls'];
	}

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

	if ( isset( $GLOBALS['extrachill_cache_test_hooks'][ $hook ] ) ) {
		list( $callback, , $accepted_args ) = $GLOBALS['extrachill_cache_test_hooks'][ $hook ];
		call_user_func_array( $callback, array_slice( $args, 0, $accepted_args ) );
	}
}

function extrachill_cache_delete_url( $url, $blog_id ) {
	$GLOBALS['extrachill_cache_test_deleted_urls'][] = array( $url, $blog_id );
}

function extrachill_cache_base_dir() {
	return '/cache';
}

require dirname( __DIR__ ) . '/inc/purge.php';

function extrachill_cache_test_reset( $blog_id = 1 ) {
	$GLOBALS['extrachill_cache_test_removed']      = array();
	$GLOBALS['extrachill_cache_test_deleted_urls'] = array();
	$GLOBALS['extrachill_cache_test_actions']      = array();
	$GLOBALS['extrachill_cache_test_blog_id']      = $blog_id;
	$GLOBALS['extrachill_cache_test_can_edit']     = true;
	$GLOBALS['extrachill_cache_test_cap_checks']   = array();
	$GLOBALS['extrachill_cache_test_doing_cron']   = false;
	$GLOBALS['extrachill_cache_test_autosaves']    = array();
	$GLOBALS['extrachill_cache_test_revisions']    = array();
	$GLOBALS['extrachill_cache_test_filter_urls']  = null;
}

function extrachill_cache_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function extrachill_cache_test_post( $id, $status = 'draft', $type = 'post' ) {
	$GLOBALS['extrachill_cache_test_post_types'][ $id ] = $type;

	$post = (object) array(
		'ID'          => $id,
		'post_status' => $status,
		'post_type'   => $type,
	);
	$GLOBALS['extrachill_cache_test_post_objects'][ $id ] = $post;

	return $post;
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
extrachill_cache_test_assert_same(
	array( 'extrachill_cache_purge_on_post_change', 10, 1 ),
	$GLOBALS['extrachill_cache_test_hooks']['purge_post_cache'],
	'legacy programmatic action retains its callback contract'
);
extrachill_cache_test_assert_same(
	array( 'extrachill_cache_purge_post', 10, 1 ),
	$GLOBALS['extrachill_cache_test_hooks']['extrachill_cache_purge_post'],
	'internal domain-authorized action accepts one post ID'
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
$GLOBALS['extrachill_cache_test_can_edit'] = false;
extrachill_cache_test_post( 8, 'publish' );
do_action( 'purge_post_cache', 8 );
extrachill_cache_test_assert_purges( true, 'legacy no-user programmatic action still purges' );
extrachill_cache_test_assert_same( array(), $GLOBALS['extrachill_cache_test_cap_checks'], 'legacy action does not check capabilities' );

extrachill_cache_test_reset();
$GLOBALS['extrachill_cache_test_can_edit'] = false;
extrachill_cache_test_transition( 'publish', 'publish', 9 );
extrachill_cache_test_assert_purges( true, 'no-user public lifecycle update still purges' );

extrachill_cache_test_reset();
$GLOBALS['extrachill_cache_test_can_edit'] = false;
extrachill_cache_test_post( 9, 'publish' );
do_action( 'extrachill_cache_purge_post', 9 );
extrachill_cache_test_assert_purges( true, 'internal domain-authorized action does not require edit_post' );
extrachill_cache_test_assert_same( array(), $GLOBALS['extrachill_cache_test_cap_checks'], 'internal action does not check capabilities' );

extrachill_cache_test_reset();
$GLOBALS['extrachill_cache_test_can_edit']   = false;
$GLOBALS['extrachill_cache_test_doing_cron'] = true;
extrachill_cache_test_transition( 'publish', 'publish', 10 );
extrachill_cache_test_assert_purges( true, 'cron or worker change purges' );

extrachill_cache_test_reset();
$GLOBALS['extrachill_cache_test_post_objects'][2] = extrachill_cache_test_post( 2, 'inherit', 'revision' );
$GLOBALS['extrachill_cache_test_revisions'][] = 2;
extrachill_cache_purge_post( 2 );
extrachill_cache_test_assert_purges( false, 'revision does not purge' );

extrachill_cache_test_reset();
$GLOBALS['extrachill_cache_test_autosaves'][] = 11;
extrachill_cache_test_post( 11, 'inherit', 'revision' );
extrachill_cache_purge_post( 11 );
extrachill_cache_test_assert_purges( false, 'autosave does not purge' );

extrachill_cache_test_reset();
extrachill_cache_purge_post( 0 );
extrachill_cache_purge_post( -1 );
extrachill_cache_purge_post( 1.5 );
extrachill_cache_purge_post( true );
extrachill_cache_purge_post( '1 invalid' );
extrachill_cache_purge_post( 999 );
extrachill_cache_test_assert_purges( false, 'invalid post IDs do not purge' );

extrachill_cache_test_reset();
extrachill_cache_purge_on_post_delete( 3, extrachill_cache_test_post( 3, 'draft' ) );
extrachill_cache_test_assert_purges( false, 'non-public deletion does not purge' );

extrachill_cache_test_reset();
$GLOBALS['extrachill_cache_test_can_edit'] = false;
extrachill_cache_purge_on_post_delete( 4, extrachill_cache_test_post( 4, 'publish' ) );
extrachill_cache_test_assert_purges( true, 'no-user published deletion still purges' );

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

extrachill_cache_test_reset( 7 );
extrachill_cache_test_post( 12, 'publish', 'page' );
$GLOBALS['extrachill_cache_test_filter_urls'] = array(
	'https://example.com/one/',
	'https://example.com/one/',
	false,
	'https://example.com/two/',
);
extrachill_cache_purge_post( 12 );
extrachill_cache_test_assert_same(
	array(
		array( 'https://example.com/one/', 7 ),
		array( 'https://example.com/two/', 7 ),
	),
	$GLOBALS['extrachill_cache_test_deleted_urls'],
	'exact filtered URLs are uniquely deleted from the current blog partition'
);
extrachill_cache_test_assert_purges( false, 'exact URL invalidation does not trigger a full-blog purge' );

extrachill_cache_test_reset( 7 );
extrachill_cache_test_post( 13, 'publish', 'page' );
extrachill_cache_purge_post( 13 );
extrachill_cache_test_assert_purges( true, 'null URL filter falls back to the full current-blog partition', 7 );

extrachill_cache_test_reset();
define( 'DOING_AUTOSAVE', true );
extrachill_cache_test_post( 7, 'publish' );
extrachill_cache_purge_post( 7 );
extrachill_cache_test_assert_purges( false, 'native autosave does not purge' );

$purge_source = file_get_contents( dirname( __DIR__ ) . '/inc/purge.php' );
extrachill_cache_test_assert_same( false, strpos( $purge_source, 'current_user_can' ), 'purge source contains no capability gate' );
extrachill_cache_test_assert_same(
	0,
	preg_match( '/artist|venue|promoter|membership|link[ _-]?page/i', $purge_source ),
	'generic purge source contains no lower-domain names'
);

fwrite( STDOUT, "All purge tests passed.\n" );
