<?php
/**
 * Focused smoke coverage for entity subscription notifications.
 *
 * Run with: php tests/festival-subscriptions.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Term {
	public $slug;

	public function __construct( $slug ) {
		$this->slug = $slug;
	}
}

$test_meta          = array();
$test_notifications = array();

function add_action() {}
function add_filter() {}
function is_wp_error() {
	return false;
}
function wp_get_post_terms( $post_id, $taxonomy ) {
	if ( 'artist' === $taxonomy ) {
		return array( new WP_Term( 'artist-one' ), new WP_Term( 'artist-two' ) );
	}

	return array( new WP_Term( 'festival-one' ), new WP_Term( 'festival-two' ) );
}
function get_post_meta( $post_id, $key ) {
	global $test_meta;
	return $test_meta[ $post_id ][ $key ] ?? '';
}
function update_post_meta( $post_id, $key, $value ) {
	global $test_meta;
	$test_meta[ $post_id ][ $key ] = $value;
}
function add_post_meta( $post_id, $key, $value, $unique ) {
	global $test_meta;
	if ( $unique && isset( $test_meta[ $post_id ][ $key ] ) ) {
		return false;
	}
	$test_meta[ $post_id ][ $key ] = $value;
	return true;
}
function current_time() {
	return '2026-07-12 20:00:00';
}
function get_current_blog_id() {
	return 1;
}
function get_userdata( $user_id ) {
	return $user_id > 0 ? (object) array( 'ID' => $user_id ) : false;
}
function absint( $value ) {
	return abs( (int) $value );
}
function get_the_title() {
	return 'Festival coverage';
}
function get_permalink() {
	return 'https://extrachill.com/festival-coverage/';
}
function __( $text ) {
	return $text;
}
function extrachill_users_entity_subscription_recipients( $producer, $entity_type, $taxonomy, $slug ) {
	if ( 'extrachill-blog' !== $producer || $entity_type !== $taxonomy ) {
		return array();
	}
	return '-one' === substr( $slug, -4 ) ? array( 4, 7 ) : array( 7, 9 );
}
function ec_users_notify( $recipient_ids, $data ) {
	global $test_notifications;
	$test_notifications[] = array(
		'recipient_ids' => $recipient_ids,
		'data'          => $data,
	);
}

require_once dirname( __DIR__ ) . '/inc/archive/festival-subscriptions.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

assert_same( true, extrachill_blog_authorize_festival_subscription_producer( false, 'extrachill-blog' ), 'Blog producer must be authorized.' );
assert_same( false, extrachill_blog_authorize_festival_subscription_producer( false, 'untrusted-producer' ), 'Untrusted producer must remain unauthorized.' );

$post = (object) array(
	'ID'          => 46,
	'post_type'   => 'post',
	'post_author' => 12,
);

extrachill_blog_notify_festival_subscribers( 'publish', 'draft', $post );
assert_same( 1, count( $test_notifications ), 'First publication should notify subscribers once.' );
assert_same( array( 4, 7, 9 ), $test_notifications[0]['recipient_ids'], 'Recipients from multiple festival terms must be unique.' );
assert_same( 'festival_update', $test_notifications[0]['data']['type'], 'Festival updates must use their dedicated notification type.' );

extrachill_blog_notify_festival_subscribers( 'publish', 'draft', $post );
assert_same( 1, count( $test_notifications ), 'Publish guard must prevent duplicate notifications.' );

$artist_post = (object) array(
	'ID'          => 47,
	'post_type'   => 'post',
	'post_author' => 12,
);

extrachill_blog_notify_artist_subscribers( 'publish', 'draft', $artist_post );
assert_same( 2, count( $test_notifications ), 'First artist publication should notify subscribers once.' );
assert_same( array( 4, 7, 9 ), $test_notifications[1]['recipient_ids'], 'Artist recipients from multiple terms must be unique.' );
assert_same( 'artist_update', $test_notifications[1]['data']['type'], 'Artist updates must use their dedicated notification type.' );

extrachill_blog_notify_artist_subscribers( 'publish', 'draft', $artist_post );
assert_same( 2, count( $test_notifications ), 'Artist publish guard must prevent duplicate notifications.' );

fwrite( STDOUT, "Entity subscription tests passed.\n" );
