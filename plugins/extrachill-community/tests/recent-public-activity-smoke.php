<?php
/**
 * Focused coverage for the bounded recent public activity owner contract.
 *
 * The ability is a projection of the one activity feed
 * (extrachill_build_activity_feed()); comment items come from the comment
 * source registered on `ec_activity_feed_sources`.
 */

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
		public $found_posts;

		public function __construct( $args ) {
			$GLOBALS['_recent_activity_query_args'] = $args;
			$this->posts                            = array_slice( $GLOBALS['_recent_activity_query_posts'], 0, $args['posts_per_page'] );
			$this->found_posts                      = count( $GLOBALS['_recent_activity_query_posts'] );
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
if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['_recent_activity_filters'] = array();
	function add_filter( $hook, $callback, $priority = 10 ) {
		$GLOBALS['_recent_activity_filters'][ $hook ][ $priority ][] = $callback;
	}
	function apply_filters( $hook, $value ) {
		$callbacks = $GLOBALS['_recent_activity_filters'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$value = $callback( $value );
			}
		}
		return $value;
	}
}
$GLOBALS['_recent_activity_viewer'] = 0;
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return $GLOBALS['_recent_activity_viewer']; }
	function get_user_meta( $user_id ) { return 5 === (int) $user_id ? array( 7, 8 ) : ''; }
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
	function bbp_get_reply_forum_id( $id ) { return 10; }
	function bbp_get_reply_topic_id( $id ) { return (int) ( $GLOBALS['_recent_activity_posts'][ $id ]->topic_id ?? 0 ); }
	function bbp_get_topic_forum_id( $id ) { return (int) ( $GLOBALS['_recent_activity_posts'][ $id ]->post_parent ?? 0 ); }
	function bbp_get_reply_url( $id ) { return 'https://community.example.test/reply/' . (int) $id; }
	function bbp_get_topic_permalink( $id ) { return 'https://community.example.test/topic/' . (int) $id; }
	function bbp_get_forum_permalink( $id ) { return 'https://community.example.test/forum/' . (int) $id; }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['_recent_activity_posts'][ $id ] ?? null; }
	function get_permalink( $id ) { return 'https://community.example.test/p/' . (int) $id; }
	function wp_get_post_parent_id( $id ) { return (int) ( $GLOBALS['_recent_activity_posts'][ $id ]->post_parent ?? 0 ); }
	function get_the_author_meta( $field, $id ) { return 'User ' . (int) $id; }
	function get_avatar() { return '<img src="https://example.test/avatar.png">'; }
	function get_the_title( $post ) { return is_object( $post ) ? $post->post_title : ( $GLOBALS['_recent_activity_posts'][ $post ]->post_title ?? '' ); }
	function get_userdata( $id ) { return $id && 999 !== $id ? (object) array(
		'ID'           => $id,
		'display_name' => 'User ' . $id,
	) : false; }
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
				'import_id'     => $id,
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

require dirname( __DIR__ ) . '/inc/core/ability-helpers.php';
require dirname( __DIR__ ) . '/inc/moderation/member-blocks.php';
require_once dirname( __DIR__ ) . '/inc/content/recent-feed.php';
require dirname( __DIR__ ) . '/inc/content/recent-public-activity.php';
if ( $standalone ) {
	extrachill_community_register_recent_public_activity_ability();
	$ability = $GLOBALS['_recent_activity_ability'];
	recent_activity_assert( true === $ability['meta']['show_in_rest'], 'Projection must use the public core ability runner.' );
	recent_activity_assert( true === $ability['meta']['annotations']['readonly'], 'Projection must remain read-only.' );
	recent_activity_assert( 20 === $ability['input_schema']['properties']['limit']['maximum'], 'Input must declare the strict limit.' );
	recent_activity_assert( 20 === $ability['output_schema']['properties']['items']['maxItems'], 'Output must declare the strict limit.' );
	recent_activity_assert( false === $ability['output_schema']['additionalProperties'], 'Top-level output must be exact.' );
	$item_schemas = $ability['output_schema']['properties']['items']['items']['oneOf'];
	recent_activity_assert( 2 === count( $item_schemas ), 'Items are exactly one of a forum item or a comment item.' );
	$actor_schema = $item_schemas[0]['properties']['actor'];
	recent_activity_assert( 'integer' === $actor_schema['properties']['user_id']['type'] && in_array( 'user_id', $actor_schema['required'], true ), 'Actor must require an integer user ID.' );
	recent_activity_assert( array( 'discussion', 'reply' ) === $item_schemas[0]['properties']['activity_type']['enum'], 'Forum items keep their activity types.' );
	recent_activity_assert( array( 'forum', 'artists' ) === $item_schemas[0]['properties']['relationships']['required'], 'Forum items keep their required relationships.' );
	recent_activity_assert( array( 'comment' ) === $item_schemas[1]['properties']['activity_type']['enum'], 'Comment items use a distinct activity type.' );
	recent_activity_assert( false === $item_schemas[1]['additionalProperties'], 'Comment items must be exact.' );
	recent_activity_assert( ! isset( $ability['input_schema']['properties']['include_comments'] ), 'The include_comments flag is gone; item types are the one narrowing input.' );
	$types_schema = $ability['input_schema']['properties']['activity_types'];
	recent_activity_assert( array( 'discussion', 'reply', 'comment' ) === $types_schema['items']['enum'], 'activity_types lists every portable item type.' );
} else {
	register_post_type( 'forum', array( 'public' => true ) );
	register_post_type( 'topic', array( 'public' => true ) );
	register_post_type( 'reply', array( 'public' => true ) );
	register_post_status( 'closed', array( 'public' => true ) );
	register_taxonomy( 'artist', 'topic', array( 'public' => true ) );
}

$forum            = recent_activity_post( 10, 'forum', 'publish', 0 );
$private_forum    = recent_activity_post( 11, 'forum', 'private', 0 );
$nested_forum     = recent_activity_post( 12, 'forum', 'publish', $private_forum->ID );
$public           = recent_activity_post( 20, 'topic', 'publish', $forum->ID );
$closed           = recent_activity_post( 21, 'topic', 'closed', $forum->ID );
$reply            = recent_activity_post( 30, 'reply', 'publish', $forum->ID );
$reply->topic_id  = $public->ID;
$private          = recent_activity_post( 22, 'topic', 'private', $forum->ID );
$draft            = recent_activity_post( 23, 'topic', 'draft', $forum->ID );
$trash            = recent_activity_post( 24, 'topic', 'trash', $forum->ID );
$nested           = recent_activity_post( 25, 'topic', 'publish', $nested_forum->ID );
$orphan           = recent_activity_post( 31, 'reply', 'publish', $forum->ID );
$orphan->topic_id = 999;

$GLOBALS['_recent_activity_posts']                = array(
	$forum->ID         => $forum,
	$private_forum->ID => $private_forum,
	$nested_forum->ID  => $nested_forum,
	$public->ID        => $public,
	$closed->ID        => $closed,
	$private->ID       => $private,
	$draft->ID         => $draft,
	$trash->ID         => $trash,
	$nested->ID        => $nested,
	$reply->ID         => $reply,
	$orphan->ID        => $orphan,
);
$GLOBALS['_recent_activity_terms'][ $public->ID ] = array(
	(object) array(
		'name' => 'Test Artist',
		'slug' => 'test-artist',
	),
);
if ( ! $standalone ) {
	wp_set_object_terms( $public->ID, 'Test Artist', 'artist' );
}
$GLOBALS['_recent_activity_query_posts'] = array( $public, $closed, $reply, $private, $draft, $trash, $nested, $orphan );

$result = extrachill_community_ability_recent_public_activity( array( 'limit' => 999 ) );
recent_activity_assert( 3 === count( $result['items'] ), 'Only public, closed-public, and publicly parented reply activity may escape.' );
recent_activity_assert( false === in_array( bbp_get_topic_permalink( $nested->ID ), array_column( $result['items'], 'canonical_url' ), true ), 'Public child forums beneath private ancestors must not escape.' );
recent_activity_assert( 2 === count( array_keys( array_column( $result['items'], 'activity_type' ), 'discussion', true ) ), 'Closed discussions remain public without exposing their storage status.' );
recent_activity_assert( 1 === count( array_keys( array_column( $result['items'], 'activity_type' ), 'reply', true ) ), 'Public replies remain distinguishable without exposing post types.' );
if ( $standalone ) {
	recent_activity_assert( 20 === $GLOBALS['_recent_activity_query_args']['posts_per_page'], 'Runtime must clamp callers to the strict maximum.' );
}
recent_activity_assert( array( 'schema_version', 'items' ) === array_keys( $result ), 'Projection must expose only version and items at the top level.' );
recent_activity_assert( false === strpos( serialize( $result ), 'post_status' ) && false === strpos( serialize( $result ), 'topic_id' ), 'Projection must not leak storage fields.' );
foreach ( $result['items'] as $item ) {
	recent_activity_assert( 7 === $item['actor']['user_id'], 'Discussion and reply actors expose an integer member ID.' );
}
if ( $standalone ) {
	$guest   = recent_activity_post( 40, 'topic', 'publish', 10, 0 );
	$unknown = recent_activity_post( 41, 'topic', 'publish', 10, 999 );
	recent_activity_assert( 0 === extrachill_community_recent_activity_actor( $guest )['user_id'], 'Guest actors use zero.' );
	recent_activity_assert( 0 === extrachill_community_recent_activity_actor( $unknown )['user_id'], 'Unknown actors use zero.' );
}
$artist_rows = array_filter( $result['items'], static function ( $item ) { return ! empty( $item['relationships']['artists'] );
} );
recent_activity_assert( 'test-artist' === reset( $artist_rows )['relationships']['artists'][0]['slug'], 'Artist relationships must be portable owner data.' );

$GLOBALS['_recent_activity_query_posts'] = array( $private, $draft, $trash, $orphan );
$empty                                   = extrachill_community_ability_recent_public_activity( array( 'artist_slug' => 'no-matches' ) );
recent_activity_assert( array() === $empty['items'], 'An all-hidden query must return a versioned empty result.' );
if ( $standalone ) {
	recent_activity_assert( 'no-matches' === $GLOBALS['_recent_activity_query_args']['tax_query'][0]['terms'], 'Artist scoping must stay inside the owner query.' );
	recent_activity_assert( 'topic' === $GLOBALS['_recent_activity_query_args']['post_type'], 'Artist scoping must query relationship-owning discussions only.' );
}

if ( $standalone ) {
	recent_activity_assert( ! isset( $GLOBALS['_recent_activity_query_args']['author__not_in'] ), 'Anonymous activity is not member-block filtered.' );
	$GLOBALS['_recent_activity_viewer'] = 5;
	extrachill_community_ability_recent_public_activity( array() );
	recent_activity_assert( array( 7, 8 ) === $GLOBALS['_recent_activity_query_args']['author__not_in'], 'Activity hides authors the viewer has blocked.' );
	$GLOBALS['_recent_activity_viewer'] = 0;
}

// ─── Comments from the feed's comment source (#1653) ────────────────────────
if ( $standalone ) {
	$GLOBALS['_recent_activity_comments'] = array(
		array(
			'blog_id'     => 3,
			'comment_id'  => 501,
			'post_id'     => 90,
			'post_type'   => 'data_machine_events',
			'post_title'  => 'Amanda Russell at The Royal',
			'post_url'    => 'https://events.example.test/events/amanda-russell/',
			'comment_url' => 'https://events.example.test/events/amanda-russell/#comment-501',
			'user_id'     => 9,
			'author_name' => 'User 9',
			'date_gmt'    => '2026-08-02 12:00:00',
			'artists'     => array( 'test-artist' ),
		),
		array(
			'blog_id'     => 1,
			'comment_id'  => 77,
			'post_id'     => 15,
			'post_type'   => 'post',
			'post_title'  => 'A Review',
			'post_url'    => 'https://example.test/a-review/',
			'comment_url' => 'https://example.test/a-review/#comment-77',
			'user_id'     => 8,
			'author_name' => 'User 8',
			'date_gmt'    => '2026-07-30 12:00:00',
			'artists'     => array( 'test-artist' ),
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
			'artists'     => array(),
		),
	);
	$GLOBALS['_recent_activity_comment_queries'] = array();
	// Stands in for extrachill-users' comment source (covered by its unit test).
	add_filter(
		'ec_activity_feed_sources',
		static function ( $sources ) {
			$sources['comments'] = array(
				'types' => array( 'comment' ),
				'query' => static function ( $query ) {
					$GLOBALS['_recent_activity_comment_queries'][] = $query;
					$rows = array_values(
						array_filter(
							$GLOBALS['_recent_activity_comments'],
							static function ( $row ) use ( $query ) {
								return ! in_array( $row['user_id'], $query['exclude_authors'], true )
									&& ( '' === $query['artist'] || in_array( $query['artist'], $row['artists'], true ) );
							}
						)
					);
					$items = array();
					foreach ( array_slice( $rows, 0, $query['limit'] ) as $row ) {
						$items[] = array(
							'type'      => 'comment',
							'timestamp' => strtotime( $row['date_gmt'] . ' UTC' ),
							'comment'   => $row,
						);
					}
					return array(
						'items' => $items,
						'total' => count( $rows ),
					);
				},
			);
			return $sources;
		}
	);

	$GLOBALS['_recent_activity_query_posts'] = array( $public, $closed, $reply );

	$with = extrachill_community_ability_recent_public_activity( array( 'limit' => 20 ) );
	recent_activity_assert( 6 === count( $with['items'] ), 'The ability returns forum items and comments from the one feed.' );
	$times  = array_map( 'strtotime', array_column( $with['items'], 'timestamp' ) );
	$sorted = $times;
	rsort( $sorted );
	recent_activity_assert( $sorted === $times, 'Feed items are chronological, newest first.' );
	recent_activity_assert( 'comment' === $with['items'][0]['activity_type'], 'The newest comment leads the feed.' );
	recent_activity_assert( array( 'publish', 'closed' ) === $GLOBALS['_recent_activity_query_args']['post_status'], 'The ability reads the feed with the public audience.' );
	recent_activity_assert( array( 'topic', 'reply' ) === $GLOBALS['_recent_activity_query_args']['post_type'] && array( 'comment' ) === end( $GLOBALS['_recent_activity_comment_queries'] )['types'], 'Omitting activity_types reads every item type.' );

	$comment_item = $with['items'][0];
	recent_activity_assert( array( 'canonical_url', 'title', 'timestamp', 'activity_type', 'actor', 'relationships' ) === array_keys( $comment_item ), 'Comment items expose the portable item keys.' );
	recent_activity_assert( 'https://events.example.test/events/amanda-russell/#comment-501' === $comment_item['canonical_url'], 'Comment items link to the comment anchor.' );
	recent_activity_assert( 'Comment on Amanda Russell at The Royal' === $comment_item['title'], 'Comment items are labelled with their post or event.' );
	recent_activity_assert(
		array(
			'user_id'      => 9,
			'display_name' => 'User 9',
			'profile_url'  => 'https://community.example.test/members/9',
		) === $comment_item['actor'],
		'Comment actors carry the member identity.'
	);
	recent_activity_assert(
		array(
			'post' => array(
				'name'          => 'Amanda Russell at The Royal',
				'kind'          => 'event',
				'canonical_url' => 'https://events.example.test/events/amanda-russell/',
			),
		) === $comment_item['relationships'],
		'Comment relationships name the event.'
	);
	$by_url = array_column( $with['items'], null, 'canonical_url' );
	recent_activity_assert( 'article' === $by_url['https://example.test/a-review/#comment-77']['relationships']['post']['kind'], 'Article comments are labelled as articles.' );
	$guest_item = $by_url['https://example.test/a-review/#comment-76'];
	recent_activity_assert( 0 === $guest_item['actor']['user_id'] && null === $guest_item['actor']['profile_url'], 'Guest comments carry no member identity.' );
	recent_activity_assert( false === strpos( serialize( $with ), 'blog_id' ) && false === strpos( serialize( $with ), 'comment_id' ), 'Comment items do not leak storage fields.' );

	$limited = extrachill_community_ability_recent_public_activity( array( 'limit' => 2 ) );
	recent_activity_assert( 2 === count( $limited['items'] ), 'The feed honours the limit.' );
	recent_activity_assert( 'comment' === $limited['items'][0]['activity_type'] && 'discussion' === $limited['items'][1]['activity_type'], 'The limit applies after the chronological merge.' );
	recent_activity_assert( 2 === end( $GLOBALS['_recent_activity_comment_queries'] )['limit'], 'Sources are asked for no more than the page needs.' );

	// Item-type narrowing.
	$queries = count( $GLOBALS['_recent_activity_comment_queries'] );
	$forum   = extrachill_community_ability_recent_public_activity(
		array(
			'limit'          => 20,
			'activity_types' => array( 'discussion', 'reply' ),
		)
	);
	recent_activity_assert( 3 === count( $forum['items'] ) && ! in_array( 'comment', array_column( $forum['items'], 'activity_type' ), true ), 'Forum types return forum items only.' );
	recent_activity_assert( count( $GLOBALS['_recent_activity_comment_queries'] ) === $queries, 'Sources outside the requested types are not queried.' );
	$only = extrachill_community_ability_recent_public_activity( array( 'activity_types' => array( 'comment' ) ) );
	recent_activity_assert( array( 'comment' ) === array_values( array_unique( array_column( $only['items'], 'activity_type' ) ) ), 'The comment type returns comments only.' );
	recent_activity_assert( array() === extrachill_community_ability_recent_public_activity( array( 'activity_types' => array( 'bogus' ) ) )['items'], 'Unknown types match nothing.' );

	// The blog artist page: artist scope reads tagged discussions and comments
	// on tagged articles and events, with no activity_types narrowing.
	$GLOBALS['_recent_activity_query_posts'] = array( $public );
	$scoped                                  = extrachill_community_ability_recent_public_activity(
		array(
			'artist_slug' => 'test-artist',
			'limit'       => 4,
		)
	);
	recent_activity_assert( array( 'comment', 'discussion', 'comment' ) === array_column( $scoped['items'], 'activity_type' ), 'Artist scoping merges tagged discussions with comments on tagged posts and events.' );
	recent_activity_assert( 'test-artist' === end( $GLOBALS['_recent_activity_comment_queries'] )['artist'], 'Artist scoping reaches the comment source.' );
	recent_activity_assert( 'topic' === $GLOBALS['_recent_activity_query_args']['post_type'] && 'test-artist' === $GLOBALS['_recent_activity_query_args']['tax_query'][0]['terms'], 'Artist scoping queries tagged discussions, never replies.' );
	recent_activity_assert( ! in_array( 'reply', array_column( $scoped['items'], 'activity_type' ), true ), 'Artist scoping never returns replies.' );
	recent_activity_assert( ! in_array( 'https://example.test/a-review/#comment-76', array_column( $scoped['items'], 'canonical_url' ), true ), 'Comments on untagged posts are excluded from artist scope.' );
	foreach ( $scoped['items'] as $item ) {
		$keys = array_keys( $item['relationships'] );
		recent_activity_assert( ( 'comment' === $item['activity_type'] ? array( 'post' ) : array( 'forum', 'artists' ) ) === $keys, 'Artist items keep the relationships the artist page validates.' );
	}
	$queries = count( $GLOBALS['_recent_activity_comment_queries'] );
	recent_activity_assert(
		array() === extrachill_community_ability_recent_public_activity(
			array(
				'artist_slug'    => 'test-artist',
				'activity_types' => array( 'reply' ),
			)
		)['items'],
		'Artist-scoped replies match nothing.'
	);
	recent_activity_assert( count( $GLOBALS['_recent_activity_comment_queries'] ) === $queries, 'An artist-scoped reply read queries no comment source.' );
	$GLOBALS['_recent_activity_query_posts'] = array( $public, $closed, $reply );

	$GLOBALS['_recent_activity_viewer'] = 5; // Blocks members 7 and 8.
	$blocked                            = extrachill_community_ability_recent_public_activity( array( 'limit' => 20 ) );
	recent_activity_assert( array( 7, 8 ) === end( $GLOBALS['_recent_activity_comment_queries'] )['exclude_authors'], 'Every source receives the viewer blocks.' );
	recent_activity_assert( ! in_array( 8, array_column( array_column( $blocked['items'], 'actor' ), 'user_id' ), true ), 'Comments by members the viewer blocked are hidden.' );
	$GLOBALS['_recent_activity_viewer'] = 0;
}

echo "PASS: Recent public activity is bounded, exact, and visibility-safe.\n";
