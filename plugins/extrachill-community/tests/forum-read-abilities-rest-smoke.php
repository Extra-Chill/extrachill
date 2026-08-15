<?php
/** Core REST and visibility contract coverage for canonical forum reads. */

if ( ! defined( 'ABSPATH' ) ) {
	$wordpress_root = $argv[1] ?? getenv( 'WP_ROOT' ) ?: '';
	if ( '' === $wordpress_root || ! file_exists( $wordpress_root . '/wp-load.php' ) ) {
		fwrite( STDERR, "Usage: php tests/forum-read-abilities-rest-smoke.php /path/to/wordpress\n" );
		exit( 1 );
	}

	define( 'SHORTINIT', true );
	require $wordpress_root . '/wp-load.php';
}

require_once ABSPATH . WPINC . '/rest-api.php';
require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/class-wp-http-response.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';
require_once ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
require_once ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php';
require_once ABSPATH . WPINC . '/abilities-api/class-wp-ability.php';

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_type;
		public $post_status;
		public $post_parent;
		public $post_author;
		public $post_title;
		public $post_content;
		public $post_date;
		public $post_date_gmt;
		public $post_modified;
		public $post_modified_gmt;

		public function __construct( array $data ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts = array();
		public $found_posts = 0;
		public $max_num_pages = 0;

		public function __construct( $args ) {
			$posts = forum_read_filter_posts( $args );
			$this->found_posts = count( $posts );
			$per_page = (int) ( $args['posts_per_page'] ?? -1 );
			$page = max( 1, (int) ( $args['paged'] ?? 1 ) );
			if ( $per_page > 0 ) {
				$this->max_num_pages = (int) ceil( $this->found_posts / $per_page );
				$posts = array_slice( $posts, ( $page - 1 ) * $per_page, $per_page );
			}
			$this->posts = $posts;
		}
	}
}

$GLOBALS['_forum_read_abilities'] = array();
$GLOBALS['_forum_read_posts'] = array();
$GLOBALS['_forum_read_user'] = 0;

if ( ! function_exists( '__' ) ) {
	function __( $text ) { return $text; }
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true() { return true; }
}

function wp_register_ability( $name, $args ) {
	if ( in_array( $name, array( 'extrachill/community-list-forums', 'extrachill/community-list-topics', 'extrachill/community-get-topic', 'extrachill/community-list-replies' ), true ) ) {
		$GLOBALS['_forum_read_abilities'][ $name ] = new WP_Ability( $name, $args );
	}
}
function wp_get_ability( $name ) { return $GLOBALS['_forum_read_abilities'][ $name ] ?? null; }
function get_post( $id ) { return $GLOBALS['_forum_read_posts'][ $id ] ?? null; }
function get_posts( $args ) { return forum_read_filter_posts( $args ); }
function get_userdata( $id ) { return (object) array( 'display_name' => 'User ' . (int) $id ); }
function get_post_meta( $id, $key ) { return '_show_on_homepage' === $key && 10 === (int) $id ? '1' : ''; }
function get_permalink( $id ) { return 'https://community.example/post/' . (int) ( is_object( $id ) ? $id->ID : $id ); }
function current_user_can( $capability, $id = 0 ) {
	return 'read_forum' === $capability && 2 === $GLOBALS['_forum_read_user'] && in_array( (int) $id, array( 20, 30 ), true );
}
function bbp_get_forum_post_type() { return 'forum'; }
function bbp_get_topic_post_type() { return 'topic'; }
function bbp_get_reply_post_type() { return 'reply'; }
function bbp_get_public_status_id() { return 'publish'; }
function bbp_get_closed_status_id() { return 'closed'; }
function bbp_get_private_status_id() { return 'private'; }
function bbp_get_hidden_status_id() { return 'hidden'; }
function bbp_get_forum_topic_count( $id ) { return 10 === (int) $id ? 2 : 1; }
function bbp_get_forum_reply_count() { return 1; }
function bbp_get_topic_reply_count() { return 1; }
function bbp_get_topic_voice_count() { return 2; }
function bbp_get_reply_topic_id( $id ) { return (int) get_post( $id )->post_parent; }
function bbp_get_reply_forum_id( $id ) { return (int) get_post( get_post( $id )->post_parent )->post_parent; }
function bbp_get_reply_to() { return 0; }
function bbp_get_forum_permalink( $id ) { return 'https://community.example/forum/' . (int) $id; }
function bbp_get_topic_permalink( $id ) { return 'https://community.example/topic/' . (int) $id; }
function bbp_get_reply_url( $id ) { return 'https://community.example/reply/' . (int) $id; }

function extrachill_community_ability_create_topic() {}
function extrachill_community_ability_create_topic_permission() { return false; }
function extrachill_community_ability_create_reply() {}
function extrachill_community_ability_create_reply_permission() { return false; }
function extrachill_community_ability_get_topic_for_editor() {}
function extrachill_community_ability_get_topic_for_editor_permission() { return false; }
function extrachill_community_ability_get_reply_for_editor() {}
function extrachill_community_ability_get_reply_for_editor_permission() { return false; }
function extrachill_community_ability_update_topic() {}
function extrachill_community_ability_update_topic_permission() { return false; }
function extrachill_community_ability_update_reply() {}
function extrachill_community_ability_update_reply_permission() { return false; }

function forum_read_post( $id, $type, $status, $parent = 0 ) {
	return new WP_Post(
		array(
			'ID'                => $id,
			'post_type'         => $type,
			'post_status'       => $status,
			'post_parent'       => $parent,
			'post_author'       => 7,
			'post_title'        => ucfirst( $type ) . ' ' . $id,
			'post_content'      => 'Content ' . $id,
			'post_date'         => '2026-08-01 12:00:00',
			'post_date_gmt'     => '2026-08-01 12:00:00',
			'post_modified'     => '2026-08-01 13:00:00',
			'post_modified_gmt' => '2026-08-01 13:00:00',
		)
	);
}

function forum_read_filter_posts( $args ) {
	$types = (array) ( $args['post_type'] ?? array() );
	$statuses = (array) ( $args['post_status'] ?? array() );
	return array_values(
		array_filter(
			$GLOBALS['_forum_read_posts'],
			static function ( $post ) use ( $args, $types, $statuses ) {
				if ( $types && ! in_array( $post->post_type, $types, true ) ) {
					return false;
				}
				if ( $statuses && ! in_array( $post->post_status, $statuses, true ) ) {
					return false;
				}
				if ( isset( $args['post_parent'] ) && (int) $args['post_parent'] !== (int) $post->post_parent ) {
					return false;
				}
				if ( ! empty( $args['post_parent__in'] ) && ! in_array( (int) $post->post_parent, array_map( 'intval', $args['post_parent__in'] ), true ) ) {
					return false;
				}
				return true;
			}
		)
	);
}

function forum_read_request( $name, $input ) {
	$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $name . '/run' );
	$request->set_query_params( array( 'input' => $input ) );
	return rest_do_request( $request );
}

function forum_read_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$posts = array(
	forum_read_post( 10, 'forum', 'publish' ),
	forum_read_post( 20, 'forum', 'private' ),
	forum_read_post( 30, 'forum', 'hidden' ),
	forum_read_post( 11, 'forum', 'publish', 20 ),
	forum_read_post( 100, 'topic', 'publish', 10 ),
	forum_read_post( 101, 'topic', 'closed', 10 ),
	forum_read_post( 102, 'topic', 'draft', 10 ),
	forum_read_post( 103, 'topic', 'trash', 10 ),
	forum_read_post( 200, 'topic', 'publish', 20 ),
	forum_read_post( 300, 'topic', 'publish', 30 ),
	forum_read_post( 110, 'topic', 'publish', 11 ),
	forum_read_post( 1000, 'reply', 'publish', 100 ),
	forum_read_post( 1001, 'reply', 'draft', 100 ),
	forum_read_post( 2000, 'reply', 'publish', 200 ),
);
foreach ( $posts as $post ) {
	$GLOBALS['_forum_read_posts'][ $post->ID ] = $post;
}

require dirname( __DIR__ ) . '/inc/content/public-voice-contract.php';
require dirname( __DIR__ ) . '/inc/core/ability-helpers.php';
require dirname( __DIR__ ) . '/inc/content/topic-reply-formatters.php';
require dirname( __DIR__ ) . '/inc/content/topic-reply-read.php';
require dirname( __DIR__ ) . '/inc/content/topic-reply-abilities.php';
require dirname( __DIR__ ) . '/inc/core/infrastructure-abilities.php';
extrachill_community_register_topic_reply_abilities();
extrachill_community_register_infrastructure_abilities();

$GLOBALS['wp_rest_server'] = new WP_REST_Server();
$GLOBALS['wp_actions']['rest_api_init'] = 1;
$controller = new WP_REST_Abilities_V1_Run_Controller();
$controller->register_routes();

foreach ( $GLOBALS['_forum_read_abilities'] as $ability ) {
	forum_read_assert( true === $ability->get_meta_item( 'show_in_rest' ), $ability->get_name() . ' must be REST exposed.' );
	forum_read_assert( true === $ability->get_meta_item( 'annotations' )['readonly'], $ability->get_name() . ' must remain read-only.' );
	forum_read_assert( false === $ability->get_input_schema()['additionalProperties'], $ability->get_name() . ' input must be exact.' );
	forum_read_assert( false === $ability->get_output_schema()['additionalProperties'], $ability->get_name() . ' output must be exact.' );
}
forum_read_assert( is_wp_error( wp_get_ability( 'extrachill/community-list-topics' )->validate_input( array( 'unknown' => true ) ) ), 'Unknown list input must be rejected by WP_Ability.' );

$GLOBALS['_forum_read_user'] = 0;
$anonymous_forums = forum_read_request( 'extrachill/community-list-forums', array() );
$anonymous_topics = forum_read_request( 'extrachill/community-list-topics', array() );
forum_read_assert( array( 10 ) === array_column( $anonymous_forums->get_data()['forums'], 'forum_id' ), 'Anonymous callers may only list public forums with public ancestors.' );
forum_read_assert( array( 100, 101 ) === array_column( $anonymous_topics->get_data()['topics'], 'topic_id' ) && 2 === $anonymous_topics->get_data()['total'], 'Anonymous topic pagination must exclude private, hidden, draft, and trash rows.' );
forum_read_assert( 404 === forum_read_request( 'extrachill/community-get-topic', array( 'topic_id' => 200 ) )->get_status(), 'Anonymous callers must not read private topics.' );

$GLOBALS['_forum_read_user'] = 1;
$cookie_topics = forum_read_request( 'extrachill/community-list-topics', array( 'forum_id' => 10 ) );
$bearer_topics = forum_read_request( 'extrachill/community-list-topics', array( 'forum_id' => 10 ) );
forum_read_assert( $cookie_topics->get_data() === $bearer_topics->get_data(), 'Cookie and bearer callers resolved to the same member must receive equivalent visible data.' );
forum_read_assert( 404 === forum_read_request( 'extrachill/community-list-replies', array( 'topic_id' => 200 ) )->get_status(), 'Members must not infer replies in private topics.' );

$GLOBALS['_forum_read_user'] = 2;
$moderator_forums = forum_read_request( 'extrachill/community-list-forums', array() );
$moderator_topics = forum_read_request( 'extrachill/community-list-topics', array() );
$private_topic = forum_read_request( 'extrachill/community-get-topic', array( 'topic_id' => 200 ) );
$public_replies = forum_read_request( 'extrachill/community-list-replies', array( 'topic_id' => 100 ) );
forum_read_assert( array( 10, 20, 30, 11 ) === array_column( $moderator_forums->get_data()['forums'], 'forum_id' ), 'Moderators may list authorized private, hidden, and descendant forums.' );
forum_read_assert( array( 100, 101, 200, 300, 110 ) === array_column( $moderator_topics->get_data()['topics'], 'topic_id' ) && 5 === $moderator_topics->get_data()['total'], 'Moderator pagination must include authorized forums without draft or trash topics.' );
forum_read_assert( 200 === $private_topic->get_status() && 200 === $private_topic->get_data()['topic']['topic_id'], 'Moderators may read authorized private topic detail.' );
forum_read_assert( array( 1000 ) === array_column( $public_replies->get_data()['replies'], 'reply_id' ), 'Reply lists must exclude draft replies.' );
forum_read_assert( false === wp_get_ability( 'extrachill/community-list-topics' )->get_output_schema()['properties']['topics']['items']['additionalProperties'], 'Nested topic items must have an exact schema.' );
forum_read_assert( false === wp_get_ability( 'extrachill/community-list-replies' )->get_output_schema()['properties']['replies']['items']['additionalProperties'], 'Nested reply items must have an exact schema.' );

echo "PASS: Canonical forum read REST abilities preserve schemas, pagination, and bbPress visibility.\n";
