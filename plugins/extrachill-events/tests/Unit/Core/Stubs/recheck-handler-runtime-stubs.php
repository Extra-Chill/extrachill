<?php
/**
 * Runtime-faithful stubs for QualifyRecheckHandlerTest.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core {
	class QualifyRecheckWpdb {
		public string $prefix = 'c8c_';
		public string $options = 'c8c_options';
		public array $updates = array();
		private array $rows = array();
		private ?int $last_prepared_flow_id = null;
		private bool $errors_suppressed = false;

		public function seed_row( int $flow_id, array $row ): void {
			$this->rows[ $flow_id ] = $row;
		}

		public function scheduling_config( int $flow_id ): array {
			$config = json_decode( (string) ( $this->rows[ $flow_id ]['scheduling_config'] ?? '' ), true );
			return is_array( $config ) ? $config : array();
		}

		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) {
				if ( is_numeric( $arg ) ) {
					$this->last_prepared_flow_id = (int) $arg;
					break;
				}
			}
			return $query;
		}

		public function suppress_errors( ?bool $suppress = null ): bool {
			$previous = $this->errors_suppressed;
			if ( null !== $suppress ) {
				$this->errors_suppressed = $suppress;
			}
			return $previous;
		}

		public function get_row( string $query, $output = ARRAY_A ): ?array {
			if ( null === $this->last_prepared_flow_id ) {
				return null;
			}
			$row                         = $this->rows[ $this->last_prepared_flow_id ] ?? null;
			$this->last_prepared_flow_id = null;
			return $row;
		}

		public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
			$flow_id = (int) ( $where['flow_id'] ?? 0 );
			if ( ! isset( $this->rows[ $flow_id ] ) ) {
				return 0;
			}
			$this->rows[ $flow_id ] = array_merge( $this->rows[ $flow_id ], $data );
			$this->updates[]        = array(
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			);
			return 1;
		}
	}
}

namespace {
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

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

	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
			$GLOBALS['ec_test_action_scheduler'][] = compact( 'timestamp', 'hook', 'args', 'group' );
			return 12345;
		}
	}

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
			return 1;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {}
	}
}
