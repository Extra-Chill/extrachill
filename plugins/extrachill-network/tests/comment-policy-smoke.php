<?php
/**
 * Standalone tests for the network-wide comment policy.
 *
 * Runs posts through a comments_open() stand-in that mirrors core: the stored
 * comment_status, then the `comments_open` filter chain by priority.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- Standalone WordPress mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
}

$GLOBALS['cp_filters'] = array();
$GLOBALS['cp_blog']    = 1;
$GLOBALS['cp_logged']  = false;
$GLOBALS['cp_emails']  = array( 'chubes@extrachill.com' );
$GLOBALS['cp_posts']   = array(
	// Blog ID => post ID => post.
	1  => array(
		10 => (object) array( 'ID' => 10, 'post_type' => 'post', 'comment_status' => 'open', 'ping_status' => 'open' ),
		11 => (object) array( 'ID' => 11, 'post_type' => 'attachment', 'comment_status' => 'open', 'ping_status' => 'open' ),
		12 => (object) array( 'ID' => 12, 'post_type' => 'product', 'comment_status' => 'open', 'ping_status' => 'open' ),
		13 => (object) array( 'ID' => 13, 'post_type' => 'post', 'comment_status' => 'closed', 'ping_status' => 'closed' ),
	),
	2  => array(
		14439 => (object) array( 'ID' => 14439, 'post_type' => 'attachment', 'comment_status' => 'open', 'ping_status' => 'open' ),
	),
	4  => array(
		58 => (object) array( 'ID' => 58, 'post_type' => 'artist_profile', 'comment_status' => 'open', 'ping_status' => 'open' ),
	),
	7  => array(
		70 => (object) array( 'ID' => 70, 'post_type' => 'data_machine_events', 'comment_status' => 'open', 'ping_status' => 'open' ),
	),
	9  => array(
		90 => (object) array( 'ID' => 90, 'post_type' => 'newsletter', 'comment_status' => 'open', 'ping_status' => 'open' ),
	),
	10 => array(
		81 => (object) array( 'ID' => 81, 'post_type' => 'page', 'comment_status' => 'open', 'ping_status' => 'open' ),
	),
	14 => array(
		7 => (object) array( 'ID' => 7, 'post_type' => 'attachment', 'comment_status' => 'open', 'ping_status' => 'open' ),
		5 => (object) array( 'ID' => 5, 'post_type' => 'post', 'comment_status' => 'open', 'ping_status' => 'open' ),
	),
);

function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['cp_filters'][ $hook ][ $priority ][] = $cb;
}
function apply_filters( $hook, $value, ...$args ) {
	$chain = $GLOBALS['cp_filters'][ $hook ] ?? array();
	ksort( $chain );
	foreach ( $chain as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$value = $cb( $value, ...$args );
		}
	}
	return $value;
}
function get_current_blog_id() {
	return $GLOBALS['cp_blog'];
}
function get_site_option( $name, $fallback = false ) {
	return $fallback;
}
function get_post( $post ) {
	if ( is_object( $post ) ) {
		return $post;
	}
	return $GLOBALS['cp_posts'][ $GLOBALS['cp_blog'] ][ (int) $post ] ?? null;
}
function is_user_logged_in() {
	return $GLOBALS['cp_logged'];
}
function email_exists( $email ) {
	return in_array( strtolower( $email ), $GLOBALS['cp_emails'], true ) ? 1 : false;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function __( $text ) {
	return $text;
}

// Core's comments_open() / pings_open(), reduced to the filter contract.
function comments_open( $post_id ) {
	$post = get_post( $post_id );
	$open = $post && 'open' === $post->comment_status;
	return apply_filters( 'comments_open', $open, $post_id );
}
function pings_open( $post_id ) {
	$post = get_post( $post_id );
	$open = $post && 'open' === $post->ping_status;
	return apply_filters( 'pings_open', $open, $post_id );
}

require dirname( __DIR__ ) . '/inc/core/blog-ids.php';
require dirname( __DIR__ ) . '/inc/core/comment-policy.php';

$failures = 0;
function check( string $label, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
}

function on_blog( int $blog_id, callable $cb ) {
	$previous          = $GLOBALS['cp_blog'];
	$GLOBALS['cp_blog'] = $blog_id;
	try {
		return $cb();
	} finally {
		$GLOBALS['cp_blog'] = $previous;
	}
}

check( 'comments_open filter registered after events opt-in and core', isset( $GLOBALS['cp_filters']['comments_open'][20] ) );
check( 'pings_open filter registered', isset( $GLOBALS['cp_filters']['pings_open'][20] ) );

// Blog post on the main site stays open.
check( 'main blog post accepts comments', true === on_blog( 1, fn() => comments_open( 10 ) ) );
check( 'main blog post accepts pings', true === on_blog( 1, fn() => pings_open( 10 ) ) );
check( 'policy never opens a closed blog post', false === on_blog( 1, fn() => comments_open( 13 ) ) );

// Attachments close everywhere.
check( 'main site attachment is closed', false === on_blog( 1, fn() => comments_open( 11 ) ) );
check( 'main site attachment pings closed', false === on_blog( 1, fn() => pings_open( 11 ) ) );
check( 'community attachment is closed', false === on_blog( 2, fn() => comments_open( 14439 ) ) );
check( 'auth attachment is closed', false === on_blog( 14, fn() => comments_open( 7 ) ) );

// Sites and post types without comment UX close.
check( 'auth site post is closed', false === on_blog( 14, fn() => comments_open( 5 ) ) );
check( 'docs page is closed', false === on_blog( 10, fn() => comments_open( 81 ) ) );
check( 'main site product is closed', false === on_blog( 1, fn() => comments_open( 12 ) ) );
check( 'artist profile is closed', false === on_blog( 4, fn() => comments_open( 58 ) ) );
check( 'missing post is closed', false === on_blog( 1, fn() => comments_open( 999 ) ) );

// Owning features keep working.
check( 'event accepts comments', true === on_blog( 7, fn() => comments_open( 70 ) ) );
check( 'newsletter accepts comments', true === on_blog( 9, fn() => comments_open( 90 ) ) );

// Feature plugins opt in through the filter.
add_filter(
	'extrachill_network_commentable_post_types',
	function ( $types, $blog_id ) {
		if ( 10 === $blog_id ) {
			$types[] = 'page';
			$types[] = 'attachment';
		}
		return $types;
	},
	10,
	2
);
check( 'opt-in filter opens a post type', true === on_blog( 10, fn() => comments_open( 81 ) ) );
$GLOBALS['cp_posts'][10][7] = $GLOBALS['cp_posts'][14][7];
check( 'attachment opt-in is ignored', false === on_blog( 10, fn() => comments_open( 7 ) ) );

// Guest impersonation of a registered email.
$guest = array(
	'user_id'              => 0,
	'comment_type'         => 'comment',
	'comment_author_email' => 'Chubes@ExtraChill.com',
);
$result = extrachill_network_reject_guest_registered_email( 0, $guest );
check( 'guest with registered email is rejected', $result instanceof WP_Error && 'comment_registered_email' === $result->code && 403 === $result->data );
check( 'guest with unknown email passes through', 0 === extrachill_network_reject_guest_registered_email( 0, array_merge( $guest, array( 'comment_author_email' => 'stranger@example.com' ) ) ) );
check( 'guest with no email passes through', 0 === extrachill_network_reject_guest_registered_email( 0, array_merge( $guest, array( 'comment_author_email' => '' ) ) ) );
check( 'logged-in commenter passes through', 1 === extrachill_network_reject_guest_registered_email( 1, array_merge( $guest, array( 'user_id' => 3 ) ) ) );
check( 'pingbacks are not judged', 0 === extrachill_network_reject_guest_registered_email( 0, array_merge( $guest, array( 'comment_type' => 'pingback' ) ) ) );
$GLOBALS['cp_logged'] = true;
check( 'logged-in session passes through', 0 === extrachill_network_reject_guest_registered_email( 0, $guest ) );
$GLOBALS['cp_logged'] = false;
$earlier = new WP_Error( 'comment_flood' );
check( 'earlier error is kept', $earlier === extrachill_network_reject_guest_registered_email( $earlier, $guest ) );
check( 'rejection registered on pre_comment_approved', isset( $GLOBALS['cp_filters']['pre_comment_approved'][20] ) );

exit( $failures ? 1 : 0 );
