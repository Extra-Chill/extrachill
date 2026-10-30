<?php
/**
 * Standalone tests for the platform mail wrappers: every send runs inside
 * PermissionHelper::run_as_system() (data-machine#3572) and WP_Error results
 * are normalized into the documented array envelope.
 */

namespace DataMachine\Abilities {

	class PermissionHelper {
		public static bool $system = false;
		public static int $entered = 0;

		public static function run_as_system( callable $callback ) {
			++self::$entered;
			$previous     = self::$system;
			self::$system = true;
			try {
				return $callback();
			} finally {
				self::$system = $previous;
			}
		}
	}
}

namespace {

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['mail_direct_result']  = null;
$GLOBALS['mail_direct_args']    = null;
$GLOBALS['mail_queued_result']  = null;
$GLOBALS['mail_queued_args']    = null;
$GLOBALS['mail_log_file']       = tempnam( sys_get_temp_dir(), 'mail-smoke-log-' );
ini_set( 'error_log', $GLOBALS['mail_log_file'] );

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function add_action() {}

function add_filter() {}

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}

function get_current_blog_id() {
	return 1;
}

function get_site_transient( $key ) {
	unset( $key );
	return false;
}

function set_site_transient( $key, $value, $ttl = 0 ) {
	unset( $key, $value, $ttl );
	return true;
}

function get_option( $name ) {
	unset( $name );
	// Easy WP SMTP configured with a real mailer so mail_site_id resolves locally.
	return array(
		'mail' => array( 'mailer' => 'smtp' ),
		'smtp' => array( 'host' => 'smtp.test' ),
	);
}

class FakeSendEmailAbility {
	public function execute( array $args ) {
		$GLOBALS['mail_direct_args'] = $args;
		$GLOBALS['mail_seen_system'] = DataMachine\Abilities\PermissionHelper::$system;
		return $GLOBALS['mail_direct_result'];
	}
}

class FakeSendEmailQueuedAbility {
	public function execute( array $args ) {
		$GLOBALS['mail_queued_args'] = $args;
		$GLOBALS['mail_seen_system'] = DataMachine\Abilities\PermissionHelper::$system;
		return $GLOBALS['mail_queued_result'];
	}
}

function wp_get_ability( $slug ) {
	if ( 'datamachine/send-email' === $slug ) {
		return new FakeSendEmailAbility();
	}
	if ( 'datamachine/send-email-queued' === $slug ) {
		return new FakeSendEmailQueuedAbility();
	}
	return null;
}

require_once dirname( __DIR__ ) . '/inc/core/mail.php';

function mail_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

function mail_reset() {
	$GLOBALS['mail_direct_result']  = null;
	$GLOBALS['mail_direct_args']    = null;
	$GLOBALS['mail_queued_result']  = null;
	$GLOBALS['mail_queued_args']    = null;
	$GLOBALS['mail_seen_system']    = null;
	DataMachine\Abilities\PermissionHelper::$system  = false;
	DataMachine\Abilities\PermissionHelper::$entered = 0;
}

// --- Direct send runs as the system. ------------------------------------------

mail_reset();
$GLOBALS['mail_direct_result'] = array( 'success' => true, 'message' => 'sent' );
$result = ec_send_email( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert( 1 === DataMachine\Abilities\PermissionHelper::$entered, 'ec_send_email() enters run_as_system once' );
mail_assert( true === $GLOBALS['mail_seen_system'], 'the direct ability executes inside the system context' );
mail_assert( false === DataMachine\Abilities\PermissionHelper::$system, 'the system context is left after the send' );
mail_assert( true === $result['success'], 'a successful direct send is returned untouched' );
mail_assert( ! array_key_exists( 'system', $GLOBALS['mail_direct_args'] ), 'no system input flag is sent to the ability' );
mail_assert( null === $GLOBALS['mail_queued_args'], 'a direct send never falls back to the queue' );

// --- Queued send runs as the system. ------------------------------------------

mail_reset();
$GLOBALS['mail_queued_result'] = array( 'success' => true, 'action_id' => 7 );
$result = ec_send_email_queued( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert( 1 === DataMachine\Abilities\PermissionHelper::$entered && true === $GLOBALS['mail_seen_system'], 'ec_send_email_queued() executes inside the system context' );
mail_assert( 7 === $result['action_id'], 'a successful queued send is returned untouched' );
mail_assert( 1 === (int) $GLOBALS['mail_queued_args']['mail_site_id'] && 'extrachill/branded' === $GLOBALS['mail_queued_args']['template'], 'queued sends keep the resolved mail defaults' );

// --- WP_Error results are normalized, never returned raw. ---------------------

mail_reset();
$GLOBALS['mail_direct_result'] = new WP_Error( 'email_auth_ref_required', 'An authorized mailbox ref is required to send email.' );
$result = ec_send_email( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert( is_array( $result ) && false === $result['success'], 'a refused direct send returns an array envelope with success => false' );
mail_assert( 'email_auth_ref_required' === $result['error_code'] && '' !== $result['error'], 'the envelope carries the error code and message' );

mail_reset();
$GLOBALS['mail_queued_result'] = new WP_Error( 'ability_invalid_permissions', 'Denied.' );
$result = ec_send_email_queued( array( 'to' => 'fan@example.com', 'subject' => 'Hi' ) );

mail_assert( is_array( $result ) && false === $result['success'] && 'ability_invalid_permissions' === $result['error_code'], 'a refused queued send is normalized too' );

echo "mail-system-send-smoke: ok\n";
}
