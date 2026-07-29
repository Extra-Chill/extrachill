<?php
/** Standalone contract tests for receipt-aware community notification producers. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['_notification_calls'] = array();
$GLOBALS['_post_meta']          = array();
$GLOBALS['_receipt']            = array( 'failed' => 0 );

function add_action() {}
function add_filter() {}
function remove_action() {}
function absint( $value ) {
	return abs( (int) $value );
}
function current_time() {
	return '2026-07-29 12:00:00';
}
function get_post_field( $field, $post_id ) {
	return 100 === (int) $post_id ? 10 : 20;
}
function get_the_title( $post_id ) {
	return 'Topic ' . (int) $post_id;
}
function get_permalink( $post_id ) {
	return 'https://community.example/topic/' . (int) $post_id;
}
function bbp_get_reply_url( $reply_id ) {
	return 'https://community.example/reply/' . (int) $reply_id;
}
function bbp_get_topic_permalink( $topic_id ) {
	return 'https://community.example/topic/' . (int) $topic_id;
}
function bbp_get_topic_content() {
	return '@topic-author welcome';
}
function bbp_get_reply_content() {
	return '@topic-author thanks';
}
function bbp_is_user_inactive() {
	return false;
}
function get_user_by( $field, $username ) {
	return 'topic-author' === $username ? (object) array( 'ID' => 10 ) : false;
}
function bbp_is_subscriptions_active() {
	return true;
}
function ec_users_auto_subscribe_enabled() {
	return true;
}
function bbp_add_user_subscription( $user_id, $topic_id ) {
	$GLOBALS['_subscribed'] = array( $user_id, $topic_id );
}
function bbp_get_subscribers() {
	return array( 20, 10, 30, '30', 40, 0 );
}
function ec_users_notify_with_receipts( $recipients, array $payload ) {
	$GLOBALS['_notification_calls'][] = array( $recipients, $payload );
	return $GLOBALS['_receipt'];
}
function extrachill_users_notifications_table_name() {
	return 'wp_notifications';
}
function bbp_get_public_status_id() {
	return 'publish';
}
function get_post_status() {
	return 'publish';
}
function get_post_meta( $post_id, $key ) {
	return $GLOBALS['_post_meta'][ $post_id ][ $key ] ?? '';
}
function add_post_meta( $post_id, $key, $value ) {
	if ( isset( $GLOBALS['_post_meta'][ $post_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['_post_meta'][ $post_id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['_post_meta'][ $post_id ][ $key ] );
	$GLOBALS['_deleted_meta'][] = array( $post_id, $key );
	return true;
}
function get_the_terms( $post_id, $taxonomy ) {
	return array( (object) array( 'slug' => $taxonomy . '-slug' ) );
}
function is_wp_error() {
	return false;
}
function extrachill_users_entity_subscription_recipients( $producer, $entity_type ) {
	return 'festival' === $entity_type ? array( 20, 30, 30 ) : array( 20, 40 );
}

class Notification_Test_DB {
	public $queries = array();

	public function prepare( $query, ...$args ) {
		$this->queries[] = array( $query, $args );
		return $query;
	}

	public function query( $query ) {
		return true;
	}
}

$wpdb = new Notification_Test_DB();

require __DIR__ . '/../inc/social/notifications/capture-replies.php';
require __DIR__ . '/../inc/social/notifications/capture-mentions.php';
require __DIR__ . '/../inc/social/notifications/capture-subscriptions.php';
require __DIR__ . '/../inc/social/notifications/capture-festival-topics.php';

function notification_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		exit( 1 );
	}
}

function notification_test_canonical_payload( array $payload ) {
	$expected = array( 'actor_id', 'type', 'title', 'link', 'item_id', 'producer', 'idempotency_key' );
	return $expected === array_keys( $payload );
}

extrachill_capture_reply_notifications( 200, 100, 1, array(), 20 );
list( $recipients, $payload ) = $GLOBALS['_notification_calls'][0];
notification_test_assert( 10 === $recipients, 'Reply recipient shaping changed.' );
notification_test_assert( notification_test_canonical_payload( $payload ), 'Reply payload is not canonical.' );
notification_test_assert( 'extrachill-community/replies' === $payload['producer'] && 'reply:200' === $payload['idempotency_key'], 'Reply receipt identity is unstable.' );
notification_test_assert( 100 === $payload['item_id'], 'Reply item context must remain the topic for mention deduplication.' );

extrachill_capture_mention_notifications( 200, 100, 1, array(), 20 );
list( $recipients, $payload ) = $GLOBALS['_notification_calls'][1];
notification_test_assert( 10 === $recipients && 200 === $payload['item_id'], 'Mention content context changed.' );
notification_test_assert( notification_test_canonical_payload( $payload ), 'Mention payload is not canonical.' );
notification_test_assert( 'extrachill-community/mentions' === $payload['producer'] && 'content:200' === $payload['idempotency_key'], 'Mention receipt identity is unstable.' );
notification_test_assert( 1 === count( $wpdb->queries ) && 'reply' === $wpdb->queries[0][1][2] && 100 === $wpdb->queries[0][1][3], 'Mention-over-reply deduplication did not run against the topic reply.' );

extrachill_capture_mention_notifications( 101, 101, 1, array() );
list( $recipients, $payload ) = $GLOBALS['_notification_calls'][2];
notification_test_assert( 10 === $recipients && 101 === $payload['item_id'], 'Topic mention content context changed.' );
notification_test_assert( 'content:101' === $payload['idempotency_key'], 'Topic mention receipt identity is unstable.' );

extrachill_capture_subscription_notifications( 200, 100, 1, array(), 20 );
list( $recipients, $payload ) = $GLOBALS['_notification_calls'][3];
notification_test_assert( array( 30, 30, 40 ) === $recipients, 'Subscription recipient shaping changed.' );
notification_test_assert( array( 20, 100 ) === $GLOBALS['_subscribed'], 'Reply author auto-subscription changed.' );
notification_test_assert( notification_test_canonical_payload( $payload ), 'Subscription payload is not canonical.' );
notification_test_assert( 'extrachill-community/topic-subscriptions' === $payload['producer'] && 'reply:200' === $payload['idempotency_key'], 'Subscription receipt identity is unstable.' );

$GLOBALS['_receipt'] = array( 'failed' => 0 );
extrachill_community_notify_festival_topic_subscribers( 300 );
list( $recipients, $payload ) = $GLOBALS['_notification_calls'][4];
notification_test_assert( array( 30 ) === $recipients, 'Festival recipient shaping changed.' );
notification_test_assert( notification_test_canonical_payload( $payload ), 'Festival payload is not canonical.' );
notification_test_assert( 'extrachill-community/festival-topics' === $payload['producer'] && 'topic:300' === $payload['idempotency_key'], 'Festival receipt identity is unstable.' );
notification_test_assert( isset( $GLOBALS['_post_meta'][300][EXTRACHILL_COMMUNITY_FESTIVAL_TOPIC_NOTIFIED_META] ), 'Successful festival receipt released its claim.' );

$GLOBALS['_receipt'] = array( 'failed' => 1 );
extrachill_community_notify_artist_topic_subscribers( 301 );
list( $recipients, $payload ) = $GLOBALS['_notification_calls'][5];
notification_test_assert( array( 40 ) === $recipients, 'Artist recipient shaping changed.' );
notification_test_assert( notification_test_canonical_payload( $payload ), 'Artist payload is not canonical.' );
notification_test_assert( 'extrachill-community/artist-topics' === $payload['producer'] && 'topic:301' === $payload['idempotency_key'], 'Artist receipt identity is unstable.' );
notification_test_assert( ! isset( $GLOBALS['_post_meta'][301][EXTRACHILL_COMMUNITY_ARTIST_TOPIC_NOTIFIED_META] ), 'Explicit failed artist receipt did not release its claim.' );

echo "PASS: Community notifications use canonical receipt identities and preserve delivery behavior.\n";
