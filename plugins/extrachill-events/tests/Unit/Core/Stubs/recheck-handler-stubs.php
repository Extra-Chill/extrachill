<?php
/**
 * Stubs for QualifyRecheckHandlerTest.
 *
 * Defines a fake ExtraChillEvents\Cli\FlowOps before the real one loads
 * so the handler's static calls hit instrumented methods. Also defines
 * the WP / Action Scheduler / abilities helpers the handler relies on.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Cli {

	if ( ! class_exists( __NAMESPACE__ . '\\FlowOps' ) ) {
		class FlowOps {

			public static function fetch_flow_row( int $flow_id ): ?array {
				return $GLOBALS['ec_test_flow_rows'][ $flow_id ] ?? null;
			}

			public static function resume_flow_from_qualified( int $flow_id, array $result ): bool {
				$GLOBALS['ec_test_flowops_calls'][] = array(
					'method' => 'resume_flow_from_qualified',
					'args'   => array( $flow_id, $result ),
				);
				return true;
			}

			public static function update_paused_reason( int $flow_id, string $verdict ): bool {
				$GLOBALS['ec_test_flowops_calls'][] = array(
					'method' => 'update_paused_reason',
					'args'   => array( $flow_id, $verdict ),
				);
				return true;
			}

			public static function flag_stale_paused( int $flow_id, string $verdict, int $consecutive_failures ): bool {
				$GLOBALS['ec_test_flowops_calls'][] = array(
					'method' => 'flag_stale_paused',
					'args'   => array( $flow_id, $verdict, $consecutive_failures ),
				);
				return true;
			}

			public static function set_recheck_metadata( int $flow_id, int $action_id, int $next_run_ts ): bool {
				$GLOBALS['ec_test_flowops_calls'][] = array(
					'method' => 'set_recheck_metadata',
					'args'   => array( $flow_id, $action_id, $next_run_ts ),
				);
				return true;
			}

			public static function pause_flow_by_verdict( int $flow_id, string $verdict, string $source_url = '' ): bool {
				$GLOBALS['ec_test_flowops_calls'][] = array(
					'method' => 'pause_flow_by_verdict',
					'args'   => array( $flow_id, $verdict, $source_url ),
				);
				return true;
			}
		}
	}
}

namespace {
	// WP / abilities / Action Scheduler stubs in the global namespace.

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) {
			return new class() {
				public function execute( array $input ) {
					return $GLOBALS['ec_test_ability_result'];
				}
			};
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $message;
			public function __construct( string $code = '', string $message = '' ) {
				$this->message = $message;
			}
			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
			$GLOBALS['ec_test_action_scheduler'][] = array(
				'timestamp' => $timestamp,
				'hook'      => $hook,
				'args'      => $args,
				'group'     => $group,
			);
			return 12345;
		}
	}

	if ( ! function_exists( 'as_unschedule_action' ) ) {
		function as_unschedule_action( string $hook, $args = null, string $group = '' ): void {}
	}

	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {}
	}

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
			return 1;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	}

	if ( ! function_exists( 'get_site_option' ) ) {
		function get_site_option( string $key, $default = false ) {
			return $default;
		}
	}
}
