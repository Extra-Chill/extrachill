<?php
/**
 * Source-routing tests for `wp extrachill analytics errors` (#1353).
 *
 * Run standalone: `php tests/ErrorsCommandTest.php`.
 */

namespace {
	$errors_command_wp_bootstrapped = function_exists( 'get_user_by' );

	if ( ! $errors_command_wp_bootstrapped ) {
		define( 'ABSPATH', __DIR__ . '/' );

		class WP_CLI {
			public static $messages = array();
			public static $errors   = array();

			public static function log( $message ) {
				self::$messages[] = $message;
			}

			public static function success( $message ) {
				self::$messages[] = 'Success: ' . $message;
			}

			public static function warning( $message ) {
				self::$messages[] = 'Warning: ' . $message;
			}

			public static function error( $message ) {
				self::$errors[] = $message;
				throw new \RuntimeException( 'wp-cli-error: ' . $message );
			}
		}

		class ErrorsCommandTestAbility {
			public $result = array();
			public $inputs = array();

			public function execute( $input ) {
				$this->inputs[] = $input;
				return $this->result;
			}
		}

		$errors_command_php  = new ErrorsCommandTestAbility();
		$errors_command_rest = new ErrorsCommandTestAbility();

		function wp_get_ability( $name ) {
			global $errors_command_php, $errors_command_rest;
			if ( 'extrachill/get-php-error-summary' === $name ) {
				return $errors_command_php;
			}
			if ( 'extrachill/get-rest-5xx-summary' === $name ) {
				return $errors_command_rest;
			}
			return null;
		}

		function is_wp_error( $value ) {
			return false;
		}

		function errors_command_assert_same( $expected, $actual, $message ) {
			if ( $expected !== $actual ) {
				throw new \RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! $errors_command_wp_bootstrapped ) {
		$errors_command_formats = array();

		function format_items( $format, $items, $fields ) {
			global $errors_command_formats;
			$errors_command_formats[] = compact( 'format', 'items', 'fields' );
		}
	}
}

namespace {
	use ExtraChill\CLI\Commands\Analytics\ErrorsCommand;

	if ( ! $errors_command_wp_bootstrapped ) {
		require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/ErrorsCommand.php';

		global $errors_command_php, $errors_command_rest, $errors_command_formats;

		$errors_command_php->result  = array( 'rows' => array() );
		$errors_command_rest->result = array(
			'total'               => 900,
			'per_day'             => 21600.0,
			'window_hours'        => 1,
			'distinct_signatures' => 1,
			'source'              => 'rest_post_dispatch',
			'note'                => 'lower bound',
			'rows'                => array(
				array(
					'route'         => '/wp-abilities/v1/abilities/(?P<name>[a-zA-Z0-9\-\/]+?)/run [extrachill/resolve-experiment-assignment]',
					'code'          => 'ability_invalid_output',
					'count'         => 900,
					'per_day'       => 21600.0,
					'peak_per_hour' => 900,
				),
			),
		);
		$command                     = new ErrorsCommand();

		// Table default reports both sources, and an empty PHP log does not hide REST 5xx.
		$command( array(), array() );
		errors_command_assert_same( 1, count( $errors_command_php->inputs ), 'Table default must read PHP errors.' );
		errors_command_assert_same( 1, count( $errors_command_rest->inputs ), 'Table default must also read REST 5xx.' );
		errors_command_assert_same( array( 'route', 'code', 'count', 'per_day', 'peak_per_hour' ), $errors_command_formats[0]['fields'], 'REST 5xx renders as its own source.' );
		errors_command_assert_same( 168, $errors_command_rest->inputs[0]['hours'], 'The 7d default maps to the 168h retention.' );

		// json defaults to php only (backward compatible shape).
		$command( array(), array( 'format' => 'json' ) );
		errors_command_assert_same( 1, count( $errors_command_rest->inputs ), 'json without --source keeps the PHP-only shape.' );

		// --source=rest in json emits raw integers; an hour window is honored.
		$command( array(), array(
			'format' => 'json',
			'source' => 'rest',
			'since'  => '1h',
		) );
		$json = end( $errors_command_formats );
		errors_command_assert_same( 900, $json['items'][0]['count'], 'json carries raw integers.' );
		errors_command_assert_same( 1, $errors_command_rest->inputs[1]['hours'], '--since=1h reads one hour.' );
		errors_command_assert_same( 2, count( $errors_command_php->inputs ), '--source=rest does not read PHP errors.' );

		// Mixed shapes in json are refused.
		$refused = false;
		try {
			$command( array(), array(
				'format' => 'csv',
				'source' => 'all',
			) );
		} catch ( \RuntimeException $e ) {
			$refused = 0 === strpos( $e->getMessage(), 'wp-cli-error:' );
		}
		errors_command_assert_same( true, $refused, '--source=all must be table-only.' );

		echo "ErrorsCommandTest passed\n";
	}
}
