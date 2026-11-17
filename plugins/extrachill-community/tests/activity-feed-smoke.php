<?php
/**
 * The one activity feed builder and its source registry (#1653).
 *
 * Covers chronological merging across sources, honest pagination across
 * sources, author scope, type narrowing, the depth cap, the forum source, and
 * rendering comment items through the existing blog comment card on /recent.
 *
 * Run: php tests/activity-feed-smoke.php
 *
 * @package ExtraChillCommunity
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Post {
	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}

class WP_Query {
	public $posts;
	public $found_posts;

	public function __construct( $args ) {
		$GLOBALS['feed_wp_query_args'][] = $args;
		$rows                            = array_values(
			array_filter(
				$GLOBALS['feed_posts'],
				static function ( $post ) use ( $args ) {
					return in_array( $post->post_type, (array) $args['post_type'], true )
						&& ( empty( $args['author'] ) || (int) $args['author'] === $post->post_author )
						&& ! in_array( $post->post_author, $args['author__not_in'] ?? array(), true );
				}
			)
		);
		$this->found_posts               = count( $rows );
		$this->posts                     = array_slice( $rows, 0, $args['posts_per_page'] );
	}
}

$GLOBALS['feed_filters']       = array();
$GLOBALS['feed_wp_query_args'] = array();
$GLOBALS['feed_posts']         = array();
$GLOBALS['feed_blocked']       = array();
$GLOBALS['feed_query_vars']    = array();
$GLOBALS['feed_template_log']  = array();

function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['feed_filters'][ $hook ][ $priority ][] = $callback;
}
function apply_filters( $hook, $value ) {
	$callbacks = $GLOBALS['feed_filters'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $callback ) {
			$value = $callback( $value );
		}
	}
	return $value;
}
function __( $text ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function bbp_get_topic_post_type() {
	return 'topic';
}
function bbp_get_reply_post_type() {
	return 'reply';
}
function bbp_get_public_status_id() {
	return 'publish';
}
function bbp_get_closed_status_id() {
	return 'closed';
}
function bbp_get_reply_topic_id( $id ) {
	return 100;
}
function bbp_get_topic_forum_id( $id ) {
	return 10;
}
function bbp_get_reply_forum_id( $id ) {
	return 10;
}
function bbp_get_paged() {
	return 1;
}
function wp_get_post_parent_id( $id ) {
	return 100;
}
function get_post( $post ) {
	return $post;
}
function get_permalink( $id ) {
	return 'https://community.example.test/p/' . (int) $id;
}
function get_the_title( $id ) {
	return 'Title ' . (int) $id;
}
function get_the_author_meta( $field, $id ) {
	return 'User ' . (int) $id;
}
function get_avatar( $id ) {
	return '<img src="https://example.test/avatar-' . (int) $id . '.png">';
}
function extrachill_community_viewer_member_blocked_ids() {
	return $GLOBALS['feed_blocked'];
}
function set_query_var( $key, $value ) {
	$GLOBALS['feed_query_vars'][ $key ] = $value;
}
function setup_postdata() {}
function wp_reset_postdata() {}
function get_post_field( $field, $id ) {
	return 100;
}
function bbpress() {
	static $bbp;
	$bbp = $bbp ?? new stdClass();
	return $bbp;
}
function bbp_get_template_part( $slug, $name ) {
	$GLOBALS['feed_template_log'][] = array( $name, $GLOBALS['feed_query_vars']['comment_data'] ?? null );
}
function extrachill_pagination() {}

require dirname( __DIR__ ) . '/inc/content/recent-feed.php';

$failures = 0;
function check( $label, $condition ) {
	global $failures;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $condition ) {
		++$failures;
	}
}

// Forum posts at even hours, comments at odd hours: they interleave.
$base = strtotime( '2026-08-01 00:00:00 UTC' );
foreach ( array( 6, 4, 2 ) as $hour ) {
	$GLOBALS['feed_posts'][] = new WP_Post(
		array(
			'ID'            => 200 + $hour,
			'post_type'     => 4 === $hour ? 'topic' : 'reply',
			'post_author'   => 2 === $hour ? 8 : 7,
			'post_date'     => gmdate( 'Y-m-d H:i:s', $base + $hour * 3600 ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $base + $hour * 3600 ),
		)
	);
}
$GLOBALS['feed_comment_queries'] = array();
$GLOBALS['feed_comments']        = array();
foreach ( array( 5, 3, 1 ) as $hour ) {
	$GLOBALS['feed_comments'][] = array(
		'comment_id'  => 500 + $hour,
		'post_title'  => 'Article ' . $hour,
		'post_url'    => 'https://example.test/article-' . $hour . '/',
		'comment_url' => 'https://example.test/article-' . $hour . '/#comment-' . ( 500 + $hour ),
		'user_id'     => 1 === $hour ? 7 : 9,
		'author_name' => 'Commenter',
		'date_gmt'    => gmdate( 'Y-m-d H:i:s', $base + $hour * 3600 ),
		'content'     => 'Nice one',
		'artists'     => 5 === $hour ? array( 'band' ) : array(),
	);
}

// Stands in for extrachill-users' comment source (covered by its unit test).
add_filter(
	'ec_activity_feed_sources',
	static function ( $sources ) {
		$sources['comments'] = array(
			'types' => array( 'comment' ),
			'query' => static function ( $query ) {
				$GLOBALS['feed_comment_queries'][] = $query;
				$rows                              = array_values(
					array_filter(
						$GLOBALS['feed_comments'],
						static function ( $row ) use ( $query ) {
							return ( 0 === $query['author'] || $query['author'] === $row['user_id'] )
								&& ! in_array( $row['user_id'], $query['exclude_authors'], true )
								&& ( '' === $query['artist'] || in_array( $query['artist'], $row['artists'], true ) );
						}
					)
				);
				$items                             = array();
				foreach ( array_slice( $rows, 0, $query['limit'] ) as $row ) {
					$items[] = array(
						'type'      => 'comment',
						'timestamp' => strtotime( $row['date_gmt'] . ' UTC' ),
						'author_id' => $row['user_id'],
						'comment'   => $row,
					);
				}
				return array(
					'items' => $items,
					'total' => count( $rows ),
				);
			},
		);
		$sources['broken']   = array( 'types' => array( 'comment' ) );
		return $sources;
	}
);

$sources = extrachill_get_activity_feed_sources();
check( 'forum source registers first, comments second, invalid sources dropped', array( 'forum', 'comments' ) === array_keys( $sources ) );

$order = static function ( $feed ) {
	return array_map(
		static function ( $item ) {
			return 'comment' === $item['type'] ? (int) $item['comment']['comment_id'] : (int) $item['post']->ID;
		},
		$feed ? $feed['items'] : array()
	);
};

$all = extrachill_build_activity_feed( 10, 1 );
check( 'sources merge chronologically, newest first', array( 206, 505, 204, 503, 202, 501 ) === $order( $all ) );
check( 'total is the sum of source totals', 6 === $all['pagination']->found_posts && 1 === $all['pagination']->max_num_pages );
check( 'items carry their source id', 'forum' === $all['items'][0]['source'] && 'comments' === $all['items'][1]['source'] );
check( 'forum items are hydrated for the reply card', 'Title 100' === $all['items'][0]['topic_title'] && 'User 7' === $all['items'][0]['author_name'] );

// Honest pagination: page N asks each source for per_page * N items, then slices.
$pages = array();
foreach ( array( 1, 2, 3 ) as $paged ) {
	$feed    = extrachill_build_activity_feed( 2, $paged );
	$pages[] = $order( $feed );
	check( "page {$paged} asks each source for per_page * paged items", 2 * $paged === end( $GLOBALS['feed_comment_queries'] )['limit'] && 2 * $paged === end( $GLOBALS['feed_wp_query_args'] )['posts_per_page'] );
	check( "page {$paged} reports 3 pages of 6 items", 3 === $feed['pagination']->max_num_pages && 6 === $feed['pagination']->found_posts && $paged === $feed['pagination']->get( 'paged' ) );
}
check( 'pages tile the merged feed without gaps or repeats', array( array( 206, 505 ), array( 204, 503 ), array( 202, 501 ) ) === $pages );
check( 'a page past the end is empty', false === extrachill_build_activity_feed( 2, 4 ) );

// Author scope (profile use).
$mine = extrachill_build_activity_feed( 10, 1, 7 );
check( 'author scope reaches every source', array( 206, 204, 501 ) === $order( $mine ) && 7 === end( $GLOBALS['feed_wp_query_args'] )['author'] && 7 === end( $GLOBALS['feed_comment_queries'] )['author'] );
check( 'author-scoped total counts only that author', 3 === $mine['pagination']->found_posts );

// Viewer blocks reach every source.
$GLOBALS['feed_blocked'] = array( 9 );
check( 'blocked authors are excluded from every source', array( 206, 204, 202, 501 ) === $order( extrachill_build_activity_feed( 10, 1 ) ) && array( 9 ) === end( $GLOBALS['feed_wp_query_args'] )['author__not_in'] );
$GLOBALS['feed_blocked'] = array();

// Type narrowing: forum-only feeds never query the comment source.
$queries = count( $GLOBALS['feed_comment_queries'] );
check( 'forum types return forum items only', array( 206, 204, 202 ) === $order( extrachill_build_activity_feed( 10, 1, 0, array( 'types' => array( 'topic', 'reply' ) ) ) ) );
check( 'unrequested sources are not queried', count( $GLOBALS['feed_comment_queries'] ) === $queries );
check( 'reply-only narrows the forum post types', array( 206, 202 ) === $order( extrachill_build_activity_feed( 10, 1, 0, array( 'types' => array( 'reply' ) ) ) ) && 'reply' === end( $GLOBALS['feed_wp_query_args'] )['post_type'] );
$artist_feed = extrachill_build_activity_feed( 10, 1, 0, array( 'artist' => 'band' ) );
check( 'artist scope keeps forum topics, not replies', 'topic' === end( $GLOBALS['feed_wp_query_args'] )['post_type'] && 'band' === end( $GLOBALS['feed_wp_query_args'] )['tax_query'][0]['terms'] );
check( 'artist scope reaches the comment source', 'band' === end( $GLOBALS['feed_comment_queries'] )['artist'] && array( 'comment' ) === end( $GLOBALS['feed_comment_queries'] )['types'] );
check( 'artist scope merges tagged topics with comments on tagged posts', array( 505, 204 ) === $order( $artist_feed ) && 2 === $artist_feed['pagination']->found_posts );
check( 'unscoped feeds pass an empty artist to every source', '' === $GLOBALS['feed_comment_queries'][0]['artist'] );
check( 'viewer audience keeps the /recent statuses', in_array( 'private', $GLOBALS['feed_wp_query_args'][0]['post_status'], true ) );
extrachill_build_activity_feed( 1, 1, 0, array( 'audience' => 'public' ) );
check( 'public audience reads public statuses only', array( 'publish', 'closed' ) === end( $GLOBALS['feed_wp_query_args'] )['post_status'] );

// Depth cap.
add_filter(
	'ec_activity_feed_max_depth',
	static function () {
		return 4;
	}
);
$capped = extrachill_build_activity_feed( 2, 5 );
check( 'deep pages ask sources for no more than the depth cap', false === $capped && 4 === end( $GLOBALS['feed_comment_queries'] )['limit'] );
check( 'the depth cap bounds the page count', 2 === extrachill_build_activity_feed( 2, 1 )['pagination']->max_num_pages );
$GLOBALS['feed_filters']['ec_activity_feed_max_depth'] = array();

// Rendering: comment items use the existing blog comment card.
ob_start();
extrachill_render_recent_feed( extrachill_build_activity_feed( 2, 1 ) );
$html = (string) ob_get_clean();
check( 'forum items render through the reply card, comments through the blog comment card', array( 'single-reply-card', 'single-blog-comment-card' ) === array_column( $GLOBALS['feed_template_log'], 0 ) );
$card = $GLOBALS['feed_template_log'][1][1];
check(
	'comment card data uses the partial keys',
	array( 'comment_ID', 'post_title', 'post_permalink', 'comment_permalink', 'comment_date_gmt', 'comment_content', 'author_id', 'author_name', 'author_avatar', 'upvote_count' ) === array_keys( $card )
);
check( 'comment card links the comment and its article', 505 === $card['comment_ID'] && 'https://example.test/article-5/#comment-505' === $card['comment_permalink'] && 'Article 5' === $card['post_title'] );
check( 'comment card data is cleared after the card', null === $GLOBALS['feed_query_vars']['comment_data'] );
check( 'items render inside the existing reply list wrapper', str_contains( $html, '<ul class="forums bbp-replies">' ) );

$partial = (string) file_get_contents( dirname( __DIR__ ) . '/bbpress/loop-single-blog-comment-card.php' );
check( 'the comment card keeps its existing classes', str_contains( $partial, 'bbp-reply-card blog-comment-card' ) );
check( 'guest commenters render without a profile link', str_contains( $partial, '$author_url  = $user_info ? bbp_get_user_profile_url( $author_id ) : \'\';' ) );
$assets = (string) file_get_contents( dirname( __DIR__ ) . '/inc/core/assets.php' );
check( 'the comment card styles load on /recent', str_contains( $assets, "is_page_template('page-templates/main-blog-comments-feed.php') || is_page('recent')" ) );

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s)\n";
	exit( 1 );
}

echo "\nAll activity feed checks passed.\n";
