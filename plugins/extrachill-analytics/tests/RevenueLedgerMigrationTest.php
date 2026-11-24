<?php
/**
 * Revenue ledger adapter, legacy migration, parity, guarded drop, and ARC reads.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once __DIR__ . '/class-fake-revenue-ledger.php';
require_once dirname( __DIR__ ) . '/inc/core/content-format-classifier.php';
require_once dirname( __DIR__ ) . '/inc/database/mediavine-revenue-db.php';
require_once dirname( __DIR__ ) . '/inc/database/revenue-ledger.php';
require_once dirname( __DIR__ ) . '/inc/core/mediavine-csv-import.php';
require_once dirname( __DIR__ ) . '/inc/core/revenue-ad-policy.php';
require_once dirname( __DIR__ ) . '/inc/core/revenue-content-attribution.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/ingest-revenue.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/migrate-revenue-ledger.php';
require_once dirname( __DIR__ ) . '/inc/database/class-extrachill-analytics-revenue-store.php';

/**
 * Covers extrachill-analytics consuming the Data Machine Business revenue ledger.
 */
final class RevenueLedgerMigrationTest extends Extrachill_Analytics_TestCase {

	/**
	 * In-memory ledger.
	 *
	 * @var Fake_Revenue_Ledger
	 */
	private $ledger;

	/**
	 * Install the fake ledger abilities.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->ledger = new Fake_Revenue_Ledger();
		$this->ledger->install();
	}

	/**
	 * Remove the fake ledger abilities.
	 */
	public function tear_down(): void {
		$this->ledger->uninstall();
		parent::tear_down();
	}

	/**
	 * A legacy row maps to source-agnostic ledger fields and maps back losslessly.
	 */
	public function test_migration_maps_legacy_row_to_ledger_fields(): void {
		$plan = extrachill_analytics_revenue_migration_plan( array( $this->legacy_row( 'resolved-post', 12.3456, 1500, 42, 7 ) ) );

		$this->assertCount( 1, $plan['snapshots'] );
		$snapshot = reset( $plan['snapshots'] );
		$this->assertSame( 'extrachill:blog-1:mediavine-revenue-2026-05', $snapshot['account'] );
		$row = $snapshot['rows'][0];

		$this->assertSame( 'ad_revenue', $row['kind'] );
		$this->assertSame( '12.345600', $row['amount'] );
		$this->assertSame( 'USD', $row['currency'] );
		$this->assertSame( '2026-05-01', $row['period_start'] );
		$this->assertSame( '2026-05-31', $row['period_end'] );
		$this->assertSame( '/resolved-post/', $row['url'] );
		$this->assertSame( 'post:7:42', $row['object_ref'] );
		$this->assertSame( 7, $row['blog_id'] );
		$this->assertSame( 1500, $row['metrics']['views'] );
		foreach ( array( 'rpm', 'cpm', 'viewability', 'fill_rate', 'impressions_per_pageview' ) as $metric ) {
			$this->assertArrayHasKey( $metric, $row['metrics'] );
		}
		$this->assertSame( 8.23, $row['metrics']['rpm'] );
		$this->assertSame( 'resolved-post', $row['metrics']['slug'] );
		$this->assertSame( '2026-05', $row['metrics']['period_label'] );

		$back = extrachill_analytics_revenue_ledger_record_from_row(
			array_merge(
				$row,
				array(
					'id'      => 9,
					'account' => $snapshot['account'],
				)
			),
			1,
			'mediavine-revenue-2026-05'
		);
		$this->assertSame( 42, $back->post_id );
		$this->assertSame( 7, $back->content_blog_id );
		$this->assertSame( 'resolved-post', $back->slug );
		$this->assertSame( 1500, $back->views );
		$this->assertSame( 12.3456, $back->revenue );
		$this->assertSame( '2026-05', $back->period_label );
		$this->assertSame( 'mediavine-revenue-2026-05', $back->import_batch );

		$unresolved = extrachill_analytics_revenue_ledger_row_from_record( $this->legacy_row( 'old-ghost.html', 1.0, 10 ) );
		$this->assertNull( $unresolved['object_ref'] );
		$this->assertNull( $unresolved['blog_id'] );
	}

	/**
	 * Dry run writes nothing, reports before totals, and parity does not yet hold.
	 */
	public function test_dry_run_writes_nothing_and_reports_per_period_totals(): void {
		$result = extrachill_analytics_revenue_migrate_legacy( array(), $this->legacy_fixture() );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( array(), $this->ledger->rows );
		$this->assertCount( 2, $result['before'] );
		$this->assertSame( array(), $result['after'] );
		$this->assertFalse( $result['parity']['holds'] );
		$this->assertSame( 2, $result['parity']['missing'] );
		$this->assertSame( array( 'would_migrate', 'would_migrate' ), wp_list_pluck( $result['snapshots'], 'status' ) );
		$this->assertSame( 'kept', $result['legacy_table'] );

		$by_label = array_column( $result['before'], null, 'period_label' );
		$this->assertSame( 2, $by_label['2026-05']['rows'] );
		$this->assertSame( 3000, $by_label['2026-05']['views'] );
		$this->assertSame( 15.5, $by_label['2026-05']['revenue'] );
	}

	/**
	 * Execute migrates, parity holds, and a re-run never duplicates or overwrites.
	 */
	public function test_execute_reaches_parity_and_rerun_is_idempotent(): void {
		$result = extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $this->legacy_fixture() );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['dry_run'] );
		$this->assertTrue( $result['parity']['holds'] );
		$this->assertSame( 2, $result['parity']['matched'] );
		$this->assertSame( $result['before'], $result['after'] );
		$this->assertCount( 3, $this->ledger->rows );
		foreach ( $this->ledger->rows as $row ) {
			$this->assertSame( 'mediavine', $row['source'] );
			$this->assertSame( 'ad_revenue', $row['kind'] );
		}

		$again = extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $this->legacy_fixture() );
		$this->assertTrue( $again['parity']['holds'] );
		$this->assertSame( array( 'already_migrated', 'already_migrated' ), wp_list_pluck( $again['snapshots'], 'status' ) );
		$this->assertCount( 3, $this->ledger->rows );
	}

	/**
	 * A ledger snapshot that differs from legacy is a conflict and is never overwritten.
	 */
	public function test_conflicting_ledger_snapshot_is_not_overwritten(): void {
		extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $this->legacy_fixture() );
		$this->ledger->rows[0]['amount'] = '99.000000';

		$result = extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $this->legacy_fixture() );

		$this->assertContains( 'conflict', wp_list_pluck( $result['snapshots'], 'status' ) );
		$this->assertFalse( $result['parity']['holds'] );
		$this->assertSame( 1, $result['parity']['mismatched'] );
		$this->assertSame( '99.000000', $this->ledger->rows[0]['amount'] );
	}

	/**
	 * Undated (all-time) legacy rows are reported, never migrated.
	 */
	public function test_undated_rows_are_reported_not_migrated(): void {
		$legacy   = $this->legacy_fixture();
		$alltime  = $this->legacy_row( 'lifetime', 500.0, 99999 );
		$alltime  = array_merge(
			$alltime,
			array(
				'period_label' => 'all-time',
				'period_start' => null,
				'period_end'   => null,
				'import_batch' => 'flat-file',
			)
		);
		$legacy[] = $alltime;

		$result = extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $legacy );

		$this->assertSame( 1, $result['undated']['rows'] );
		$this->assertSame( array( 'all-time' ), $result['undated']['periods'] );
		$this->assertTrue( $result['parity']['holds'] );
		$this->assertCount( 3, $this->ledger->rows );
	}

	/**
	 * The drop refuses without parity and never calls the dropper.
	 */
	public function test_drop_refuses_without_parity(): void {
		$dropped = false;
		$dropper = static function () use ( &$dropped ) {
			$dropped = true;
			return true;
		};

		$result = extrachill_analytics_revenue_drop_legacy( array( 'execute' => true ), $this->legacy_fixture(), $dropper );

		$this->assertTrue( $result['refused'] );
		$this->assertFalse( $result['dropped'] );
		$this->assertFalse( $result['success'] );
		$this->assertFalse( $dropped );
		$this->assertStringContainsString( 'Parity does not hold', implode( ' ', $result['reasons'] ) );
	}

	/**
	 * The drop is dry-run by default and only drops on execute once parity holds.
	 */
	public function test_drop_is_dry_run_by_default_and_drops_only_with_parity(): void {
		extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $this->legacy_fixture() );
		$calls   = 0;
		$dropper = static function () use ( &$calls ) {
			++$calls;
			return true;
		};

		$dry = extrachill_analytics_revenue_drop_legacy( array(), $this->legacy_fixture(), $dropper );
		$this->assertTrue( $dry['dry_run'] );
		$this->assertTrue( $dry['would_drop'] );
		$this->assertFalse( $dry['dropped'] );
		$this->assertSame( 0, $calls );

		$real = extrachill_analytics_revenue_drop_legacy( array( 'execute' => true ), $this->legacy_fixture(), $dropper );
		$this->assertTrue( $real['success'] );
		$this->assertTrue( $real['dropped'] );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Undated legacy rows block the drop unless explicitly allowed.
	 */
	public function test_drop_refuses_undated_rows_unless_allowed(): void {
		$legacy   = $this->legacy_fixture();
		$legacy[] = array_merge(
			$this->legacy_row( 'lifetime', 500.0, 99999 ),
			array(
				'period_label' => 'all-time',
				'period_start' => null,
				'period_end'   => null,
			)
		);
		extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $legacy );
		$dropper = static function () {
			return true;
		};

		$refused = extrachill_analytics_revenue_drop_legacy( array( 'execute' => true ), $legacy, $dropper );
		$this->assertTrue( $refused['refused'] );
		$this->assertStringContainsString( 'undated', implode( ' ', $refused['reasons'] ) );

		$allowed = extrachill_analytics_revenue_drop_legacy(
			array(
				'execute'       => true,
				'allow_undated' => true,
			),
			$legacy,
			$dropper
		);
		$this->assertTrue( $allowed['dropped'] );
	}

	/**
	 * The revenue ARC reads ledger rows through revenue-query, chronologically.
	 */
	public function test_arc_reads_from_ledger_rows(): void {
		extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $this->legacy_fixture() );
		$this->ledger->calls = array();

		$response = extrachill_analytics_revenue_timeseries_response( 1, false );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( '2026-04', '2026-05' ), wp_list_pluck( $response['series'], 'period' ) );
		$this->assertSame( 1, $response['series'][0]['pages'] );
		$this->assertSame( 500, $response['series'][0]['views'] );
		$this->assertSame( 4.0, $response['series'][0]['revenue'] );
		$this->assertSame( 2, $response['series'][1]['pages'] );
		$this->assertSame( 3000, $response['series'][1]['views'] );
		$this->assertSame( 15.5, $response['series'][1]['revenue'] );
		$this->assertSame( 287.5, $response['series'][1]['mom_pct'] );
		$this->assertSame( 19.5, $response['totals']['revenue'] );
		$this->assertSame( 'datamachine/revenue-query', $response['source']['ability'] );
		$this->assertSame( array(), $response['source']['truncated_snapshots'] );

		$abilities = array_unique( array_column( $this->ledger->calls, 0 ) );
		$this->assertSame( array( 'datamachine/revenue-query' ), array_values( $abilities ) );

		// Other blogs' snapshots never leak into the blog's ARC.
		$this->assertSame( array(), extrachill_analytics_revenue_timeseries_response( 7, false )['series'] );
	}

	/**
	 * Row-level reads keep the legacy scope semantics (period label + batch).
	 */
	public function test_get_rows_scopes_by_period_and_batch(): void {
		extrachill_analytics_revenue_migrate_legacy( array( 'execute' => true ), $this->legacy_fixture() );

		$may = extrachill_analytics_revenue_get_rows(
			array(
				'blog_id'      => 1,
				'period_label' => '2026-05',
			)
		);
		$this->assertCount( 2, $may );
		$this->assertSame( array( 'resolved-post', 'old-ghost.html' ), wp_list_pluck( $may, 'slug' ) );

		$batch = extrachill_analytics_revenue_get_rows(
			array(
				'blog_id'      => 1,
				'import_batch' => 'mediavine-revenue-2026-04',
			)
		);
		$this->assertSame( array( '2026-04' ), array_values( array_unique( wp_list_pluck( $batch, 'period_label' ) ) ) );

		$window = extrachill_analytics_revenue_get_rows(
			array(
				'blog_id'      => 1,
				'period_start' => '2026-04-01',
				'period_end'   => '2026-04-30',
			)
		);
		$this->assertCount( 1, $window );

		$totals = extrachill_analytics_revenue_get_scope_totals( array( 'blog_id' => 1 ) );
		$this->assertSame( 3, $totals->rows_count );
		$this->assertSame( 3500, $totals->views );
		$this->assertEqualsWithDelta( 19.5, $totals->revenue, 0.000001 );
	}

	/**
	 * The production ingest store writes the snapshot through revenue-ingest only.
	 */
	public function test_ingest_store_writes_snapshot_to_ledger(): void {
		$store = new Extrachill_Analytics_Revenue_Store( 'replace', 'mediavine' );
		$this->assertTrue( $store->begin() );
		$this->assertSame( array(), $store->get_snapshot( 1, '2026-06', 'mediavine-abc-2026-06' ) );
		$record = array_merge(
			$this->legacy_row( 'june-post', 3.25, 300, 11, 1 ),
			array(
				'period_label' => '2026-06',
				'period_start' => '2026-06-01',
				'period_end'   => '2026-06-30',
				'import_batch' => 'mediavine-abc-2026-06',
			)
		);
		$this->assertTrue( $store->upsert( $record ) );
		$this->assertTrue( $store->commit() );

		$this->assertCount( 1, $this->ledger->rows );
		$this->assertSame( 'extrachill:blog-1:mediavine-abc-2026-06', $this->ledger->rows[0]['account'] );
		$this->assertSame( 'post:1:11', $this->ledger->rows[0]['object_ref'] );

		// Re-writing the snapshot replaces it in place.
		$store->begin();
		$existing = $store->get_snapshot( 1, '2026-06', 'mediavine-abc-2026-06' );
		$this->assertCount( 1, $existing );
		$store->upsert( array_merge( $record, array( 'revenue' => 5.0 ) ) );
		$store->delete_ids( array() );
		$this->assertTrue( $store->commit() );
		$this->assertCount( 1, $this->ledger->rows );
		$this->assertSame( '5.000000', $this->ledger->rows[0]['amount'] );

		// Undated snapshots fail closed: the ledger only stores dated windows.
		$store->begin();
		$store->upsert(
			array_merge(
				$record,
				array(
					'period_start' => '',
					'period_end'   => '',
				)
			)
		);
		$this->assertFalse( $store->commit() );
		$this->assertStringContainsString( 'dated period', $store->last_error() );
	}

	/**
	 * Legacy fixture: two May rows (one resolved, one unresolved) and one April row.
	 *
	 * @return array<int, array>
	 */
	private function legacy_fixture(): array {
		$april = array_merge(
			$this->legacy_row( 'april-post', 4.0, 500, 5, 1 ),
			array(
				'id'           => 3,
				'period_label' => '2026-04',
				'period_start' => '2026-04-01',
				'period_end'   => '2026-04-30',
				'import_batch' => 'mediavine-revenue-2026-04',
			)
		);
		return array(
			array_merge( $this->legacy_row( 'resolved-post', 12.5, 1500, 42, 7 ), array( 'id' => 1 ) ),
			array_merge( $this->legacy_row( 'old-ghost.html', 3.0, 1500 ), array( 'id' => 2 ) ),
			$april,
		);
	}

	/**
	 * Build one legacy table row (as $wpdb ARRAY_A would return it).
	 *
	 * @param string $slug            Slug.
	 * @param float  $revenue         Revenue.
	 * @param int    $views           Views.
	 * @param int    $post_id         Resolved post id (0 = unresolved).
	 * @param int    $content_blog_id Owning content blog.
	 * @return array
	 */
	private function legacy_row( string $slug, float $revenue, int $views, int $post_id = 0, int $content_blog_id = 0 ): array {
		return array(
			'id'                       => 1,
			'blog_id'                  => '1',
			'slug'                     => $slug,
			'url'                      => '/' . $slug . '/',
			'post_id'                  => $post_id > 0 ? (string) $post_id : null,
			'content_blog_id'          => $content_blog_id > 0 ? (string) $content_blog_id : null,
			'canonical_url'            => $post_id > 0 ? 'https://extrachill.com/' . $slug . '/' : '',
			'views'                    => (string) $views,
			'revenue'                  => (string) $revenue,
			'rpm'                      => '8.2300',
			'cpm'                      => '2.1000',
			'viewability'              => '71.5000',
			'fill_rate'                => '93.0000',
			'impressions_per_pageview' => '3.4000',
			'period_label'             => '2026-05',
			'period_start'             => '2026-05-01',
			'period_end'               => '2026-05-31',
			'import_batch'             => 'mediavine-revenue-2026-05',
			'imported_at'              => '2026-06-02 00:00:00',
		);
	}
}
