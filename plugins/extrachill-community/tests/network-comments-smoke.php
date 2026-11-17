<?php
/**
 * Coverage for issue #1634: community profiles list a member's approved
 * comments from every comment activity site (main + events), newest first,
 * each linking back to its post or event and the comment anchor.
 *
 * Run: php tests/network-comments-smoke.php
 *
 * @package ExtraChillCommunity
 */

define( 'ABSPATH', __DIR__ );

class WP_Comment {
	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}

$GLOBALS['_blog_stack']   = array( 3 );
$GLOBALS['_query_args']   = array();
$GLOBALS['_rendered']     = array();
$GLOBALS['_hosting_blogs'] = array( 1, 7 );

// Per-blog fixtures: comments returned by get_comments(), posts by get_post().
$GLOBALS['_comments'] = array(
	1 => array(
		new WP_Comment( array( 'comment_ID' => '11', 'comment_post_ID' => 100, 'user_id' => '42', 'comment_date_gmt' => '2026-03-10 15:00:00', 'comment_content' => 'Great review' ) ),
		new WP_Comment( array( 'comment_ID' => '12', 'comment_post_ID' => 999, 'user_id' => '42', 'comment_date_gmt' => '2026-03-14 15:00:00', 'comment_content' => 'Orphaned' ) ),
	),
	7 => array(
		new WP_Comment( array( 'comment_ID' => '71', 'comment_post_ID' => 700, 'user_id' => '42', 'comment_date_gmt' => '2026-03-12 20:00:00', 'comment_content' => 'See you there' ) ),
	),
);
$GLOBALS['_posts'] = array(
	1 => array( 100 => (object) array( 'ID' => 100, 'post_type' => 'post', 'post_title' => 'An Article' ) ),
	7 => array( 700 => (object) array( 'ID' => 700, 'post_type' => 'data_machine_events', 'post_title' => 'Amanda Russell Live' ) ),
);
$GLOBALS['_hosts'] = array(
	1 => 'https://extrachill.com',
	7 => 'https://events.extrachill.com',
);

function add_filter() {}
function ec_users_get_comment_activity_blog_ids() {
	return $GLOBALS['_hosting_blogs'];
}
function ec_get_blog_id( $key ) {
	return 'main' === $key ? 1 : 0;
}
function current_blog() {
	return end( $GLOBALS['_blog_stack'] );
}
function switch_to_blog( $id ) {
	$GLOBALS['_blog_stack'][] = (int) $id;
}
function restore_current_blog() {
	array_pop( $GLOBALS['_blog_stack'] );
}
function get_comments( $args ) {
	$GLOBALS['_query_args'][ current_blog() ] = $args;
	return $GLOBALS['_comments'][ current_blog() ] ?? array();
}
function get_post( $id ) {
	return $GLOBALS['_posts'][ current_blog() ][ $id ] ?? null;
}
function get_permalink( $id ) {
	return $GLOBALS['_hosts'][ current_blog() ] . '/p/' . $id . '/';
}
function get_the_author_meta( $field, $user_id ) {
	return 'User ' . $user_id;
}
function get_avatar_url( $user_id ) {
	return 'https://example.test/avatar/' . $user_id;
}
function get_userdata( $id ) {
	return (object) array( 'user_nicename' => 'user-' . $id );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function set_query_var( $key, $value ) {
	if ( 'comment_data' === $key ) {
		$GLOBALS['_rendered'][] = $value;
	}
}
function locate_template() {}

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

check( 'hosting sites come from extrachill-users', array( 1, 7 ) === extrachill_community_get_comment_activity_blog_ids() );

$comments = extrachill_community_get_user_network_comments( 42 );

check( 'queries every hosting site', array( 1, 7 ) === array_keys( $GLOBALS['_query_args'] ) );
check( 'queries only approved discussion comments by the user', 42 === $GLOBALS['_query_args'][7]['user_id'] && 'approve' === $GLOBALS['_query_args'][7]['status'] && 'comment' === $GLOBALS['_query_args'][7]['type'] );
check( 'restores the original blog', array( 3 ) === $GLOBALS['_blog_stack'] );
check( 'skips comments whose post is gone', 2 === count( $comments ) );
check( 'merges sites newest first', array( '71', '11' ) === array_column( $comments, 'comment_ID' ) );
check( 'event comment links back to its event', 'https://events.extrachill.com/p/700/' === $comments[0]['post_permalink'] && 'Amanda Russell Live' === $comments[0]['post_title'] );
check( 'event comment links to its comment anchor', 'https://events.extrachill.com/p/700/#comment-71' === $comments[0]['comment_permalink'] );
check( 'article comment links to its comment anchor', 'https://extrachill.com/p/100/#comment-11' === $comments[1]['comment_permalink'] );
check( 'items carry their blog and post type', 7 === $comments[0]['blog_id'] && 'data_machine_events' === $comments[0]['post_type'] && 1 === $comments[1]['blog_id'] );
check( 'invalid user returns nothing', array() === extrachill_community_get_user_network_comments( 0 ) );

$GLOBALS['_query_args'] = array();
extrachill_community_get_user_network_comments( 42, array( 7 ) );
check( 'explicit blog list narrows the query', array( 7 ) === array_keys( $GLOBALS['_query_args'] ) );

$html = display_main_site_comments_for_user( 42 );
check( 'legacy wrapper renders the network feed', 2 === count( $GLOBALS['_rendered'] ) && str_contains( $html, 'Comments Feed for' ) );
check( 'legacy wrapper renders event comments too', '71' === $GLOBALS['_rendered'][0]['comment_ID'] );

$GLOBALS['_hosting_blogs'] = array();
check( 'no hosting sites reports configuration', str_contains( extrachill_community_display_network_comments_for_user( 42 ), 'Comment sites not configured.' ) );

$card = (string) file_get_contents( __DIR__ . '/../bbpress/loop-single-blog-comment-card.php' );
check( 'card prefers the comment anchor link', str_contains( $card, "\$comment_data['comment_permalink']" ) );
check( 'card no longer says "View on Main Site"', ! str_contains( $card, 'View on Main Site' ) );

if ( $failures > 0 ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}

echo "\nAll network comments checks passed.\n";
