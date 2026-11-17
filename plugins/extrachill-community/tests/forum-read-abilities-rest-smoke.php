<?php
/**
 * Core REST and visibility contract coverage for canonical forum reads.
 *
 * Requires a fully booted WordPress: it runs through the host-smoke backend
 * (declared in homeboy.json `wordpress_test_paths`). Forums, topics, replies,
 * and users are real rows in the sandbox database and every request goes
 * through core's Abilities REST run route. This plugin and bbPress are mounted
 * but not activated there, so the test registers bbPress's post types and
 * statuses and stubs only the bbPress template functions the reads call.
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) {
	fwrite( STDERR, "forum-read-abilities-rest-smoke.php requires a booted WordPress; run it through the host-smoke backend.\n" );
	exit( 1 );
}

$GLOBALS['_forum_read_member_query'] = array();

if ( ! function_exists( 'bbp_get_forum_post_type' ) ) {
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
}

// The write and editor abilities share a registration function with the
// reads; their callbacks are out of scope here.
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

// bbPress's post types and statuses, as bbPress registers them: closed is
// public, spam is protected, and hidden is private.
register_post_type( 'forum', array( 'public' => true, 'hierarchical' => true, 'capability_type' => array( 'forum', 'forums' ) ) );
register_post_type( 'topic', array( 'public' => true, 'capability_type' => array( 'topic', 'topics' ) ) );
register_post_type( 'reply', array( 'public' => true, 'capability_type' => array( 'reply', 'replies' ) ) );
register_post_status( 'closed', array( 'public' => true ) );
register_post_status( 'spam', array( 'protected' => true, 'exclude_from_search' => true ) );
register_post_status( 'hidden', array( 'private' => true, 'exclude_from_search' => true ) );

// User 1 is a plain member and user 2 a moderator who may read private and
// hidden forums 20 and 30. Replace each user's capabilities with exactly that.
add_filter(
	'user_has_cap',
	static function ( $allcaps, $caps, $args, $user ) {
		$granted = array();
		if ( 2 === (int) $user->ID ) {
			$granted['read_private_forums'] = true;
			if ( 'read_forum' === ( $args[0] ?? '' ) && in_array( (int) ( $args[2] ?? 0 ), array( 20, 30 ), true ) ) {
				$granted['read_forum'] = true;
			}
		}
		return $granted;
	},
	PHP_INT_MAX,
	4
);

add_action(
	'pre_get_posts',
	static function ( $query ) {
		if ( isset( $query->query['author'] ) ) {
			$GLOBALS['_forum_read_member_query'] = $query->query;
		}
	}
);

function forum_read_user( $id ) {
	global $wpdb;
	if ( get_userdata( $id ) ) {
		return;
	}
	$wpdb->insert(
		$wpdb->users,
		array(
			'ID'              => $id,
			'user_login'      => 'forum-read-' . $id,
			'user_pass'       => wp_hash_password( wp_generate_password() ),
			'user_nicename'   => 'forum-read-' . $id,
			'user_email'      => 'forum-read-' . $id . '@example.com',
			'user_registered' => '2024-01-01 00:00:00',
			'display_name'    => 'User ' . $id,
		)
	);
	wp_cache_delete( $id, 'users' );
	forum_read_assert( get_userdata( $id ) instanceof WP_User, "Fixture user {$id} must exist." );
}

// Each fixture is a second older than the previous one, so date-ordered
// queries return fixtures in declaration order.
function forum_read_post( $id, $type, $status, $parent = 0, $author = 7, $date = null ) {
	static $sequence = 0;
	++$sequence;
	$date = $date ?? gmdate( 'Y-m-d H:i:s', strtotime( '2024-08-01 12:00:00' ) - $sequence );

	$inserted = wp_insert_post(
		array(
			'import_id'         => $id,
			'post_type'         => $type,
			'post_status'       => $status,
			'post_parent'       => $parent,
			'post_author'       => $author,
			'post_title'        => ucfirst( $type ) . ' ' . $id,
			'post_content'      => 'Content ' . $id,
			'post_date'         => $date,
			'post_date_gmt'     => $date,
		),
		true
	);
	forum_read_assert( $id === $inserted, "Fixture post {$id} must be created with its requested ID: " . wp_json_encode( $inserted ) );
	forum_read_assert( $status === get_post_status( $id ), "Fixture post {$id} must keep status {$status}." );
}

function forum_read_set_viewer( $user_id ) {
	wp_set_current_user( $user_id );
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

foreach ( array( 2, 7, 8 ) as $forum_read_user_id ) {
	forum_read_user( $forum_read_user_id );
}

forum_read_post( 10, 'forum', 'publish' );
forum_read_post( 20, 'forum', 'private' );
forum_read_post( 30, 'forum', 'hidden' );
forum_read_post( 11, 'forum', 'publish', 20 );
forum_read_post( 100, 'topic', 'publish', 10 );
forum_read_post( 101, 'topic', 'closed', 10 );
forum_read_post( 102, 'topic', 'draft', 10 );
forum_read_post( 103, 'topic', 'trash', 10 );
forum_read_post( 200, 'topic', 'publish', 20 );
forum_read_post( 300, 'topic', 'publish', 30 );
forum_read_post( 110, 'topic', 'publish', 11 );
forum_read_post( 1000, 'reply', 'publish', 100 );
forum_read_post( 1001, 'reply', 'draft', 100 );
forum_read_post( 2000, 'reply', 'publish', 200 );
update_post_meta( 10, '_show_on_homepage', '1' );

require dirname( __DIR__ ) . '/inc/content/public-voice-contract.php';
require dirname( __DIR__ ) . '/inc/core/ability-helpers.php';
require dirname( __DIR__ ) . '/inc/moderation/member-blocks.php';
require dirname( __DIR__ ) . '/inc/content/topic-reply-formatters.php';
require dirname( __DIR__ ) . '/inc/content/topic-reply-read.php';
require dirname( __DIR__ ) . '/inc/content/topic-reply-abilities.php';
require dirname( __DIR__ ) . '/inc/core/infrastructure-abilities.php';
require_once __DIR__ . '/support/booted-ability-registry.php';
extrachill_community_test_register_abilities(
	static function () {
		extrachill_community_register_topic_reply_abilities();
		extrachill_community_register_infrastructure_abilities();
	}
);

$forum_read_abilities = array();
foreach ( array( 'extrachill/community-list-forums', 'extrachill/community-list-topics', 'extrachill/community-get-topic', 'extrachill/community-list-replies' ) as $forum_read_ability_name ) {
	$forum_read_abilities[ $forum_read_ability_name ] = wp_get_ability( $forum_read_ability_name );
	forum_read_assert( $forum_read_abilities[ $forum_read_ability_name ] instanceof WP_Ability, $forum_read_ability_name . ' must register in the core registry.' );
}

foreach ( $forum_read_abilities as $ability ) {
	forum_read_assert( true === $ability->get_meta_item( 'show_in_rest' ), $ability->get_name() . ' must be REST exposed.' );
	forum_read_assert( true === $ability->get_meta_item( 'annotations' )['readonly'], $ability->get_name() . ' must remain read-only.' );
	forum_read_assert( false === $ability->get_input_schema()['additionalProperties'], $ability->get_name() . ' input must be exact.' );
	forum_read_assert( false === $ability->get_output_schema()['additionalProperties'], $ability->get_name() . ' output must be exact.' );
}
forum_read_assert( is_wp_error( wp_get_ability( 'extrachill/community-list-topics' )->validate_input( array( 'unknown' => true ) ) ), 'Unknown list input must be rejected by WP_Ability.' );

forum_read_set_viewer( 0 );
$anonymous_forums = forum_read_request( 'extrachill/community-list-forums', array() );
$anonymous_topics = forum_read_request( 'extrachill/community-list-topics', array() );
forum_read_assert( array( 10 ) === array_column( $anonymous_forums->get_data()['forums'] ?? array(), 'forum_id' ), 'Anonymous callers may only list public forums with public ancestors: ' . wp_json_encode( $anonymous_forums->get_data() ) );
forum_read_assert( array( 100, 101 ) === array_column( $anonymous_topics->get_data()['topics'] ?? array(), 'topic_id' ) && 2 === $anonymous_topics->get_data()['total'], 'Anonymous topic pagination must exclude private, hidden, draft, and trash rows: ' . wp_json_encode( $anonymous_topics->get_data() ) );
forum_read_assert( 404 === forum_read_request( 'extrachill/community-get-topic', array( 'topic_id' => 200 ) )->get_status(), 'Anonymous callers must not read private topics.' );

forum_read_set_viewer( 1 );
$cookie_topics = forum_read_request( 'extrachill/community-list-topics', array( 'forum_id' => 10 ) );
$bearer_topics = forum_read_request( 'extrachill/community-list-topics', array( 'forum_id' => 10 ) );
forum_read_assert( $cookie_topics->get_data() === $bearer_topics->get_data(), 'Cookie and bearer callers resolved to the same member must receive equivalent visible data.' );
forum_read_assert( 404 === forum_read_request( 'extrachill/community-list-replies', array( 'topic_id' => 200 ) )->get_status(), 'Members must not infer replies in private topics.' );

forum_read_set_viewer( 2 );
$moderator_forums = forum_read_request( 'extrachill/community-list-forums', array() );
$moderator_topics = forum_read_request( 'extrachill/community-list-topics', array() );
$private_topic    = forum_read_request( 'extrachill/community-get-topic', array( 'topic_id' => 200 ) );
$public_replies   = forum_read_request( 'extrachill/community-list-replies', array( 'topic_id' => 100 ) );
// Forums are listed by menu_order then title ("Forum 10" < "Forum 11" < ...).
forum_read_assert( array( 10, 11, 20, 30 ) === array_column( $moderator_forums->get_data()['forums'] ?? array(), 'forum_id' ), 'Moderators may list authorized private, hidden, and descendant forums: ' . wp_json_encode( $moderator_forums->get_data() ) );
forum_read_assert( array( 100, 101, 200, 300, 110 ) === array_column( $moderator_topics->get_data()['topics'] ?? array(), 'topic_id' ) && 5 === $moderator_topics->get_data()['total'], 'Moderator pagination must include authorized forums without draft or trash topics: ' . wp_json_encode( $moderator_topics->get_data() ) );
forum_read_assert( 200 === $private_topic->get_status() && 200 === $private_topic->get_data()['topic']['topic_id'], 'Moderators may read authorized private topic detail: ' . wp_json_encode( $private_topic->get_data() ) );
forum_read_assert( array( 1000 ) === array_column( $public_replies->get_data()['replies'] ?? array(), 'reply_id' ), 'Reply lists must exclude draft replies.' );
forum_read_assert( false === wp_get_ability( 'extrachill/community-list-topics' )->get_output_schema()['properties']['topics']['items']['additionalProperties'], 'Nested topic items must have an exact schema.' );
forum_read_assert( false === wp_get_ability( 'extrachill/community-list-replies' )->get_output_schema()['properties']['replies']['items']['additionalProperties'], 'Nested reply items must have an exact schema.' );

// Member blocks hide the blocked author's topics and replies from the blocker only.
forum_read_post( 1002, 'reply', 'publish', 100, 8, '2024-08-01 12:00:00' );
forum_read_post( 104, 'topic', 'publish', 10, 8 );
forum_read_set_viewer( 1 );
extrachill_community_set_member_blocked_ids( 1, array( 8 ) );
$blocking_topics  = forum_read_request( 'extrachill/community-list-topics', array( 'forum_id' => 10 ) );
$blocking_replies = forum_read_request( 'extrachill/community-list-replies', array( 'topic_id' => 100 ) );
$blocking_detail  = forum_read_request( 'extrachill/community-get-topic', array( 'topic_id' => 100 ) );
forum_read_assert( array( 100, 101 ) === array_column( $blocking_topics->get_data()['topics'] ?? array(), 'topic_id' ), 'Topic lists hide authors the viewer blocked: ' . wp_json_encode( $blocking_topics->get_data() ) );
forum_read_assert( array( 1000 ) === array_column( $blocking_replies->get_data()['replies'] ?? array(), 'reply_id' ), 'Reply lists hide authors the viewer blocked.' );
forum_read_assert( array( 1000 ) === array_column( $blocking_detail->get_data()['replies'] ?? array(), 'reply_id' ), 'Topic detail replies hide authors the viewer blocked.' );
forum_read_set_viewer( 2 );
$other_replies = forum_read_request( 'extrachill/community-list-replies', array( 'topic_id' => 100 ) );
forum_read_assert( array( 1000, 1002 ) === array_column( $other_replies->get_data()['replies'] ?? array(), 'reply_id' ), 'Blocks only affect the member who blocked: ' . wp_json_encode( $other_replies->get_data() ) );
wp_delete_post( 104, true );

// Member lists remain strictly public even for viewers with private-forum access.
forum_read_post( 12, 'forum', 'publish' );
forum_read_post( 120, 'topic', 'publish', 12, 7, '2024-08-02 12:00:00' );
forum_read_post( 105, 'topic', 'spam', 10 );
forum_read_post( 106, 'topic', 'publish', 10, 8 );
forum_read_set_viewer( 2 );

$member_input  = array(
	'author_id' => 7,
	'orderby'   => 'title',
	'order'     => 'ASC',
	'per_page'  => 2,
);
$member_topics = forum_read_request( 'extrachill/community-list-topics', $member_input );
$member_query  = $GLOBALS['_forum_read_member_query'];
forum_read_assert( 200 === $member_topics->get_status(), 'Core REST accepts author_id without forum_id.' );
forum_read_assert( array( 120, 100 ) === array_column( $member_topics->get_data()['topics'] ?? array(), 'topic_id' ), 'Member topics are newest first across public forums, excluding other authors and nonpublic topics: ' . wp_json_encode( $member_topics->get_data() ) );
forum_read_assert( 3 === $member_topics->get_data()['total'], 'Member pagination excludes private, hidden, nested-private, spam, trash and draft topics.' );
forum_read_assert( 'date' === ( $member_query['orderby'] ?? '' ) && 'DESC' === ( $member_query['order'] ?? '' ) && true === ( $member_query['ignore_sticky_posts'] ?? null ), 'Member lists force chronological order without sticky ranking.' );

$member_input['page'] = 2;
forum_read_assert( array( 101 ) === array_column( forum_read_request( 'extrachill/community-list-topics', $member_input )->get_data()['topics'] ?? array(), 'topic_id' ), 'Member pagination retains closed public topics.' );
forum_read_assert(
	404 === forum_read_request(
		'extrachill/community-list-topics',
		array(
			'author_id' => 7,
			'forum_id'  => 20,
		)
	)->get_status(),
	'Member forum filtering never exposes private forums to moderators.'
);
forum_read_assert( is_wp_error( wp_get_ability( 'extrachill/community-list-topics' )->validate_input( array( 'author_id' => 0 ) ) ), 'Guest author filters are invalid.' );
extrachill_community_set_member_blocked_ids( 2, array( 7 ) );
forum_read_assert( array() === forum_read_request( 'extrachill/community-list-topics', array( 'author_id' => 7 ) )->get_data()['topics'], 'Member author filters respect viewer blocks.' );
// Core drops `author` whenever `author__not_in` is set; blocking someone else
// must not widen an author filter to every unblocked author.
extrachill_community_set_member_blocked_ids( 2, array( 8 ) );
$member_topics_blocking_other = forum_read_request( 'extrachill/community-list-topics', array( 'author_id' => 7 ) )->get_data();
forum_read_assert( array( 7 ) === array_values( array_unique( array_column( $member_topics_blocking_other['topics'], 'author_id' ) ) ) && 3 === $member_topics_blocking_other['total'], 'Blocking another member keeps the author filter: ' . wp_json_encode( $member_topics_blocking_other ) );

echo "PASS: Canonical forum read REST abilities preserve schemas, pagination, and bbPress visibility.\n";
