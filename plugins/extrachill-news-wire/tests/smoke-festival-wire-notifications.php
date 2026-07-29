<?php
/**
 * Focused smoke coverage for Festival Wire publication notifications.
 *
 * Run with: php tests/smoke-festival-wire-notifications.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Post {
	public $ID = 14;
	public $post_author = 1;
	public $post_type = 'festival_wire';
}

class WP_Error {}

$meta = array();
$notifications = array();
$resolved_slugs = array();
$delivery_receipts = array();

function add_filter() {}
function add_action() {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post_meta( $post_id, $key ) { global $meta; return $meta[ $post_id ][ $key ] ?? ''; }
function add_post_meta( $post_id, $key, $value, $unique ) { global $meta; if ( $unique && isset( $meta[ $post_id ][ $key ] ) ) { return false; } $meta[ $post_id ][ $key ] = $value; return true; }
function delete_post_meta( $post_id, $key ) { global $meta; unset( $meta[ $post_id ][ $key ] ); return true; }
function wp_get_post_terms() { return array( 'big-fest', 'small-fest' ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $text ) { return $text; }
function get_the_title() { return 'The Festival Update'; }
function get_permalink() { return 'https://wire.extrachill.com/festival-wire/the-festival-update/'; }
function extrachill_users_entity_subscription_recipients( $producer, $entity_type, $taxonomy, $slug ) { global $resolved_slugs; $resolved_slugs[] = array( $producer, $entity_type, $taxonomy, $slug ); return 'big-fest' === $slug ? array( 7, 8 ) : array( 8, 9 ); }
function ec_users_notify_with_receipts( $recipients, $payload ) { global $notifications, $delivery_receipts; $notifications[] = array( $recipients, $payload ); return array_shift( $delivery_receipts ); }

require dirname( __DIR__ ) . '/includes/festival-wire-notifications.php';

function expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$post = new WP_Post();

expect( ec_news_wire_authorize_festival_notification_producer( false, 'festival_wire' ), 'The News Wire producer must be authorized.' );
expect( ! ec_news_wire_authorize_festival_notification_producer( false, 'other-producer' ), 'Other producers must not be authorized.' );

$delivery_receipts[] = array(
	'requested'  => 3,
	'inserted'   => 1,
	'existing'   => 2,
	'failed'     => 0,
	'recipients' => array(),
);
ec_news_wire_notify_festival_subscribers_on_publish( 'publish', 'draft', $post );

expect( 2 === count( $resolved_slugs ), 'Every attached festival slug must be resolved.' );
expect( 1 === count( $notifications ), 'A first publication must create one receipted notification delivery.' );
expect( array( 7, 8, 9 ) === $notifications[0][0], 'Recipients across festival terms must be deduplicated.' );
expect( 'festival_wire_update' === $notifications[0][1]['type'], 'The notification must use the Festival Wire payload type.' );
expect( 14 === $notifications[0][1]['item_id'], 'The notification must reference the published post.' );
expect( EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_PRODUCER === $notifications[0][1]['producer'], 'The notification must use the existing producer namespace.' );
expect( 'post:14' === $notifications[0][1]['idempotency_key'], 'The notification must use an immutable post idempotency key.' );
expect( 1 === get_post_meta( 14, EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_SENT_META, true ), 'Inserted and existing receipts must preserve the publication claim.' );

ec_news_wire_notify_festival_subscribers_on_publish( 'publish', 'publish', $post );
expect( 1 === count( $notifications ), 'Published updates must not create duplicate notifications.' );

$failed_post = new WP_Post();
$failed_post->ID = 15;
$delivery_receipts[] = array(
	'requested'  => 3,
	'inserted'   => 2,
	'existing'   => 0,
	'failed'     => 1,
	'recipients' => array(),
);
ec_news_wire_notify_festival_subscribers_on_publish( 'publish', 'draft', $failed_post );
expect( '' === get_post_meta( 15, EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_SENT_META, true ), 'An explicit receipt failure must release the publication claim.' );

$delivery_receipts[] = array(
	'requested'  => 3,
	'inserted'   => 3,
	'existing'   => 0,
	'failed'     => 0,
	'recipients' => array(),
);
ec_news_wire_notify_festival_subscribers_on_publish( 'publish', 'draft', $failed_post );
expect( 3 === count( $notifications ), 'A failed delivery must be retryable.' );
expect( 1 === get_post_meta( 15, EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_SENT_META, true ), 'A successful retry must preserve the publication claim.' );

$invalid_post = new WP_Post();
$invalid_post->ID = 16;
$delivery_receipts[] = array( 'failed' => 0 );
ec_news_wire_notify_festival_subscribers_on_publish( 'publish', 'draft', $invalid_post );
expect( '' === get_post_meta( 16, EC_NEWS_WIRE_FESTIVAL_NOTIFICATION_SENT_META, true ), 'An invalid receipt shape must release the publication claim.' );

print "Festival Wire notification smoke test passed.\n";
