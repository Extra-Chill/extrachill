<?php
/**
 * Database stub for qualify digest latest-verdict tests.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyVerdict;

/**
 * Emulates canonical latest-row selection independently from production SQL.
 */
class QualifyDigestWpdbStub {

	/**
	 * WordPress site table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'c8c_';

	/**
	 * Prepared arguments for the unsupported query.
	 *
	 * @var array<int,mixed>
	 */
	public array $unsupported_args = array();

	/**
	 * Captured unsupported query.
	 *
	 * @var string
	 */
	public string $unsupported_query = '';

	/**
	 * Captured newly-qualified query.
	 *
	 * @var string
	 */
	public string $qualified_query = '';

	/**
	 * Captured extraction-gap query.
	 *
	 * @var string
	 */
	public string $gap_query = '';

	/**
	 * Seeded verdict history.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $verdict_rows;

	/**
	 * Seeded flow rows.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $flow_rows;

	/**
	 * Arguments keyed by prepared SQL.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private array $prepared_args = array();

	/**
	 * Seed verdict history.
	 *
	 * @param array<int,array<string,mixed>> $verdict_rows Seeded verdict rows.
	 * @param array<int,array<string,mixed>> $flow_rows    Seeded flow rows.
	 */
	public function __construct( array $verdict_rows, array $flow_rows = array() ) {
		$this->verdict_rows = $verdict_rows;
		$this->flow_rows    = $flow_rows;
	}

	/**
	 * Naively interpolate placeholders and retain their original arguments.
	 *
	 * @param string $sql  SQL template.
	 * @param mixed  ...$args Placeholder values.
	 * @return string Interpolated SQL.
	 */
	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', is_string( $arg ) ? "'{$arg}'" : (string) $arg, $sql, 1 );
		}
		$this->prepared_args[ $sql ] = $args;
		return $sql;
	}

	/**
	 * Return seeded flow rows and grouped extraction-gap rows.
	 *
	 * @param string $sql    SQL query.
	 * @param mixed  $output Output mode.
	 * @return array Empty rows.
	 */
	public function get_results( string $sql, $output = ARRAY_A ): array {
		unset( $output );
		if ( false !== strpos( $sql, 'SELECT flow_id, flow_name, scheduling_config' ) ) {
			return $this->flow_rows;
		}
		if ( false !== strpos( $sql, 'SELECT v.id, v.url' ) ) {
			$this->gap_query = $sql;
			$args            = $this->prepared_args[ $sql ];
			$latest          = array();
			foreach ( $this->verdict_rows as $row ) {
				if ( $row['id'] > $args[0] ) {
					continue;
				}
				$hash = $row['url_hash'];
				if ( ! isset( $latest[ $hash ] )
					|| $row['qualified_at'] > $latest[ $hash ]['qualified_at']
					|| ( $row['qualified_at'] === $latest[ $hash ]['qualified_at'] && $row['id'] > $latest[ $hash ]['id'] ) ) {
					$latest[ $hash ] = $row;
				}
			}

			$rows = array_values(
				array_filter(
					$latest,
					static function ( array $row ) use ( $args ): bool {
						return in_array( $row['verdict'], array( $args[1], $args[2] ), true )
							&& $row['qualified_at'] >= $args[3]
							&& $row['qualified_at'] < $args[4]
							&& $row['id'] > $args[5];
					}
				)
			);
			usort( $rows, static fn( array $left, array $right ): int => $left['id'] <=> $right['id'] );
			$rows = array_slice( $rows, 0, $args[7] );
			return array_map(
				static function ( array $row ): array {
					return array(
						'id'          => $row['id'],
						'url'         => $row['url'] ?? 'https://' . $row['url_hash'] . '.example/events',
						'verdict'     => $row['verdict'],
						'fingerprint' => $row['fingerprint'] ?? '{}',
					);
				},
				$rows
			);
		}
		if ( false !== strpos( $sql, "verdict = '" . QualifyVerdict::EXTRACTION_GAP . "'" ) ) {
			$this->gap_query = $sql;
			$args            = $this->prepared_args[ $sql ];
			$counts          = array();
			foreach ( $this->verdict_rows as $row ) {
				if ( QualifyVerdict::EXTRACTION_GAP === $row['verdict']
					&& $row['qualified_at'] >= $args[1]
					&& $row['qualified_at'] < $args[2] ) {
					$hint            = $row['improvement_hint'] ?? '';
					$counts[ $hint ] = ( $counts[ $hint ] ?? 0 ) + 1;
				}
			}
			$rows = array();
			foreach ( $counts as $hint => $count ) {
				$rows[] = array(
					'improvement_hint' => $hint,
					'c'                => $count,
				);
			}
			return $rows;
		}
		return array();
	}

	/**
	 * Resolve table checks and emulate the latest-verdict count contract.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int|string Query result.
	 */
	public function get_var( string $sql ) {
		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			return $this->prefix . 'dme_qualify_verdicts';
		}
		if ( false !== strpos( $sql, 'COALESCE(MAX(id), 0)' ) ) {
			return empty( $this->verdict_rows ) ? 0 : max( array_column( $this->verdict_rows, 'id' ) );
		}
		if ( false !== strpos( $sql, "verdict = '" . QualifyVerdict::QUALIFIED_STRUCTURED . "'" ) ) {
			$this->qualified_query = $sql;
			$args                  = $this->prepared_args[ $sql ];
			$count                 = 0;
			foreach ( $this->verdict_rows as $row ) {
				if ( QualifyVerdict::QUALIFIED_STRUCTURED === $row['verdict']
					&& $row['id'] <= $args[0]
					&& $row['qualified_at'] >= $args[2]
					&& $row['qualified_at'] < $args[3] ) {
					++$count;
				}
			}
			return $count;
		}
		if ( false === strpos( $sql, "current_verdict.verdict = '" . QualifyVerdict::UNSUPPORTED_SOURCE . "'" ) ) {
			return 0;
		}

		$this->unsupported_query = $sql;
		$args                    = $this->prepared_args[ $sql ];
		$this->unsupported_args  = $args;
		$latest                  = array();
		foreach ( $this->verdict_rows as $row ) {
			if ( isset( $args[3] ) && $row['id'] > $args[3] ) {
				continue;
			}
			$hash = $row['url_hash'];
			if ( ! isset( $latest[ $hash ] )
				|| $row['qualified_at'] > $latest[ $hash ]['qualified_at']
				|| ( $row['qualified_at'] === $latest[ $hash ]['qualified_at'] && $row['id'] > $latest[ $hash ]['id'] ) ) {
				$latest[ $hash ] = $row;
			}
		}

		$count = 0;
		foreach ( $latest as $row ) {
			if ( QualifyVerdict::UNSUPPORTED_SOURCE === $row['verdict']
				&& $row['qualified_at'] >= $args[1]
				&& $row['qualified_at'] < $args[2] ) {
				++$count;
			}
		}
		return $count;
	}
}
