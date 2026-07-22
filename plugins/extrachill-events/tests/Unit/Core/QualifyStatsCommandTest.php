<?php
/**
 * Tests for bounded qualify-stats database paging.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Cli\QualifyStatsCommand;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/digest-stubs.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyVerdictsTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Cli/QualifyStatsCommand.php';

/**
 * Covers keyset and page-size constraints on stats reads.
 */
class QualifyStatsCommandTest extends TestCase {

	/** Test snapshot paging honors canonical backfill and equal-local-time ordering. */
	public function test_latest_rows_query_is_snapshot_bounded_and_canonical(): void {
		global $wpdb;
		$GLOBALS['ec_digest_timezone'] = 'America/New_York';

		$original_wpdb = $wpdb ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database test double.
		$wpdb = new class() {
			/**
			 * Database table prefix.
			 *
			 * @var string
			 */
			public string $prefix = 'wp_';
			/**
			 * Captured prepared queries.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $prepared = array();
			/**
			 * Captured snapshot queries.
			 *
			 * @var int
			 */
			public int $snapshot_queries = 0;

			/**
			 * Capture a prepared query and its arguments.
			 *
			 * @param string $query Query template.
			 * @param mixed  ...$args Bound arguments.
			 */
			public function prepare( string $query, ...$args ): string {
				$this->prepared[] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $query;
			}

			/**
			 * Return the canonical row from an equal-local-time plus backfill fixture.
			 *
			 * @param string $query  Prepared query.
			 * @param mixed  $output Requested output format.
			 */
			public function get_results( string $query, $output ): array {
				unset( $query, $output );
				// id 125 wins the DST-equal timestamp tie; later backfill id 126
				// has an older qualified_at and must not replace it.
				return array(
					array(
						'id'          => 125,
						'url'         => 'https://venue.example/events',
						'verdict'     => 'extraction_gap',
						'fingerprint' => '{}',
					),
				);
			}

			/**
			 * Return a stable snapshot upper bound.
			 *
			 * @param string $query Snapshot query.
			 */
			public function get_var( string $query ): int {
				unset( $query );
				++$this->snapshot_queries;
				return 500;
			}
		};

		try {
			$method = new \ReflectionMethod( QualifyStatsCommand::class, 'latest_rows_page' );
			$method->setAccessible( true );
			$rows = $method->invoke( new QualifyStatsCommand(), '2025-01-01 07:00:00', 500, 123, 250 );

			$this->assertSame( 125, $rows[0]['id'] );
			$query = end( $wpdb->prepared );
			$this->assertStringContainsString( 'AND v.id > %d', $query['query'] );
			$this->assertStringContainsString( 'ORDER BY v.id ASC', $query['query'] );
			$this->assertStringContainsString( 'LIMIT %d', $query['query'] );
			$this->assertStringContainsString( 'newer.qualified_at > v.qualified_at', $query['query'] );
			$this->assertStringContainsString( 'newer.qualified_at = v.qualified_at AND newer.id > v.id', $query['query'] );
			$this->assertStringNotContainsString( 'MAX(id) AS max_id', $query['query'] );
			$this->assertStringNotContainsString( 'NOW()', $query['query'] );
			$this->assertSame( array( 500, '2025-01-01 07:00:00', 123, 500, 250 ), $query['args'] );

			$cutoff_method = new \ReflectionMethod( QualifyStatsCommand::class, 'site_local_cutoff' );
			$cutoff_method->setAccessible( true );
			$cutoff = $cutoff_method->invoke( new QualifyStatsCommand(), 30, strtotime( '2025-02-01 12:00:00 UTC' ) );
			$this->assertSame( '2025-01-02 07:00:00', $cutoff );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore original database object.
			$wpdb = $original_wpdb;
			unset( $GLOBALS['ec_digest_timezone'] );
		}
	}
}
