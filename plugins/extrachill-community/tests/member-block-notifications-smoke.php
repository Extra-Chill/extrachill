<?php
/** Member blocks suppress community notifications caused by the blocked member. */

define( 'ABSPATH', __DIR__ . '/' );

// Users: 10 topic author, 20 replier (actor), 30 + 40 subscribers / mentioned members.
$GLOBALS['_mbn_meta']  = array();
$GLOBALS['_mbn_calls'] = array();

function add_action() {}
function add_filter() {}
function remove_action() {}
function absint( $value ) { return abs( (int) $value ); }
function current_time() { return '2026-07-29 12:00:00'; }
function get_user_meta( $user_id, $key ) { return $GLOBALS['_mbn_meta'][ $user_id ][ $key ] ?? ''; }
function get_post_field( $field, $post_id ) { return 100 === (int) $post_id ? '10' : '20'; }
function get_the_title( $post_id ) { return 'Topic ' . (int) $post_id; }
function get_permalink( $post_id ) { return 'https://community.example/topic/' . (int) $post_id; }
function bbp_get_reply_url( $reply_id ) { return 'https://community.example/reply/' . (int) $reply_id; }
function bbp_get_topic_permalink( $topic_id ) { return 'https://community.example/topic/' . (int) $topic_id; }
function bbp_get_topic_content() { return '@carol @dave hello'; }
function bbp_get_reply_content() { return '@topic-author @carol @dave thanks'; }
function bbp_is_user_inactive() { return false; }
function get_user_by( $field, $slug ) {
	$map = array( 'topic-author' => 10, 'carol' => 30, 'dave' => 40 );
	return isset( $map[ $slug ] ) ? (object) array( 'ID' => $map[ $slug ] ) : false;
}
function bbp_is_subscriptions_active() { return true; }
function bbp_add_user_subscription() {}
function bbp_get_subscribers() { return array( 10, 20, 30, 40 ); }
function ec_users_notify_with_receipts( $recipients, array $payload ) {
	$GLOBALS['_mbn_calls'][] = array( $recipients, $payload );
	return array( 'failed' => 0 );
}
function extrachill_users_notifications_table_name() { return 'wp_notifications'; }
function bbp_get_public_status_id() { return 'publish'; }
function get_post_status() { return 'publish'; }
function get_post_meta() { return ''; }
function add_post_meta() { return true; }
function delete_post_meta() { return true; }
function get_the_terms( $post_id, $taxonomy ) { return array( (object) array( 'slug' => $taxonomy . '-slug' ) ); }
function is_wp_error() { return false; }
function extrachill_users_entity_subscription_recipients() { return array( 10, 30, 40 ); }

$wpdb = new class() {
	public function prepare( $query ) { return $query; }
	public function query() { return true; }
};

require dirname( __DIR__ ) . '/inc/moderation/member-blocks.php';
require dirname( __DIR__ ) . '/inc/social/notifications/capture-replies.php';
require dirname( __DIR__ ) . '/inc/social/notifications/capture-mentions.php';
require dirname( __DIR__ ) . '/inc/social/notifications/capture-subscriptions.php';
require dirname( __DIR__ ) . '/inc/social/notifications/capture-festival-topics.php';

function mbn_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function mbn_block( $blocker, array $blocked ) {
	$GLOBALS['_mbn_meta'][ $blocker ][ EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_META ] = $blocked;
}

/** Recipients notified of a given type since the last reset, flattened. */
function mbn_recipients( $type ) {
	$ids = array();
	foreach ( $GLOBALS['_mbn_calls'] as $call ) {
		if ( $type === $call[1]['type'] ) {
			$ids = array_merge( $ids, (array) $call[0] );
		}
	}
	return $ids;
}

function mbn_reset() {
	$GLOBALS['_mbn_calls'] = array();
}

// Helper contract.
mbn_block( 30, array( 20 ) );
mbn_assert( array( 10, 40, 10 ) === extrachill_community_filter_member_blocked_recipients( array( 10, 30, 40, 10 ), 20 ), 'Recipient filter drops blockers and keeps order/duplicates.' );
mbn_assert( array( 10, 30, 40 ) === extrachill_community_filter_member_blocked_recipients( array( 10, 30, 40 ), 99 ), 'Recipient filter keeps everyone for an unblocked actor.' );

// Baseline: nobody blocks the replier, so everyone is notified.
$GLOBALS['_mbn_meta'] = array();
mbn_reset();
extrachill_capture_reply_notifications( 200, 100, 1, array(), 20 );
extrachill_capture_subscription_notifications( 200, 100, 1, array(), 20 );
extrachill_capture_mention_notifications( 200, 100, 1, array(), 20 );
mbn_assert( array( 10 ) === mbn_recipients( 'reply' ), 'Unblocked topic author gets the reply notification.' );
mbn_assert( array( 30, 40 ) === mbn_recipients( 'subscription' ), 'Unblocked subscribers get subscription notifications.' );
mbn_assert( array( 10, 30, 40 ) === mbn_recipients( 'mention' ), 'Unblocked mentioned members get mention notifications.' );

// Topic author (10) and Carol (30) block the replier (20); Dave (40) does not.
mbn_block( 10, array( 20 ) );
mbn_block( 30, array( 99, 20 ) );
mbn_reset();
extrachill_capture_reply_notifications( 200, 100, 1, array(), 20 );
extrachill_capture_subscription_notifications( 200, 100, 1, array(), 20 );
extrachill_capture_mention_notifications( 200, 100, 1, array(), 20 );
mbn_assert( array() === mbn_recipients( 'reply' ), 'A topic author who blocked the replier gets no reply notification.' );
mbn_assert( array( 40 ) === mbn_recipients( 'subscription' ), 'Subscribers who blocked the replier are skipped; others still notified.' );
mbn_assert( array( 40 ) === mbn_recipients( 'mention' ), 'Mentioned members who blocked the author are skipped; others still notified.' );

// Topic mentions and entity topic subscriptions honor blocks of the topic author (20 on topic 300).
mbn_block( 10, array() );
mbn_block( 40, array( 20 ) );
mbn_reset();
extrachill_capture_mention_notifications( 300, 300, 1, array() );
extrachill_community_notify_festival_topic_subscribers( 300 );
extrachill_community_notify_artist_topic_subscribers( 300 );
mbn_assert( array() === mbn_recipients( 'mention' ), 'Topic mentions skip members who blocked the topic author.' );
mbn_assert( array( 10 ) === mbn_recipients( 'festival_discussion' ), 'Festival subscribers who blocked the topic author are skipped.' );
mbn_assert( array( 10 ) === mbn_recipients( 'artist_discussion' ), 'Artist subscribers who blocked the topic author are skipped.' );

// Unblocking restores notifications.
$GLOBALS['_mbn_meta'] = array();
mbn_reset();
extrachill_capture_reply_notifications( 201, 100, 1, array(), 20 );
extrachill_capture_mention_notifications( 201, 100, 1, array(), 20 );
mbn_assert( array( 10 ) === mbn_recipients( 'reply' ), 'Unblocked topic author is notified again.' );
mbn_assert( array( 10, 30, 40 ) === mbn_recipients( 'mention' ), 'Unblocked mentioned members are notified again.' );

echo "PASS: Member blocks suppress reply, mention, and subscription notifications from blocked members.\n";
