<?php
/**
 * Adapter test for `wp extrachill community app-relaunch-email` (#1417).
 *
 * The command must default to a dry run, pass --send through only when given,
 * and surface the ability's refusal as a CLI error.
 */

namespace WP_CLI\Utils {
	if ( ! function_exists( 'get_flag_value' ) ) {
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}

		function format_items( $format, $items, $fields ) {
			\WP_CLI::$messages[] = 'table:' . count( $items );
		}
	}
}

namespace {
	if ( function_exists( 'get_user_by' ) ) {
		// Real WordPress is loaded; these doubles would collide. Run standalone.
		return;
	}

	define( 'ABSPATH', __DIR__ . '/' );

	class AppRelaunchTestError extends RuntimeException {}

	class WP_CLI {
		public static $messages = array();

		public static function error( $message ) {
			throw new AppRelaunchTestError( $message );
		}

		public static function success( $message ) {
			self::$messages[] = 'Success: ' . $message;
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}
	}

	class WP_Error {
		public function get_error_message() {
			return 'Refusing to send: set the network options.';
		}
	}

	class AppRelaunchTestAbility {
		public $inputs = array();

		public function execute( $input ) {
			$this->inputs[] = $input;
			if ( ! empty( $input['send'] ) && ! empty( $GLOBALS['app_relaunch_refuse'] ) ) {
				return new WP_Error();
			}
			return array(
				'mode'                 => empty( $input['send'] ) ? 'dry-run' : 'send',
				'participants'         => 3,
				'recent'               => 1,
				'dormant'              => 2,
				'excluded'             => array( 'opted_out' => 1 ),
				'eligible'             => 1,
				'store_links_ready'    => true,
				'sample'               => array( array( 'user_id' => 10, 'display_name' => 'A', 'last_post_gmt' => '2026-01-01 00:00:00', 'last_topic' => 'T' ) ),
				'sent'                 => empty( $input['send'] ) ? 0 : 1,
				'failed'               => 0,
				'skipped_already_sent' => 0,
			);
		}
	}

	$app_relaunch_ability = new AppRelaunchTestAbility();

	function ec_get_blog_id( $key ) {
		return 2;
	}
	function ec_get_site_url( $key ) {
		return 'https://community.extrachill.com';
	}
	function get_current_blog_id() {
		return 2;
	}
	function wp_get_ability( $name ) {
		return 'extrachill/community-app-relaunch-email' === $name ? $GLOBALS['app_relaunch_ability'] : null;
	}
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function app_relaunch_assert( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/OwnerSiteAbility.php';
	require_once dirname( __DIR__ ) . '/inc/Commands/Community/CommunityCommand.php';

	$command = new \ExtraChill\CLI\Commands\Community\CommunityCommand();

	$command->app_relaunch_email( array(), array() );
	app_relaunch_assert( false === $app_relaunch_ability->inputs[0]['send'], 'default run is a dry run' );
	app_relaunch_assert( in_array( 'Dry run: nothing sent. Re-run with --send to queue the email.', WP_CLI::$messages, true ), 'dry run says nothing was sent' );
	app_relaunch_assert( in_array( '    Eligible:               1', WP_CLI::$messages, true ), 'dry run prints counts' );
	app_relaunch_assert( in_array( 'table:1', WP_CLI::$messages, true ), 'dry run prints a sample' );

	WP_CLI::$messages = array();
	$command->app_relaunch_email( array(), array( 'send' => true, 'limit' => '5' ) );
	app_relaunch_assert( true === $app_relaunch_ability->inputs[1]['send'] && 5 === $app_relaunch_ability->inputs[1]['limit'], '--send and --limit pass through' );
	app_relaunch_assert( in_array( 'Success: Queued 1, failed 0, already sent 0.', WP_CLI::$messages, true ), 'send reports the outcome' );

	$GLOBALS['app_relaunch_refuse'] = true;
	try {
		$command->app_relaunch_email( array(), array( 'send' => true ) );
		app_relaunch_assert( false, 'refusal must error' );
	} catch ( AppRelaunchTestError $error ) {
		app_relaunch_assert( false !== strpos( $error->getMessage(), 'Refusing to send' ), 'refusal surfaces as a CLI error' );
	}

	fwrite( STDOUT, "AppRelaunchEmailCommand tests passed.\n" );
}
