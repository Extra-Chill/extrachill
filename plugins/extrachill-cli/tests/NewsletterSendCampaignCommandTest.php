<?php
/**
 * Behavior tests for `wp extrachill newsletter send_campaign`.
 *
 * Run standalone: php tests/NewsletterSendCampaignCommandTest.php
 */

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$messages[] = 'TABLE:' . implode( ',', array_column( $items, 'ID' ) );
		unset( $format, $fields );
	}
}

namespace {
	if ( function_exists( 'get_user_by' ) ) {
		// Real WordPress is bootstrapped; these doubles would collide with core.
		return;
	}

	use ExtraChill\CLI\Commands\Newsletter\NewsletterCommand;

	define( 'ABSPATH', __DIR__ . '/' );

	class NewsletterSendTestError extends RuntimeException {}

	class WP_CLI {
		public static $messages = array();

		public static function error( $message ) {
			throw new NewsletterSendTestError( $message );
		}

		public static function success( $message ) {
			self::$messages[] = 'Success: ' . $message;
		}

		public static function warning( $message ) {
			self::$messages[] = 'Warning: ' . $message;
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}

		public static function line( $message ) {
			self::$messages[] = $message;
		}
	}

	class WP_Error {
		private $message;

		public function __construct( $code, $message ) {
			unset( $code );
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}

	class NewsletterSendTestAbility {
		public $inputs   = array();
		public $previous = null;
		public $fail     = false;

		public function execute( $input ) {
			$this->inputs[] = $input;
			if ( $this->fail && empty( $input['dry_run'] ) ) {
				return new WP_Error( 'newsletter_already_sent', 'This newsletter was already sent or scheduled. Pass force to send it again.' );
			}
			return array(
				'success'      => true,
				'dry_run'      => $input['dry_run'],
				'sent'         => ! $input['dry_run'],
				'scheduled'    => false,
				'post_id'      => $input['post_id'],
				'subject'      => 'Under the Hood',
				'from_name'    => 'Extra Chill',
				'from_email'   => 'newsletter@extrachill.com',
				'lists'        => array( array( 'id' => 'MainHash', 'name' => 'Main', 'active' => 3000 ) ),
				'excluded'     => array( array( 'id' => 'BoogieA', 'name' => 'Boogieman A', 'active' => 336 ) ),
				'total_active' => 3000,
				'schedule'     => isset( $input['schedule_date_time'] ) ? array( 'date_time' => $input['schedule_date_time'], 'timezone' => 'America/New_York' ) : null,
				'previous'     => $this->previous,
				'message'      => $input['dry_run'] ? 'Dry run. Nothing was sent.' : 'Campaign created and now sending',
			);
		}
	}

	$newsletter_send_ability = new NewsletterSendTestAbility();

	function wp_get_ability( $name ) {
		global $newsletter_send_ability;
		if ( 'extrachill/send-campaign' !== $name ) {
			throw new RuntimeException( 'Unexpected ability requested: ' . $name );
		}
		return $newsletter_send_ability;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function newsletter_send_assert( $condition, $message ) {
		if ( ! $condition ) {
			throw new RuntimeException( $message . "\nMessages: " . var_export( WP_CLI::$messages, true ) );
		}
	}

	function newsletter_send_expect_error( callable $run, $needle, $message ) {
		try {
			$run();
		} catch ( NewsletterSendTestError $error ) {
			newsletter_send_assert( false !== strpos( $error->getMessage(), $needle ), $message . ' (got: ' . $error->getMessage() . ')' );
			return;
		}
		throw new RuntimeException( $message . ' (no error raised)' );
	}

	require_once dirname( __DIR__ ) . '/inc/Commands/Newsletter/NewsletterCommand.php';

	$command = new NewsletterCommand();

	// Target is required and exclusive; failures never reach the ability.
	newsletter_send_expect_error( fn() => $command->send_campaign( array( '69' ), array() ), '--lists or --all-lists', 'missing target errors' );
	newsletter_send_expect_error( fn() => $command->send_campaign( array( '69' ), array( 'lists' => 'a', 'all-lists' => true ) ), '--lists or --all-lists', 'both targets error' );
	newsletter_send_expect_error( fn() => $command->send_campaign( array(), array( 'all-lists' => true ) ), 'Post ID', 'missing post errors' );
	newsletter_send_assert( array() === $newsletter_send_ability->inputs, 'invalid args never reach the ability' );

	// Default is preview only.
	WP_CLI::$messages = array();
	$command->send_campaign( array( '69' ), array( 'all-lists' => true, 'exclude' => 'BoogieA, BoogieB,BoogieA' ) );
	newsletter_send_assert( 1 === count( $newsletter_send_ability->inputs ), 'preview runs exactly one ability call' );
	$preview = $newsletter_send_ability->inputs[0];
	newsletter_send_assert( true === $preview['dry_run'], 'preview is a dry run' );
	newsletter_send_assert( true === $preview['all_lists'] && ! isset( $preview['list_ids'] ), 'all-lists maps to all_lists' );
	newsletter_send_assert( array( 'BoogieA', 'BoogieB' ) === $preview['exclude_list_ids'], 'exclusions are split, trimmed and deduped' );
	newsletter_send_assert( in_array( 'Subject:  Under the Hood', WP_CLI::$messages, true ), 'preview prints the subject' );
	newsletter_send_assert( in_array( 'TABLE:MainHash', WP_CLI::$messages, true ), 'preview prints the list table' );
	newsletter_send_assert( in_array( 'Excluded: Boogieman A (336)', WP_CLI::$messages, true ), 'preview names exclusions' );
	newsletter_send_assert( in_array( 'Preview only. Nothing was sent. Re-run with --yes to send.', WP_CLI::$messages, true ), 'preview says nothing was sent' );

	// --yes previews, then sends.
	$newsletter_send_ability->inputs = array();
	WP_CLI::$messages                = array();
	$command->send_campaign( array( '69' ), array( 'lists' => 'MainHash', 'schedule' => '2030-10-19 09:00', 'timezone' => 'America/Chicago', 'yes' => true ) );
	newsletter_send_assert( 2 === count( $newsletter_send_ability->inputs ), '--yes previews then sends' );
	$real = $newsletter_send_ability->inputs[1];
	newsletter_send_assert( false === $real['dry_run'], 'second call is a real send' );
	newsletter_send_assert( array( 'MainHash' ) === $real['list_ids'] && ! isset( $real['all_lists'] ), 'lists map to list_ids' );
	newsletter_send_assert( '2030-10-19 09:00' === $real['schedule_date_time'] && 'America/Chicago' === $real['schedule_timezone'], 'schedule args pass through' );
	newsletter_send_assert( ! isset( $real['force'] ), 'force is off by default' );
	newsletter_send_assert( 'Success: Campaign created and now sending' === end( WP_CLI::$messages ), 'send reports success' );

	// Previous send: preview warns; guarded send errors.
	$newsletter_send_ability->inputs   = array();
	$newsletter_send_ability->previous = array( 'sent_at' => '2026-10-18T13:00:00+00:00', 'scheduled_for' => null, 'list_ids' => array() );
	WP_CLI::$messages                  = array();
	$command->send_campaign( array( '69' ), array( 'all-lists' => true ) );
	newsletter_send_assert( in_array( 'Warning: Already sent at 2026-10-18T13:00:00+00:00. A real send needs --force.', WP_CLI::$messages, true ), 'preview warns about a previous send' );

	$newsletter_send_ability->fail = true;
	newsletter_send_expect_error( fn() => $command->send_campaign( array( '69' ), array( 'all-lists' => true, 'yes' => true ) ), 'already sent', 'guarded send errors' );

	$newsletter_send_ability->fail   = false;
	$newsletter_send_ability->inputs = array();
	$command->send_campaign( array( '69' ), array( 'all-lists' => true, 'yes' => true, 'force' => true ) );
	newsletter_send_assert( true === $newsletter_send_ability->inputs[1]['force'], '--force passes force' );

	// JSON output is a single document.
	$newsletter_send_ability->previous = null;
	WP_CLI::$messages                  = array();
	$command->send_campaign( array( '69' ), array( 'all-lists' => true, 'format' => 'json' ) );
	newsletter_send_assert( 1 === count( WP_CLI::$messages ), 'json preview prints one document' );
	$decoded = json_decode( WP_CLI::$messages[0], true );
	newsletter_send_assert( true === $decoded['dry_run'] && 3000 === $decoded['total_active'], 'json preview is the ability result' );

	fwrite( STDOUT, "NewsletterSendCampaignCommand tests passed.\n" );
}
