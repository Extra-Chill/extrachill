<?php
/**
 * Standalone contract test for the one-time app relaunch email (#1417).
 *
 * Covers segment selection (dormant posters in; recent posters, opted-out,
 * team, bot and blocked users out), idempotency of the sent marker, and the
 * refusal to send while the store IDs are unset. Never sends mail: the queue
 * is a recording double.
 *
 * Run with: php tests/app-relaunch-email-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class Relaunch_Test_User {
	public $ID;
	public $user_email;
	public $display_name;
	public $roles = array();
	public $spam  = 0;
	public $deleted = 0;

	public function __construct( $id, array $props = array() ) {
		$this->ID           = $id;
		$this->user_email   = "user{$id}@example.com";
		$this->display_name = "User {$id}";
		foreach ( $props as $key => $value ) {
			$this->$key = $value;
		}
	}
}

$GLOBALS['_relaunch'] = array(
	'users'         => array(),
	'meta'          => array(),
	'caps'          => array(),
	'team'          => array(),
	'site_options'  => array(),
	'filters'       => array(),
);

function add_action() {}
function __( $text ) {
	return $text;
}
function esc_html__( $text ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_url( $url ) {
	return (string) $url;
}
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}
function home_url( $path = '' ) {
	return 'https://community.example.com' . $path;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['_relaunch']['filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function get_site_option( $key, $default = false ) {
	return $GLOBALS['_relaunch']['site_options'][ $key ] ?? $default;
}
function get_userdata( $user_id ) {
	return $GLOBALS['_relaunch']['users'][ (int) $user_id ] ?? false;
}
function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['_relaunch']['meta'][ (int) $user_id ][ $key ] ?? '';
}
function add_user_meta( $user_id, $key, $value, $unique = false ) {
	if ( $unique && isset( $GLOBALS['_relaunch']['meta'][ (int) $user_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['_relaunch']['meta'][ (int) $user_id ][ $key ] = $value;
	return 1;
}
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['_relaunch']['meta'][ (int) $user_id ][ $key ] );
	return true;
}
function is_super_admin( $user_id ) {
	return false;
}
function user_can( $user_id, $cap ) {
	return in_array( $cap, $GLOBALS['_relaunch']['caps'][ (int) $user_id ] ?? array(), true );
}
function current_user_can( $cap ) {
	return false;
}
function ec_is_team_member( $user_id ) {
	return in_array( (int) $user_id, $GLOBALS['_relaunch']['team'], true );
}
function ec_get_network_bot_user_id() {
	return 32;
}
function ec_get_site_url( $key ) {
	return 'https://community.example.com';
}
function ec_notifications_unsubscribe_url( $user_id ) {
	return 'https://community.example.com/wp-json/extrachill/v1/notifications/unsubscribe?token=t' . (int) $user_id;
}

require dirname( __DIR__ ) . '/inc/core/app-relaunch-email.php';

function relaunch_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$now     = strtotime( '2026-11-15 12:00:00 UTC' );
$dormant = gmdate( 'Y-m-d H:i:s', $now - 200 * DAY_IN_SECONDS );
$recent  = gmdate( 'Y-m-d H:i:s', $now - 10 * DAY_IN_SECONDS );

// 10, 11: dormant posters. 12: recent poster. 13: opted out. 14: team.
// 15: admin. 16: moderator. 32: network bot. 17: blocked. 18: spam flag.
// 19: already sent. 20: invalid email. 21: excluded by filter (bounced).
foreach ( array( 10, 11, 12, 13, 14, 15, 16, 32, 17, 18, 19, 21 ) as $id ) {
	$GLOBALS['_relaunch']['users'][ $id ] = new Relaunch_Test_User( $id );
}
$GLOBALS['_relaunch']['users'][17]->roles = array( 'bbp_blocked' );
$GLOBALS['_relaunch']['users'][18]->spam  = 1;
$GLOBALS['_relaunch']['users'][20]        = new Relaunch_Test_User( 20, array( 'user_email' => 'not-an-email' ) );
$GLOBALS['_relaunch']['meta'][13]['ec_notification_emails_disabled'] = 1;
$GLOBALS['_relaunch']['meta'][19][ EXTRACHILL_COMMUNITY_APP_RELAUNCH_SENT_META ] = $now - 5;
$GLOBALS['_relaunch']['team']     = array( 14 );
$GLOBALS['_relaunch']['caps'][15] = array( 'manage_options' );
$GLOBALS['_relaunch']['caps'][16] = array( 'moderate' );
$GLOBALS['_relaunch']['filters']['extrachill_community_app_relaunch_exclusion_reason'][] = static function ( $reason, $user_id ) {
	return 21 === $user_id ? 'bounced' : $reason;
};

$rows = array();
foreach ( array( 10, 11, 13, 14, 15, 16, 32, 17, 18, 19, 20, 21 ) as $id ) {
	$rows[] = array( 'user_id' => $id, 'last_post_gmt' => $dormant );
}
$rows[] = array( 'user_id' => 12, 'last_post_gmt' => $recent );

$queued = array();
$deps   = array(
	'now'        => $now,
	'candidates' => static function () use ( &$rows ) {
		return $rows;
	},
	'last_topic' => static function ( $user_id ) {
		return array(
			'title' => "Thread by {$user_id}",
			'url'   => "https://community.example.com/t/{$user_id}/",
		);
	},
	'queue'      => static function ( $args ) use ( &$queued ) {
		$queued[] = $args;
		return array( 'success' => true );
	},
);

// Dry run: counts and sample, no queue, no markers.
$dry = extrachill_community_app_relaunch_run( array(), $deps );
relaunch_check( is_array( $dry ) && 'dry-run' === $dry['mode'], 'dry run is the default' );
relaunch_check( 13 === $dry['participants'], 'participants count every poster' );
relaunch_check( 1 === $dry['recent'], 'recent poster is counted as recent' );
relaunch_check( 12 === $dry['dormant'], 'dormant posters are counted' );
relaunch_check( 2 === $dry['eligible'], 'only the two plain dormant posters are eligible' );
relaunch_check( array( 10, 11 ) === array_column( $dry['sample'], 'user_id' ), 'sample lists the dormant posters' );
relaunch_check( 'Thread by 10' === $dry['sample'][0]['last_topic'], 'sample carries the last thread title' );
relaunch_check( 1 === ( $dry['excluded']['opted_out'] ?? 0 ), 'opted-out user excluded' );
relaunch_check( 3 === ( $dry['excluded']['team'] ?? 0 ), 'team, admin and moderator excluded' );
relaunch_check( 3 === ( $dry['excluded']['bot'] ?? 0 ), 'bot, blocked and spam accounts excluded' );
relaunch_check( 1 === ( $dry['excluded']['already_sent'] ?? 0 ), 'already-sent user excluded' );
relaunch_check( 1 === ( $dry['excluded']['invalid_user'] ?? 0 ), 'invalid email excluded' );
relaunch_check( 1 === ( $dry['excluded']['bounced'] ?? 0 ), 'filter can exclude bounced users' );
relaunch_check( false === $dry['store_links_ready'], 'dry run reports store links not ready' );
relaunch_check( array() === $queued, 'dry run queues nothing' );
relaunch_check( '' === get_user_meta( 10, EXTRACHILL_COMMUNITY_APP_RELAUNCH_SENT_META, true ), 'dry run writes no marker' );

// Send refuses without store IDs.
$refused = extrachill_community_app_relaunch_run( array( 'send' => true ), $deps );
relaunch_check( is_wp_error( $refused ) && 'app_store_links_unset' === $refused->get_error_code(), 'send refuses without store IDs' );
$GLOBALS['_relaunch']['site_options']['extrachill_app_store_id'] = '1234567890';
$refused = extrachill_community_app_relaunch_run( array( 'send' => true ), $deps );
relaunch_check( is_wp_error( $refused ) && 'app_store_links_unset' === $refused->get_error_code(), 'send refuses without the Play package' );
relaunch_check( array() === $queued, 'refused send queues nothing' );

// Send with both store IDs set.
$GLOBALS['_relaunch']['site_options']['extrachill_play_package'] = 'com.extrachill.app';
$sent = extrachill_community_app_relaunch_run( array( 'send' => true ), $deps );
relaunch_check( is_array( $sent ) && 2 === $sent['sent'] && 0 === $sent['failed'], 'send queues one email per eligible user' );
relaunch_check( array( 'user10@example.com', 'user11@example.com' ) === array_column( $queued, 'to' ), 'only eligible users are emailed' );
$body = $queued[0]['context']['body_html'];
relaunch_check( false !== strpos( $body, 'Thread by 10' ), 'email names the last thread' );
relaunch_check( false !== strpos( $body, 'replies reach your phone' ), 'email carries the app message' );
relaunch_check( false !== strpos( $body, 'https://apps.apple.com/app/id1234567890' ), 'email links the App Store' );
relaunch_check( false !== strpos( $body, 'https://play.google.com/store/apps/details?id=com.extrachill.app' ), 'email links Google Play' );
relaunch_check( false !== strpos( $body, 'notifications/unsubscribe?token=t10' ), 'email carries the one-tap unsubscribe link' );
relaunch_check( 'extrachill/branded' === $queued[0]['template'], 'email uses the branded product template' );
relaunch_check( $now === get_user_meta( 10, EXTRACHILL_COMMUNITY_APP_RELAUNCH_SENT_META, true ), 'sent marker recorded' );

// Idempotency: re-running sends nothing new.
$again = extrachill_community_app_relaunch_run( array( 'send' => true ), $deps );
relaunch_check( 0 === $again['eligible'] && 0 === $again['sent'], 're-run sends nothing' );
relaunch_check( 2 === count( $queued ), 're-run queues no duplicate' );
relaunch_check( 'already_sent' === extrachill_community_app_relaunch_send_one( 10, null, extrachill_community_app_relaunch_store_links(), $deps['queue'], $now ), 'direct resend is refused by the marker' );
relaunch_check( 2 === count( $queued ), 'direct resend queues nothing' );

// A failed enqueue releases the claim so a later run can retry.
$GLOBALS['_relaunch']['users'][22] = new Relaunch_Test_User( 22 );
$rows                              = array( array( 'user_id' => 22, 'last_post_gmt' => $dormant ) );
$deps['queue']                     = static function () {
	return array( 'success' => false, 'error' => 'queue down' );
};
$failed = extrachill_community_app_relaunch_run( array( 'send' => true ), $deps );
relaunch_check( 1 === $failed['failed'] && 0 === $failed['sent'], 'failed enqueue is reported' );
relaunch_check( '' === get_user_meta( 22, EXTRACHILL_COMMUNITY_APP_RELAUNCH_SENT_META, true ), 'failed enqueue releases the marker' );

// --limit caps a send run.
$GLOBALS['_relaunch']['users'][23] = new Relaunch_Test_User( 23 );
$rows[]                            = array( 'user_id' => 23, 'last_post_gmt' => $dormant );
$deps['queue']                     = static function ( $args ) use ( &$queued ) {
	$queued[] = $args;
	return array( 'success' => true );
};
$limited = extrachill_community_app_relaunch_run( array( 'send' => true, 'limit' => 1 ), $deps );
relaunch_check( 1 === $limited['sent'] && 2 === $limited['eligible'], 'limit caps the send run' );

echo "PASS: app relaunch email segment, exclusions, store-ID refusal and idempotency.\n";
