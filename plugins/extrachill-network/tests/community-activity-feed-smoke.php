<?php
/**
 * Community activity adapter: reads the one community activity feed (#1653).
 *
 * Covers that the sidebar widget and the blog homepage card consume the
 * `extrachill/community-recent-public-activity` ability (no local query or
 * merge) for every item type, item mapping for topics, replies, and article
 * and event comments, type narrowing, caching, the rendered "commented on"
 * line, and the blog homepage card rendering comment items.
 *
 * Run: php tests/community-activity-feed-smoke.php
 *
 * @package ExtraChill\Network
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['ec_activity_transients'] = array();
$GLOBALS['ec_activity_requests']   = array();
$GLOBALS['ec_activity_response']   = null;

function get_site_transient( $key ) {
	return $GLOBALS['ec_activity_transients'][ $key ]['value'] ?? false;
}
function set_site_transient( $key, $value, $ttl ) {
	$GLOBALS['ec_activity_transients'][ $key ] = compact( 'value', 'ttl' );
	return true;
}
function wp_json_encode( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stub for wp_json_encode().
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function absint( $value ) {
	return abs( (int) $value );
}
function ec_cross_site_rest_request( $site_key, $method, $path, $args ) {
	$GLOBALS['ec_activity_requests'][] = compact( 'site_key', 'method', 'path', 'args' );
	return $GLOBALS['ec_activity_response'];
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

require dirname( __DIR__ ) . '/inc/community-activity/community-activity.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
};

$forum = array(
	'name'          => 'General',
	'slug'          => 'general',
	'canonical_url' => 'https://community.example.test/forums/general/',
);

// The ability returns the feed already merged by date.
$GLOBALS['ec_activity_response'] = array(
	'schema_version' => '1',
	'items'          => array(
		array(
			'canonical_url' => 'https://community.example.test/topic/twenty/#post-30',
			'title'         => 'Topic Twenty',
			'timestamp'     => '2026-08-03T12:00:00+00:00',
			'activity_type' => 'reply',
			'actor'         => array(
				'user_id'      => 7,
				'display_name' => 'User 7',
				'profile_url'  => 'https://community.example.test/u/7',
			),
			'relationships' => array(
				'forum'   => $forum,
				'artists' => array(),
			),
		),
		array(
			'canonical_url' => 'https://events.example.test/e/amanda/#comment-501',
			'title'         => 'Comment on Amanda Russell at The Royal',
			'timestamp'     => '2026-08-02T12:00:00+00:00',
			'activity_type' => 'comment',
			'actor'         => array(
				'user_id'      => 0,
				'display_name' => 'Guest',
				'profile_url'  => null,
			),
			'relationships' => array(
				'post' => array(
					'name'          => 'Amanda Russell at The Royal',
					'kind'          => 'event',
					'canonical_url' => 'https://events.example.test/e/amanda/',
				),
			),
		),
		array(
			'canonical_url' => 'https://community.example.test/topic/twenty/',
			'title'         => 'Topic Twenty',
			'timestamp'     => '2026-08-01T12:00:00+00:00',
			'activity_type' => 'discussion',
			'actor'         => array(
				'user_id'      => 7,
				'display_name' => 'User 7',
				'profile_url'  => 'https://community.example.test/u/7',
			),
			'relationships' => array(
				'forum'   => $forum,
				'artists' => array(),
			),
		),
		array(
			'canonical_url' => 'https://example.test/a-review/#comment-77',
			'title'         => 'Comment on A Review',
			'timestamp'     => '2026-07-31T12:00:00+00:00',
			'activity_type' => 'comment',
			'actor'         => array(
				'user_id'      => 8,
				'display_name' => 'User 8',
				'profile_url'  => 'https://community.example.test/u/8',
			),
			'relationships' => array(
				'post' => array(
					'name'          => 'A Review',
					'kind'          => 'article',
					'canonical_url' => 'https://example.test/a-review/',
				),
			),
		),
		array( 'activity_type' => 'unknown' ),
	),
);

$items = extrachill_get_community_activity_items( 5 );
$assert( 1 === count( $GLOBALS['ec_activity_requests'] ), 'The adapter reads the feed with one request.' );
$request = $GLOBALS['ec_activity_requests'][0];
$assert( 'community' === $request['site_key'] && 'GET' === $request['method'], 'The feed is read from the community site with GET.' );
$assert( '/wp-abilities/v1/abilities/extrachill/community-recent-public-activity/run' === $request['path'], 'The adapter consumes the shared feed ability.' );
$assert( array( 'limit' => 5 ) === $request['args']['query']['input'], 'The widget asks for every item type.' );
$assert( 0 === $request['args']['user_id'], 'The cached read is anonymous.' );

$assert( array( 'Reply', 'Comment', 'Topic', 'Comment' ) === array_column( $items, 'type' ), 'Feed order is kept and unknown items are dropped.' );
$assert(
	array( 'type', 'username', 'user_profile_url', 'topic_title', 'forum_title', 'date_time', 'forum_url', 'topic_url', 'site_key' ) === array_keys( $items[1] ),
	'Every item exposes the renderer keys.'
);
$assert( array( 'community', 'events', 'community', 'main' ) === array_column( $items, 'site_key' ), 'Items name the site hosting their link: forum items community, event comments events, article comments main.' );
$assert( 'Amanda Russell at The Royal' === $items[1]['topic_title'], 'Comment items carry the event title.' );
$assert( 'https://events.example.test/e/amanda/#comment-501' === $items[1]['topic_url'], 'Comment items link to the comment anchor.' );
$assert( '' === $items[1]['user_profile_url'] && '' === $items[1]['forum_url'], 'Guest comments carry no profile or forum link.' );
$assert( 'General' === $items[0]['forum_title'], 'Forum items carry their forum.' );

$assert( extrachill_get_community_activity_items( 5 ) === $items, 'A second read is served from the cache.' );
$assert( 1 === count( $GLOBALS['ec_activity_requests'] ), 'The cache prevents a second request.' );
$cached = reset( $GLOBALS['ec_activity_transients'] );
$assert( 10 * MINUTE_IN_SECONDS === $cached['ttl'], 'A good read is cached for ten minutes.' );

// Type narrowing stays available to callers and is delegated to the feed.
extrachill_get_community_activity_items( 3, array( 'activity_types' => array( 'discussion', 'reply' ) ) );
$assert(
	array(
		'limit'          => 3,
		'activity_types' => array( 'discussion', 'reply' ),
	) === end( $GLOBALS['ec_activity_requests'] )['args']['query']['input'],
	'Type narrowing is delegated to the feed.'
);

// The blog homepage card reads every item type and renders comment items.
$card_path   = dirname( __DIR__, 2 ) . '/extrachill-blog/inc/home/templates/section-community.php';
$card_source = (string) file_get_contents( $card_path );
$assert( ! str_contains( $card_source, 'activity_types' ), 'The blog homepage card does not narrow item types.' );

function is_user_logged_in() {
	return false;
}
function esc_html_e( $text ) {
	echo htmlspecialchars( (string) $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub for esc_html_e().
}
function extrachill_blog_bridge_url( $url, $site_key ) {
	return $url . '|bridge=' . $site_key;
}
$GLOBALS['ec_activity_transients'] = array();
$GLOBALS['ec_activity_requests']   = array();
ob_start();
require $card_path;
$card_html = (string) ob_get_clean();
$assert( array( 'limit' => 3 ) === $GLOBALS['ec_activity_requests'][0]['args']['query']['input'], 'The homepage card asks the feed for every item type.' );
$assert( 4 === substr_count( $card_html, '<span class="home-community-activity-title">' ), 'The card renders every item the feed returns, comments included.' );
$assert( str_contains( $card_html, 'href="https://events.example.test/e/amanda/#comment-501|bridge=events"' ) && str_contains( $card_html, 'Amanda Russell at The Royal' ), 'Event comments link to the comment anchor, bridged to the events site.' );
$assert( str_contains( $card_html, 'Guest · Comment' ), 'Comment items show the commenter and the Comment type.' );
$assert( str_contains( $card_html, 'class="home-community-activity-item ec-cross-site-link" href="https://community.example.test/topic/twenty/#post-30|bridge=community"' ), 'Forum items keep the bridged community link.' );

// An article comment on the homepage links within the main site, unbridged.
$assert( str_contains( $card_html, '<a class="home-community-activity-item" href="https://example.test/a-review/#comment-77">' ), 'Article comments link within the main site with the existing item class.' );
$assert( str_contains( $card_html, 'User 8 · Comment' ), 'Article comments show the commenter.' );

// A failed read is cached briefly and renders the empty state.
$GLOBALS['ec_activity_transients'] = array();
$GLOBALS['ec_activity_response']   = null;
$assert( array() === extrachill_get_community_activity_items( 4 ), 'A failed read returns no items.' );
$failed = reset( $GLOBALS['ec_activity_transients'] );
$assert( MINUTE_IN_SECONDS === $failed['ttl'], 'A failed read is cached for one minute.' );

ob_start();
extrachill_render_community_activity(
	array(
		'items'          => $items,
		'render_wrapper' => false,
		'item_class'     => 'sidebar-activity-card',
	)
);
$html = (string) ob_get_clean();
$assert( 4 === substr_count( $html, 'class="community-activity-card sidebar-activity-card"' ), 'Every item renders with the existing activity card classes.' );
$assert( str_contains( $html, 'Guest commented on <a id="topic-2" href="https://events.example.test/e/amanda/#comment-501">Amanda Russell at The Royal</a>' ), 'Comments render a "commented on" line.' );
$assert( str_contains( $html, 'replied to <a id="topic-1"' ) && str_contains( $html, 'posted <a id="topic-3"' ), 'Forum items keep their lines.' );
$assert( str_contains( $html, '<a href="https://community.example.test/u/8">User 8</a> commented on <a id="topic-4" href="https://example.test/a-review/#comment-77">A Review</a>' ), 'Article comments render the same "commented on" line.' );

$widget = (string) file_get_contents( dirname( __DIR__ ) . '/inc/community-activity/sidebar-widget.php' );
$assert( str_contains( $widget, 'extrachill_get_community_activity_items( 5 )' ) && ! str_contains( $widget, 'include_comments' ), 'The sidebar widget reads the whole feed.' );

echo "Community activity feed adapter checks passed.\n";
