<?php
/** Focused coverage for the bounded recent public activity owner contract. */

$standalone = ! defined( 'ABSPATH' );
if ( $standalone ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
$GLOBALS['_recent_activity_standalone'] = $standalone;

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public function __construct( array $data ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts;

		public function __construct( $args ) {
			$GLOBALS['_recent_activity_query_args'] = $args;
			$this->posts                            = $GLOBALS['_recent_activity_query_posts'];
		}
	}
}

$GLOBALS['_recent_activity_ability']     = null;
$GLOBALS['_recent_activity_query_args']  = array();
$GLOBALS['_recent_activity_query_posts'] = array();
$GLOBALS['_recent_activity_posts']       = array();
$GLOBALS['_recent_activity_terms']       = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) { return $text; }
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true() { return true; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		if ( 'extrachill/community-recent-public-activity' === $name ) {
			$GLOBALS['_recent_activity_ability'] = $args;
		}
	}
}
if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
	function bbp_get_topic_post_type() { return 'topic'; }
	function bbp_get_reply_post_type() { return 'reply'; }
	function bbp_get_forum_post_type() { return 'forum'; }
	function bbp_get_public_status_id() { return 'publish'; }
	function bbp_get_closed_status_id() { return 'closed'; }
	function bbp_get_reply_topic_id( $id ) { return (int) ( $GLOBALS['_recent_activity_posts'][ $id ]->topic_id ?? 0 ); }
	function bbp_get_topic_forum_id( $id ) { return (int) ( $GLOBALS['_recent_activity_posts'][ $id ]->post_parent ?? 0 ); }
	function bbp_get_reply_url( $id ) { return 'https://community.example.test/reply/' . (int) $id; }
	function bbp_get_topic_permalink( $id ) { return 'https://community.example.test/topic/' . (int) $id; }
	function bbp_get_forum_permalink( $id ) { return 'https://community.example.test/forum/' . (int) $id; }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['_recent_activity_posts'][ $id ] ?? null; }
	function get_the_title( $post ) { return $post->post_title; }
	function get_userdata( $id ) { return $id ? (object) array( 'ID' => $id, 'display_name' => 'User ' . $id ) : false; }
	function get_post_meta() { return ''; }
	function get_the_terms( $id ) { return $GLOBALS['_recent_activity_terms'][ $id ] ?? array(); }
	function get_term_link( $term ) { return 'https://community.example.test/artist/' . $term->slug; }
	function is_wp_error() { return false; }
	function mysql2date( $format, $date ) { return gmdate( $format, strtotime( $date . ' UTC' ) ); }
}
if ( ! function_exists( 'extrachill_get_user_profile_url' ) ) {
	function extrachill_get_user_profile_url( $id ) { return 'https://community.example.test/members/' . (int) $id; }
}

function recent_activity_post( $id, $type, $status, $parent = 10, $author = 7 ) {
	if ( ! $GLOBALS['_recent_activity_standalone'] ) {
		$post_id = wp_insert_post(
			array(
				'import_id'    => $id,
				'post_type'    => $type,
				'post_status'  => $status,
				'post_parent'  => $parent,
				'post_author'  => $author,
				'post_title'   => ucfirst( $type ) . ' ' . $id,
				'post_name'    => $type . '-' . $id,
				'post_date'    => '2026-08-01 12:00:00',
				'post_date_gmt' => '2026-08-01 12:00:00',
			)
		);
		return get_post( $post_id );
	}

	return new WP_Post(
		array(
			'ID'            => $id,
			'post_type'     => $type,
			'post_status'   => $status,
			'post_parent'   => $parent,
			'post_author'   => $author,
			'post_title'    => ucfirst( $type ) . ' ' . $id,
			'post_name'     => $type . '-' . $id,
			'post_date'     => '2026-08-01 12:00:00',
			'post_date_gmt' => '2026-08-01 12:00:00',
		)
	);
}

function recent_activity_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/inc/content/recent-public-activity.php';
if ( $standalone ) {
	extrachill_community_register_recent_public_activity_ability();
	$ability = $GLOBALS['_recent_activity_ability'];
	recent_activity_assert( true === $ability['meta']['show_in_rest'], 'Projection must use the public core ability runner.' );
	recent_activity_assert( true === $ability['meta']['annotations']['readonly'], 'Projection must remain read-only.' );
	recent_activity_assert( 20 === $ability['input_schema']['properties']['limit']['maximum'], 'Input must declare the strict limit.' );
	recent_activity_assert( 20 === $ability['output_schema']['properties']['items']['maxItems'], 'Output must declare the strict limit.' );
	recent_activity_assert( false === $ability['output_schema']['additionalProperties'], 'Top-level output must be exact.' );
} else {
	register_post_type( 'forum', array( 'public' => true ) );
	register_post_type( 'topic', array( 'public' => true ) );
	register_post_type( 'reply', array( 'public' => true ) );
	register_post_status( 'closed', array( 'public' => true ) );
	register_taxonomy( 'artist', 'topic', array( 'public' => true ) );
}

$forum  = recent_activity_post( 10, 'forum', 'publish', 0 );
$public = recent_activity_post( 20, 'topic', 'publish', $forum->ID );
$closed = recent_activity_post( 21, 'topic', 'closed', $forum->ID );
$reply  = recent_activity_post( 30, 'reply', 'publish', $forum->ID );
$reply->topic_id = $public->ID;
$private = recent_activity_post( 22, 'topic', 'private', $forum->ID );
$draft   = recent_activity_post( 23, 'topic', 'draft', $forum->ID );
$trash   = recent_activity_post( 24, 'topic', 'trash', $forum->ID );
$orphan  = recent_activity_post( 31, 'reply', 'publish', $forum->ID );
$orphan->topic_id = 999;

$GLOBALS['_recent_activity_posts'] = array(
	$forum->ID   => $forum,
	$public->ID  => $public,
	$closed->ID  => $closed,
	$private->ID => $private,
	$draft->ID   => $draft,
	$trash->ID   => $trash,
	$reply->ID   => $reply,
	$orphan->ID  => $orphan,
);
$GLOBALS['_recent_activity_terms'][ $public->ID ] = array( (object) array( 'name' => 'Test Artist', 'slug' => 'test-artist' ) );
if ( ! $standalone ) {
	wp_set_object_terms( $public->ID, 'Test Artist', 'artist' );
}
$GLOBALS['_recent_activity_query_posts'] = array( $public, $closed, $reply, $private, $draft, $trash, $orphan );

$result = extrachill_community_ability_recent_public_activity( array( 'limit' => 999 ) );
recent_activity_assert( 3 === count( $result['items'] ), 'Only public, closed-public, and publicly parented reply activity may escape.' );
recent_activity_assert( 2 === count( array_keys( array_column( $result['items'], 'activity_type' ), 'discussion', true ) ), 'Closed discussions remain public without exposing their storage status.' );
recent_activity_assert( 1 === count( array_keys( array_column( $result['items'], 'activity_type' ), 'reply', true ) ), 'Public replies remain distinguishable without exposing post types.' );
if ( $standalone ) {
	recent_activity_assert( 20 === $GLOBALS['_recent_activity_query_args']['posts_per_page'], 'Runtime must clamp callers to the strict maximum.' );
}
recent_activity_assert( array( 'schema_version', 'items' ) === array_keys( $result ), 'Projection must expose only version and items at the top level.' );
recent_activity_assert( false === strpos( serialize( $result ), 'post_status' ) && false === strpos( serialize( $result ), 'topic_id' ), 'Projection must not leak storage fields.' );
$artist_rows = array_filter( $result['items'], static function ( $item ) { return ! empty( $item['relationships']['artists'] ); } );
recent_activity_assert( 'test-artist' === reset( $artist_rows )['relationships']['artists'][0]['slug'], 'Artist relationships must be portable owner data.' );

$GLOBALS['_recent_activity_query_posts'] = array( $private, $draft, $trash, $orphan );
$empty = extrachill_community_ability_recent_public_activity( array( 'artist_slug' => 'no-matches' ) );
recent_activity_assert( array() === $empty['items'], 'An all-hidden query must return a versioned empty result.' );
if ( $standalone ) {
	recent_activity_assert( 'no-matches' === $GLOBALS['_recent_activity_query_args']['tax_query'][0]['terms'], 'Artist scoping must stay inside the owner query.' );
	recent_activity_assert( 'topic' === $GLOBALS['_recent_activity_query_args']['post_type'], 'Artist scoping must query relationship-owning discussions only.' );
}

echo "PASS: Recent public activity is bounded, exact, and visibility-safe.\n";
