<?php
// phpcs:ignoreFile -- Standalone test doubles for the WordPress hook, user, post and database APIs.
/**
 * Push channel smoke test (inc/notifications/push.php through the real
 * ec_users_notify_with_receipts() insert/replay path).
 *
 * Run with: php tests/notification-push-smoke.php
 *
 * @package ExtraChill\Users
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

// ─── Minimal WordPress doubles ───────────────────────────────────────────────

$GLOBALS['smoke_hooks'] = array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['smoke_hooks'][ $hook ][] = array( $callback, $args ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { add_action( $hook, $callback, $priority, $args ); }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['smoke_hooks'][ $hook ] ?? array() as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
	}
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['smoke_hooks'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, $entry[1] ) );
	}
	return $value;
}
function __( $text, $domain = null ) { return $text; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function esc_url_raw( $url ) { return (string) $url; }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_cache_delete( $key, $group = '' ) { return true; }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }

$GLOBALS['smoke_users'] = array(
	7 => (object) array( 'ID' => 7, 'display_name' => 'Riley Replier' ),
	8 => (object) array( 'ID' => 8, 'display_name' => 'Recipient' ),
	9 => (object) array( 'ID' => 9, 'display_name' => 'Opted Out' ),
);
function get_userdata( $id ) { return $GLOBALS['smoke_users'][ (int) $id ] ?? false; }

$GLOBALS['smoke_meta'] = array();
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['smoke_meta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { $GLOBALS['smoke_meta'][ $id ][ $key ] = $value; return true; }
function delete_user_meta( $id, $key ) { unset( $GLOBALS['smoke_meta'][ $id ][ $key ] ); return true; }

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;
	public $post_parent;
	public function __construct( array $fields ) { foreach ( $fields as $key => $value ) { $this->$key = $value; } }
}
$GLOBALS['smoke_posts'] = array(
	// Public, open forum chain.
	10 => new WP_Post( array( 'ID' => 10, 'post_type' => 'forum', 'post_status' => 'publish', 'post_parent' => 0 ) ),
	11 => new WP_Post( array( 'ID' => 11, 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => 10 ) ),
	12 => new WP_Post( array( 'ID' => 12, 'post_type' => 'reply', 'post_status' => 'publish', 'post_parent' => 11 ) ),
	// Private forum chain.
	20 => new WP_Post( array( 'ID' => 20, 'post_type' => 'forum', 'post_status' => 'private', 'post_parent' => 0 ) ),
	21 => new WP_Post( array( 'ID' => 21, 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => 20 ) ),
	// Closed (but published) forum chain.
	30 => new WP_Post( array( 'ID' => 30, 'post_type' => 'forum', 'post_status' => 'publish', 'post_parent' => 0 ) ),
	31 => new WP_Post( array( 'ID' => 31, 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => 30 ) ),
);
$GLOBALS['smoke_post_meta'] = array( 30 => array( '_bbp_status' => 'closed' ) );
function get_post( $id ) { return is_object( $id ) ? $id : ( $GLOBALS['smoke_posts'][ (int) $id ] ?? null ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['smoke_post_meta'][ (int) $id ][ $key ] ?? ''; }
function get_post_ancestors( $post ) {
	$ancestors = array();
	$parent    = (int) $post->post_parent;
	while ( $parent > 0 && isset( $GLOBALS['smoke_posts'][ $parent ] ) ) {
		$ancestors[] = $parent;
		$parent      = (int) $GLOBALS['smoke_posts'][ $parent ]->post_parent;
	}
	return $ancestors;
}

/**
 * In-memory stand-in for the notification table's INSERT IGNORE + unique
 * (user_id, delivery_key) contract used by ec_users_notify_with_receipts().
 */
class Smoke_WPDB {
	public $base_prefix = 'wp_';
	public $insert_id   = 0;
	private $rows       = array();
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function query( $prepared ) {
		list( $sql, $args ) = $prepared;
		if ( 0 !== strpos( $sql, 'INSERT IGNORE' ) ) {
			return 0;
		}
		$key = $args[0] . '|' . $args[11];
		if ( isset( $this->rows[ $key ] ) ) {
			$this->insert_id = 0;
			return 0;
		}
		$this->insert_id    = count( $this->rows ) + 100;
		$this->rows[ $key ] = array(
			'id'                  => $this->insert_id,
			'producer'            => $args[9],
			'idempotency_key'     => $args[10],
			'producer_owns_email' => $args[8],
		);
		return 1;
	}
	public function get_row( $prepared, $output = null ) {
		list( , $args ) = $prepared;
		return $this->rows[ $args[0] . '|' . $args[1] ] ?? null;
	}
}
$GLOBALS['wpdb'] = new Smoke_WPDB();
define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__ ) . '/inc/notifications/push.php';

function push_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

function push_payload( array $overrides = array() ) {
	return array_merge(
		array(
			'actor_id'        => 7,
			'type'            => 'reply',
			'title'           => 'Best basement shows this spring',
			'link'            => 'https://community.example.org/t/basement/#post-12',
			'item_id'         => 11,
			'producer'        => 'tests.push',
			'idempotency_key' => 'reply:12',
		),
		$overrides
	);
}

function push_outbox() { return $GLOBALS['ec_users_push_test_outbox'] ?? array(); }

// 1. No wp-native-auth: insert succeeds, nothing is pushed, nothing fatals.
push_check( ! function_exists( 'wp_native_auth_send_push' ), 'primitive absent at start' );
$receipt = ec_users_notify_with_receipts( 8, push_payload( array( 'idempotency_key' => 'absent:1' ) ) );
push_check( 1 === $receipt['inserted'], 'insert without wp-native-auth' );
push_check( array() === push_outbox(), 'no push without wp-native-auth' );

// Install the primitive double (function_exists now true).
require_once __DIR__ . '/support/wp-native-auth-push-double.php';
push_check( function_exists( 'wp_native_auth_send_push' ), 'double installed' );

// 2. Insert of an allow-listed type pushes once with the contract shape.
$receipt = ec_users_notify_with_receipts( 8, push_payload() );
$outbox  = push_outbox();
push_check( 1 === count( $outbox ), 'allow-listed insert pushes' );
push_check( 8 === $outbox[0]['user_id'], 'pushed to recipient' );
push_check(
	array(
		'title'       => 'New reply from Riley Replier',
		'data'        => array(
			'notification_id' => $receipt['recipients'][8]['notification_id'],
			'type'            => 'reply',
			'url'             => 'https://community.example.org/t/basement/#post-12',
		),
		'collapse_id' => 'ec-topic-11',
		'body'        => 'Best basement shows this spring',
	) === $outbox[0]['message'],
	'public forum message shape'
);

// 3. Replay (existing receipt) does not push again.
$replay = ec_users_notify_with_receipts( 8, push_payload() );
push_check( 1 === $replay['existing'], 'replay is existing' );
push_check( 1 === count( push_outbox() ), 'replay does not push' );

// Mention on a reply in the same topic collapses with the reply push.
ec_users_notify_with_receipts( 8, push_payload( array( 'type' => 'mention', 'item_id' => 12, 'producer' => 'tests.push.mention', 'idempotency_key' => 'content:12' ) ) );
$outbox = push_outbox();
push_check( 'Riley Replier mentioned you' === $outbox[1]['message']['title'], 'mention title' );
push_check( 'ec-topic-11' === $outbox[1]['message']['collapse_id'], 'per-topic collapse id' );

// 4. Opted-out recipient is not pushed; preference helpers mirror email.
push_check( ec_users_notification_push_enabled( 9 ), 'push defaults on' );
ec_users_set_notification_push_disabled( 9, true );
push_check( ! ec_users_notification_push_enabled( 9 ), 'push disabled' );
$before = count( push_outbox() );
$receipt = ec_users_notify_with_receipts( 9, push_payload( array( 'idempotency_key' => 'optout:1' ) ) );
push_check( 1 === $receipt['inserted'] && count( push_outbox() ) === $before, 'opted-out user not pushed' );
ec_users_set_notification_push_disabled( 9, false );
push_check( ec_users_notification_push_enabled( 9 ) && '' === get_user_meta( 9, EC_NOTIFICATIONS_PUSH_DISABLED_META ), 'enable removes the flag' );

// 5. Engagement / non-allow-listed types never push.
$before = count( push_outbox() );
foreach ( array( 'upvote', 'milestone', 'local_scene_digest', 'festival_wire_update' ) as $i => $type ) {
	ec_users_notify_with_receipts( 8, push_payload( array( 'type' => $type, 'idempotency_key' => 'nudge:' . $i ) ) );
}
push_check( count( push_outbox() ) === $before, 'non-allow-listed types do not push' );

// 6. Private content never reaches the lock screen.
$secret = 'Secret: Jamie Doe +2 for the Room 3 show';
$cases  = array(
	'private forum' => array( 'item_id' => 21 ),
	'closed forum'  => array( 'item_id' => 31 ),
	'missing item'  => array( 'item_id' => 999 ),
	'venue scope'   => array( 'type' => 'guest_list_request', 'item_id' => 4242 ),
	'booking scope' => array( 'type' => 'booking_inquiry_submitted', 'item_id' => 0 ),
);
$i = 0;
foreach ( $cases as $label => $overrides ) {
	$before = count( push_outbox() );
	ec_users_notify_with_receipts( 8, push_payload( array_merge( array( 'title' => $secret, 'idempotency_key' => 'private:' . $i++ ), $overrides ) ) );
	$outbox = push_outbox();
	push_check( count( $outbox ) === $before + 1, $label . ' still pushes' );
	$message = end( $outbox )['message'];
	push_check( ! isset( $message['body'] ), $label . ' has no body' );
	push_check( false === strpos( json_encode( array( $message['title'], $message['collapse_id'] ) ), 'Jamie' ), $label . ' leaks nothing' );
	push_check( '' !== $message['title'], $label . ' keeps a generic title' );
}
push_check( 'New booking inquiry' === end( $outbox )['message']['title'], 'generic private title' );

// The allow-list is filterable from one place.
add_filter( 'ec_users_notification_push_types', function ( $types ) { unset( $types['reply'] ); return $types; } );
$before = count( push_outbox() );
ec_users_notify_with_receipts( 8, push_payload( array( 'idempotency_key' => 'filtered:1' ) ) );
push_check( count( push_outbox() ) === $before, 'filter removes a type' );

echo "PASS: push channel pushes genuine allow-listed inserts only, honours opt-out, keeps private content off the lock screen, and no-ops without wp-native-auth.\n";
