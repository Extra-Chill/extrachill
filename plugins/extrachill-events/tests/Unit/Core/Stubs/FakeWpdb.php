<?php
/**
 * Minimal $wpdb stub for qualify v2 unit tests.
 *
 * Captures prepare() arguments and returns whatever rows were seeded via
 * seed_rows() / seed_row(). Sufficient for the SELECT-shaped queries our
 * unit-under-test classes issue. NOT a general-purpose mock.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

class FakeWpdb {

	public string $prefix = 'c8c_';

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * @var array<string, mixed>|null
	 */
	private ?array $row = null;

	public array $queries = array();

	public function prepare( string $sql, ...$args ): string {
		// Naive interpolation good enough to keep a record for assertions.
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', is_string( $arg ) ? "'" . $arg . "'" : (string) $arg, $sql, 1 );
		}
		return $sql;
	}

	public function get_results( string $sql, $output = ARRAY_A ): array {
		$this->queries[] = $sql;
		return $this->rows;
	}

	public function get_row( string $sql, $output = ARRAY_A ): ?array {
		$this->queries[] = $sql;
		return $this->row;
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		return null;
	}

	public function seed_rows( array $rows ): void {
		$this->rows = $rows;
	}

	public function seed_row( array $row ): void {
		$this->row = $row;
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
