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

function add_filter() {}
function add_action() {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post_meta( $post_id, $key ) { global $meta; return $meta[ $post_id ][ $key ] ?? ''; }
function add_post_meta( $post_id, $key, $value, $unique ) { global $meta; if ( $unique && isset( $meta[ $post_id ][ $key ] ) ) { return false; } $meta[ $post_id ][ $key ] = $value; return true; }
function wp_get_post_terms() { return array( 'big-fest', 'small-fest' ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $text ) { return $text; }
function get_the_title() { return 'The Festival Update'; }
function get_permalink() { return 'https://wire.extrachill.com/festival-wire/the-festival-update/'; }
function extrachill_users_entity_subscription_recipients( $producer, $entity_type, $taxonomy, $slug ) { global $resolved_slugs; $resolved_slugs[] = array( $producer, $entity_type, $taxonomy, $slug ); return 'big-fest' === $slug ? array( 7, 8 ) : array( 8, 9 ); }
function ec_users_notify( $recipients, $payload ) { global $notifications; $notifications[] = array( $recipients, $payload ); }

require dirname( __DIR__ ) . '/includes/festival-wire-notifications.php';

function expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$post = new WP_Post();

expect( ec_news_wire_authorize_festival_notification_producer( false, 'festival_wire' ), 'The News Wire producer must be authorized.' );
expect( ! ec_news_wire_authorize_festival_notification_producer( false, 'other-producer' ), 'Other producers must not be authorized.' );

ec_news_wire_notify_festival_subscribers_on_publish( 'publish', 'draft', $post );

expect( 2 === count( $resolved_slugs ), 'Every attached festival slug must be resolved.' );
expect( 1 === count( $notifications ), 'A first publication must create one shared notification.' );
expect( array( 7, 8, 9 ) === $notifications[0][0], 'Recipients across festival terms must be deduplicated.' );
expect( 'festival_wire_update' === $notifications[0][1]['type'], 'The notification must use the Festival Wire payload type.' );
expect( 14 === $notifications[0][1]['item_id'], 'The notification must reference the published post.' );

ec_news_wire_notify_festival_subscribers_on_publish( 'publish', 'publish', $post );
expect( 1 === count( $notifications ), 'Published updates must not create duplicate notifications.' );

print "Festival Wire notification smoke test passed.\n";
