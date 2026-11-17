<?php
/**
 * Community activity: forum items merged with recent comments (#1634).
 *
 * Covers the comment item shape, chronological merge, the opt-in flag that
 * keeps existing callers (the blog homepage card) forum-only, and the
 * rendered "commented on" line using existing activity card classes.
 *
 * Run: php tests/community-activity-comments-smoke.php
 *
 * @package ExtraChill\Network
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['ec_activity_cache']    = array();
$GLOBALS['ec_activity_posts']    = array();
$GLOBALS['ec_activity_comments'] = array();
$GLOBALS['ec_activity_switches'] = 0;
$GLOBALS['ec_activity_post']     = null;

function wp_cache_get( $key ) {
	return $GLOBALS['ec_activity_cache'][ $key ] ?? false;
}
function wp_cache_set( $key, $value ) {
	$GLOBALS['ec_activity_cache'][ $key ] = $value;
	return true;
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function absint( $value ) {
	return abs( (int) $value );
}
function get_current_blog_id() {
	return 1;
}
function ec_get_blog_id( $key ) {
	return 'community' === $key ? 2 : 0;
}
function switch_to_blog() {
	++$GLOBALS['ec_activity_switches'];
}
function restore_current_blog() {}
function get_post_meta( $id, $key ) {
	return $GLOBALS['ec_activity_posts'][ $id ]['meta'][ $key ] ?? '';
}
function get_post_field( $field, $id ) {
	return $GLOBALS['ec_activity_posts'][ $id ][ $field ] ?? 0;
}
function get_the_title( $id ) {
	return $GLOBALS['ec_activity_posts'][ $id ]['title'] ?? '';
}
function get_permalink( $id ) {
	return 'https://community.example.test/p/' . (int) $id;
}
function get_the_ID() {
	return $GLOBALS['ec_activity_post']['ID'];
}
function get_post_type() {
	return $GLOBALS['ec_activity_post']['type'];
}
function get_the_author_meta() {
	return $GLOBALS['ec_activity_post']['author'];
}
function get_the_author() {
	return 'User ' . $GLOBALS['ec_activity_post']['author'];
}
function get_the_date() {
	return $GLOBALS['ec_activity_post']['date'];
}
function wp_reset_postdata() {}
function extrachill_get_user_profile_url( $id ) {
	return 'https://community.example.test/u/' . (int) $id;
}
function ec_users_get_recent_comment_activity( $limit = 10 ) {
	return array_slice( $GLOBALS['ec_activity_comments'], 0, $limit );
}
function esc_html__( $text ) {
	return $text;
}
function __( $text ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_url( $url ) {
	return (string) $url;
}
function wp_kses_post( $html ) {
	return $html;
}
function human_time_diff() {
	return '1 hour';
}

class WP_Query {
	private $rows;

	public function __construct() {
		$this->rows = array_values( $GLOBALS['ec_activity_query_rows'] );
	}

	public function have_posts() {
		return ! empty( $this->rows );
	}

	public function the_post() {
		$GLOBALS['ec_activity_post'] = array_shift( $this->rows );
	}
}

require dirname( __DIR__ ) . '/inc/community-activity/community-activity.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
};

// Forum fixtures: forum 10, topic 20 (Aug 1 12:00 UTC), reply 30 (Aug 3 12:00 UTC).
$GLOBALS['ec_activity_posts']      = array(
	10 => array( 'title' => 'General' ),
	20 => array(
		'title' => 'Topic Twenty',
		'meta'  => array( '_bbp_forum_id' => 10 ),
	),
	30 => array(
		'title' => 'Reply',
		'meta'  => array(
			'_bbp_forum_id' => 10,
			'_bbp_topic_id' => 20,
		),
	),
);
$GLOBALS['ec_activity_query_rows'] = array(
	array(
		'ID'     => 30,
		'type'   => 'reply',
		'author' => 7,
		'date'   => '2026-08-03T07:00:00-05:00',
	),
	array(
		'ID'     => 20,
		'type'   => 'topic',
		'author' => 7,
		'date'   => '2026-08-01T07:00:00-05:00',
	),
);
$GLOBALS['ec_activity_comments']   = array(
	array(
		'blog_id'     => 3,
		'comment_id'  => 501,
		'post_id'     => 90,
		'post_type'   => 'data_machine_events',
		'post_title'  => 'Amanda Russell at The Royal',
		'post_url'    => 'https://events.example.test/e/amanda/',
		'comment_url' => 'https://events.example.test/e/amanda/#comment-501',
		'user_id'     => 9,
		'author_name' => 'User 9',
		'date_gmt'    => '2026-08-02 12:00:00',
	),
	array(
		'blog_id'     => 1,
		'comment_id'  => 76,
		'post_id'     => 15,
		'post_type'   => 'post',
		'post_title'  => 'A Review',
		'post_url'    => 'https://example.test/a-review/',
		'comment_url' => 'https://example.test/a-review/#comment-76',
		'user_id'     => 0,
		'author_name' => 'Guest',
		'date_gmt'    => '2026-07-29 12:00:00',
	),
);

// Back-compat: the default call (blog homepage card) stays forum-only.
$default = extrachill_get_community_activity_items( 5 );
$assert( array( 'Reply', 'Topic' ) === array_column( $default, 'type' ), 'Default items stay forum-only, newest first.' );
$assert( 1 === $GLOBALS['ec_activity_switches'], 'Forum items are read once from the community site.' );

// Opt-in merge.
$merged = extrachill_get_community_activity_items( 5, array( 'include_comments' => true ) );
$assert( array( 'Reply', 'Comment', 'Topic', 'Comment' ) === array_column( $merged, 'type' ), 'Comments merge chronologically with topics and replies.' );
$assert( 1 === $GLOBALS['ec_activity_switches'], 'Forum items come from the cache on the second call.' );

$comment_item = $merged[1];
$assert(
	array( 'id', 'type', 'username', 'user_profile_url', 'topic_title', 'forum_title', 'date_time', 'forum_url', 'topic_url' ) === array_keys( $comment_item ),
	'Comment items use the same keys as forum items, so every consumer can read them.'
);
$assert( 'https://events.example.test/e/amanda/#comment-501' === $comment_item['topic_url'], 'Comment items link to the comment anchor.' );
$assert( 'Amanda Russell at The Royal' === $comment_item['topic_title'], 'Comment items carry the event title.' );
$assert( 'https://community.example.test/u/9' === $comment_item['user_profile_url'], 'Member commenters link to their profile.' );
$assert( '2026-08-02T12:00:00+00:00' === $comment_item['date_time'], 'Comment dates are ISO 8601 from the UTC column.' );
$assert( '' === $merged[3]['user_profile_url'] && 'Guest' === $merged[3]['username'], 'Guest commenters have no profile link.' );

$limited = extrachill_get_community_activity_items( 2, array( 'include_comments' => true ) );
$assert( array( 'Reply', 'Comment' ) === array_column( $limited, 'type' ), 'The limit applies after the merge.' );

// Rendering: comment line with existing classes only.
ob_start();
extrachill_render_community_activity(
	array(
		'render_wrapper' => false,
		'item_class'     => 'sidebar-activity-card',
		'items'          => $merged,
	)
);
$html = (string) ob_get_clean();
$assert( false !== strpos( $html, '<a href="https://community.example.test/u/9">User 9</a> commented on <a id="topic-2" href="https://events.example.test/e/amanda/#comment-501">Amanda Russell at The Royal</a> - 1 hour ago' ), 'Comments render as "<user> commented on <title>" linking to the anchor.' );
$assert( false !== strpos( $html, 'replied to' ) && false !== strpos( $html, 'posted' ), 'Forum lines still render.' );
$assert( 4 === substr_count( $html, 'class="community-activity-card sidebar-activity-card"' ), 'Every line uses the existing activity card classes.' );

// Without extrachill-users the opt-in degrades to forum-only (the function exists here, so simulate an empty reader).
$GLOBALS['ec_activity_comments'] = array();
$assert( array( 'Reply', 'Topic' ) === array_column( extrachill_get_community_activity_items( 5, array( 'include_comments' => true ) ), 'type' ), 'No comments means forum-only output.' );

echo "PASS: Community activity merges comments chronologically without changing default callers.\n";
