<?php
/**
 * Stubs for FlowOpsResumeTest.
 *
 * Provides:
 *  - A FakeWpdb that records prepare() / get_row() / update() calls and
 *    seeds rows keyed by flow_id (so the SELECT inside
 *    resume_flow_from_qualified() returns the right shape).
 *  - do_action capture into $GLOBALS['ec_test_log_entries'] for the
 *    `datamachine_log` hook so tests can assert structured log entries.
 *  - as_enqueue_async_action capture so we can assert the immediate-run
 *    handoff without depending on Action Scheduler.
 *
 * All function definitions are guarded so this file can coexist with the
 * other Stubs/*.php helpers loaded by the same test suite.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core {

	if ( ! class_exists( __NAMESPACE__ . '\\FlowOpsFakeWpdb' ) ) {
		class FlowOpsFakeWpdb {

			public string $prefix = 'c8c_';

			/**
			 * Rows seeded for SELECT-by-flow-id, keyed by flow_id.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $rows = array();

			/**
			 * Last flow_id parsed out of a prepare() call, used by get_row()
			 * to return the right seeded row.
			 */
			private ?int $last_prepared_flow_id = null;

			/**
			 * Captured update() invocations.
			 *
			 * @var array<int, array{data:array,where:array,format:array,where_format:array}>
			 */
			public array $updates = array();

			public function seed_row( int $flow_id, array $row ): void {
				$this->rows[ $flow_id ] = $row;
			}

			public function prepare( string $sql, ...$args ): string {
				// The only %d we care about is the flow_id used by the
				// resume SELECT. Capture it for the next get_row().
				foreach ( $args as $arg ) {
					if ( is_int( $arg ) ) {
						$this->last_prepared_flow_id = $arg;
						break;
					}
					if ( is_numeric( $arg ) ) {
						$this->last_prepared_flow_id = (int) $arg;
						break;
					}
				}
				return $sql;
			}

			public function get_row( string $sql, $output = ARRAY_A ): ?array {
				if ( null === $this->last_prepared_flow_id ) {
					return null;
				}
				$row                         = $this->rows[ $this->last_prepared_flow_id ] ?? null;
				$this->last_prepared_flow_id = null;
				return $row;
			}

			public function get_var( string $sql ) {
				$row = $this->get_row( $sql );
				return is_array( $row ) ? ( $row['flow_config'] ?? null ) : null;
			}

			public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
				$this->updates[] = array(
					'table'        => $table,
					'data'         => $data,
					'where'        => $where,
					'format'       => is_array( $format ) ? $format : array(),
					'where_format' => is_array( $where_format ) ? $where_format : array(),
				);
				return 1;
			}
		}
	}
}

namespace {
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			if ( 'datamachine_log' === $hook ) {
				$GLOBALS['ec_test_log_entries'][] = array(
					'level'   => (string) ( $args[0] ?? '' ),
					'message' => (string) ( $args[1] ?? '' ),
					'context' => is_array( $args[2] ?? null ) ? $args[2] : array(),
				);
			}
		}
	}

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
			$GLOBALS['ec_test_async_actions'][] = array(
				'hook'  => $hook,
				'args'  => $args,
				'group' => $group,
			);
			return 1;
		}
	}

	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = 0 ) {
			return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value, ...$args ) {
			return $value;
		}
	}
}
