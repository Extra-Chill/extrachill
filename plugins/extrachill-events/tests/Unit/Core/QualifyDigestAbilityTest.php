<?php
/**
 * Tests for QualifyDigestAbilities renderers.
 *
 * Renderers are pure: in goes the gather_data() shape, out comes a string.
 * Tests feed seeded data structures and assert the rendered output
 * contains the expected sections and counts.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Abilities\QualifyDigestAbilities;
use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/digest-stubs.php';
require_once __DIR__ . '/Stubs/class-qualifydigestwpdbstub.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyVerdictsTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/QualifyDigestAbilities.php';

class QualifyDigestAbilityTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['ec_digest_timezone'] );
		parent::tearDown();
	}

	private function seed_data(): array {
		return array(
			'counts'             => array(
				'paused_total'        => 4,
				'resumed_total'       => 12,
				'new_qualified_total' => 18,
				'unsupported_total'   => 9,
				'stale_total'         => 1,
			),
			'paused_by_verdict'  => array(
				QualifyVerdict::EXTRACTION_GAP => 3,
				QualifyVerdict::BOT_BLOCKED    => 1,
			),
			'standing_inventory' => array(
				'active:daily'                             => 147,
				'paused:' . QualifyVerdict::EXTRACTION_GAP => 22,
				'paused:' . QualifyVerdict::BOT_BLOCKED    => 4,
				'paused:' . QualifyVerdict::COVERED_ELSEWHERE => 2,
			),
			'top_extraction_gap' => array(
				array(
					'category'            => 'extractor',
					'platform'            => 'squarespace',
					'structured_signal'   => 'none',
					'page_shape'          => 'listing',
					'extractor'           => 'SquarespaceExtractor',
					'reason'              => 'attempt_failed',
					'count'               => 8,
					'representative_urls' => array( 'https://venue-one.example/events' ),
				),
				array(
					'category'            => 'extractor',
					'platform'            => 'wordpress_generic',
					'structured_signal'   => 'jsonld',
					'page_shape'          => 'listing',
					'extractor'           => 'JsonLdExtractor',
					'reason'              => 'attempt_failed',
					'count'               => 5,
					'representative_urls' => array( 'https://venue-two.example/calendar' ),
				),
				array(
					'category'            => 'non_actionable',
					'platform'            => 'facebook.com',
					'structured_signal'   => 'none',
					'page_shape'          => 'unknown',
					'extractor'           => 'none',
					'reason'              => 'login_wall',
					'count'               => 3,
					'representative_urls' => array( 'https://facebook.com/venue' ),
				),
			),
			'stale_flows'        => array(
				array(
					'flow_id'              => 99,
					'flow_name'            => 'Broken Venue',
					'paused_reason'        => QualifyVerdict::BOT_BLOCKED,
					'consecutive_failures' => 6,
				),
			),
		);
	}

	public function test_text_renderer_contains_summary_counts(): void {
		$ability = new QualifyDigestAbilities();
		$data    = $this->seed_data();
		$body    = $ability->render_text( $data, strtotime( '2025-05-18 00:00:00' ), strtotime( '2025-05-24 00:00:00' ) );

		$this->assertStringContainsString( 'EVENT CALENDAR QUALIFY DIGEST', $body );
		$this->assertStringContainsString( 'Paused this week:        4 flows', $body );
		$this->assertStringContainsString( 'Auto-resumed this week:  12 flows', $body );
		$this->assertStringContainsString( 'New venues qualified:    18', $body );
		$this->assertStringContainsString( 'Unsupported sources:     9', $body );
		$this->assertStringContainsString( 'Stale paused flows:      1', $body );
	}

	public function test_text_renderer_contains_ranked_cohorts_and_urls(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_text( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( 'extractor: platform=squarespace, signal=none, shape=listing, extractor=SquarespaceExtractor, reason=attempt_failed', $body );
		$this->assertStringContainsString( 'extractor: platform=wordpress_generic, signal=jsonld, shape=listing, extractor=JsonLdExtractor, reason=attempt_failed', $body );
		$this->assertStringContainsString( 'non_actionable: platform=facebook.com, signal=none, shape=unknown, extractor=none, reason=login_wall', $body );
		$this->assertStringContainsString( 'https://venue-one.example/events', $body );
	}

	public function test_text_renderer_surfaces_stale_flows(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_text( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( 'Stale paused flows — manual review recommended', $body );
		$this->assertStringContainsString( 'Broken Venue', $body );
		$this->assertStringContainsString( '6 failures', $body );
	}

	public function test_html_renderer_contains_all_sections(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_html( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( '<!DOCTYPE html>', $body );
		$this->assertStringContainsString( 'Event Calendar Qualify Digest', $body );
		$this->assertStringContainsString( '<h2>Summary</h2>', $body );
		$this->assertStringContainsString( '<h2>Paused this week — by verdict</h2>', $body );
		$this->assertStringContainsString( '<h2>Standing inventory</h2>', $body );
		$this->assertStringContainsString( '<h2>Top qualification remediation cohorts</h2>', $body );
		$this->assertStringContainsString( '<h2>Stale paused flows — manual review recommended</h2>', $body );
	}

	public function test_html_renderer_renders_zero_state_when_nothing_changed(): void {
		$ability = new QualifyDigestAbilities();
		$empty   = array(
			'counts'             => array(
				'paused_total'        => 0,
				'resumed_total'       => 0,
				'new_qualified_total' => 0,
				'unsupported_total'   => 0,
				'stale_total'         => 0,
			),
			'paused_by_verdict'  => array(),
			'standing_inventory' => array(),
			'top_extraction_gap' => array(),
			'stale_flows'        => array(),
		);
		$body    = $ability->render_html( $empty, time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( 'Nothing paused this week.', $body );
		$this->assertStringContainsString( 'No universal_web_scraper flows registered.', $body );
		$this->assertStringContainsString( 'No remediation verdicts this week.', $body );
		// Stale section must be absent when there are no stale flows.
		$this->assertStringNotContainsString( '<h2>Stale paused flows', $body );
	}

	public function test_html_renderer_renders_counts(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_html( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( '147', $body, 'standing inventory active:daily count' );
		$this->assertStringContainsString( '22', $body, 'paused extraction_gap count' );
		$this->assertStringContainsString( '18', $body, 'new qualified total' );
		$this->assertStringContainsString( '9', $body, 'unsupported source total' );
	}

	/**
	 * Digest boundaries use site-local time and canonical latest-row ordering.
	 */
	public function test_digest_uses_canonical_latest_unsupported_rows_in_local_half_open_window(): void {
		$start                         = strtotime( '2026-07-01 04:00:00 UTC' );
		$end                           = strtotime( '2026-07-08 04:00:00 UTC' );
		$GLOBALS['ec_digest_timezone'] = 'America/New_York';

		$wpdb            = new QualifyDigestWpdbStub(
			array(
				array(
					'id'           => 1,
					'url_hash'     => 'repeat',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-02 00:00:00',
				),
				array(
					'id'           => 2,
					'url_hash'     => 'repeat',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-03 00:00:00',
				),
				array(
					'id'           => 3,
					'url_hash'     => 'recovered',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-04 00:00:00',
				),
				array(
					'id'           => 4,
					'url_hash'     => 'recovered',
					'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
					'qualified_at' => '2026-07-05 00:00:00',
				),
				array(
					'id'           => 100,
					'url_hash'     => 'backfill',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-02 00:00:00',
				),
				array(
					'id'           => 5,
					'url_hash'     => 'backfill',
					'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
					'qualified_at' => '2026-07-06 00:00:00',
				),
				array(
					'id'           => 10,
					'url_hash'     => 'timestamp-tie',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-06 12:00:00',
				),
				array(
					'id'           => 11,
					'url_hash'     => 'timestamp-tie',
					'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
					'qualified_at' => '2026-07-06 12:00:00',
				),
				array(
					'id'           => 12,
					'url_hash'     => 'end-boundary',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-08 00:00:00',
				),
				array(
					'id'           => 6,
					'url_hash'     => 'current',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-07 00:00:00',
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test installs an isolated database stub.

		$data = ( new QualifyDigestAbilities() )->gather_data( $start, $end );

		$this->assertSame( 2, $data['counts']['unsupported_total'] );
		$this->assertSame( array( QualifyVerdict::UNSUPPORTED_SOURCE, '2026-07-01 00:00:00', '2026-07-08 00:00:00', 100, 100 ), $wpdb->unsupported_args );
		$this->assertStringContainsString( 'COUNT(DISTINCT current_verdict.url_hash)', $wpdb->unsupported_query );
		$this->assertStringContainsString( 'current_verdict.qualified_at <', $wpdb->unsupported_query );
		$this->assertStringContainsString( 'newer_verdict.qualified_at > current_verdict.qualified_at', $wpdb->unsupported_query );
		$this->assertStringContainsString( 'newer_verdict.id > current_verdict.id', $wpdb->unsupported_query );
		$this->assertSame( 2, substr_count( $wpdb->unsupported_query, 'id <= 100' ) );
		$this->assertStringNotContainsString( 'MAX(id)', $wpdb->unsupported_query );
	}

	/**
	 * Every digest event section uses the same site-local half-open window.
	 */
	public function test_all_digest_sections_exclude_exact_end_boundary_in_site_timezone(): void {
		$GLOBALS['ec_digest_timezone'] = 'America/New_York';
		$verdict_rows                  = array(
			array(
				'id'           => 1,
				'url_hash'     => 'qualified-start',
				'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
				'qualified_at' => '2026-07-01 00:00:00',
			),
			array(
				'id'           => 2,
				'url_hash'     => 'qualified-end',
				'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
				'qualified_at' => '2026-07-08 00:00:00',
			),
			array(
				'id'               => 3,
				'url_hash'         => 'gap-start',
				'verdict'          => QualifyVerdict::EXTRACTION_GAP,
				'qualified_at'     => '2026-07-01 00:00:00',
				'improvement_hint' => 'inside gap',
			),
			array(
				'id'               => 4,
				'url_hash'         => 'gap-end',
				'verdict'          => QualifyVerdict::EXTRACTION_GAP,
				'qualified_at'     => '2026-07-08 00:00:00',
				'improvement_hint' => 'excluded gap',
			),
			array(
				'id'           => 5,
				'url_hash'     => 'unsupported-start',
				'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
				'qualified_at' => '2026-07-01 00:00:00',
			),
			array(
				'id'           => 6,
				'url_hash'     => 'unsupported-end',
				'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
				'qualified_at' => '2026-07-08 00:00:00',
			),
		);
		$flow_rows                     = array(
			array(
				'flow_id'           => 1,
				'flow_name'         => 'Paused Start',
				'scheduling_config' => wp_json_encode(
					array(
						'paused_at'     => '2026-07-01 00:00:00',
						'paused_reason' => QualifyVerdict::EXTRACTION_GAP,
					)
				),
			),
			array(
				'flow_id'           => 2,
				'flow_name'         => 'Paused End',
				'scheduling_config' => wp_json_encode(
					array(
						'paused_at'     => '2026-07-08 00:00:00',
						'paused_reason' => QualifyVerdict::UNSUPPORTED_SOURCE,
					)
				),
			),
			array(
				'flow_id'           => 3,
				'flow_name'         => 'Resumed Start',
				'scheduling_config' => wp_json_encode(
					array(
						'resumed_at'         => '2026-07-01 00:00:00',
						'resumed_by_qualify' => true,
					)
				),
			),
			array(
				'flow_id'           => 4,
				'flow_name'         => 'Resumed End',
				'scheduling_config' => wp_json_encode(
					array(
						'resumed_at'         => '2026-07-08 00:00:00',
						'resumed_by_qualify' => true,
					)
				),
			),
		);
		$wpdb                          = new QualifyDigestWpdbStub( $verdict_rows, $flow_rows );
		$GLOBALS['wpdb']               = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test installs an isolated database stub.

		$data = ( new QualifyDigestAbilities() )->gather_data(
			strtotime( '2026-07-01 04:00:00 UTC' ),
			strtotime( '2026-07-08 04:00:00 UTC' )
		);

		$this->assertSame( array( QualifyVerdict::EXTRACTION_GAP => 1 ), $data['paused_by_verdict'] );
		$this->assertSame( 1, $data['counts']['resumed_total'] );
		$this->assertSame( 1, $data['counts']['new_qualified_total'] );
		$this->assertSame( 1, $data['counts']['unsupported_total'] );
		$representative_urls = array_merge( ...array_column( $data['top_extraction_gap'], 'representative_urls' ) );
		$this->assertCount( 2, $data['top_extraction_gap'] );
		$this->assertContains( 'https://gap-start.example/events', $representative_urls );
		$this->assertContains( 'https://unsupported-start.example/events', $representative_urls );
		$this->assertNotContains( 'https://gap-end.example/events', $representative_urls );
		$this->assertNotContains( 'https://unsupported-end.example/events', $representative_urls );
		$this->assertStringContainsString( 'qualified_at <', $wpdb->qualified_query );
		$this->assertStringNotContainsString( 'qualified_at <=', $wpdb->qualified_query );
		$this->assertStringContainsString( 'qualified_at <', $wpdb->gap_query );
		$this->assertStringNotContainsString( 'qualified_at <=', $wpdb->gap_query );
	}

	/**
	 * Verify each edge case in the canonical latest unsupported contract.
	 *
	 * @param array<int,array<string,mixed>> $rows     Verdict history.
	 * @param int                            $expected Expected URL count.
	 *
	 * @dataProvider latestUnsupportedContractProvider
	 */
	public function test_latest_unsupported_count_contract( array $rows, int $expected ): void {
		$GLOBALS['wpdb'] = new QualifyDigestWpdbStub( $rows ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test installs an isolated database stub.

		$data = ( new QualifyDigestAbilities() )->gather_data(
			strtotime( '2026-07-01 00:00:00 UTC' ),
			strtotime( '2026-07-08 00:00:00 UTC' )
		);

		$this->assertSame( $expected, $data['counts']['unsupported_total'] );
	}

	/**
	 * Edge cases required by the latest unsupported contract.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>>,1:int}>
	 */
	public static function latestUnsupportedContractProvider(): array {
		return array(
			'backfilled higher id does not override newer timestamp' => array(
				array(
					array(
						'id'           => 100,
						'url_hash'     => 'backfill',
						'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
						'qualified_at' => '2026-07-02 00:00:00',
					),
					array(
						'id'           => 5,
						'url_hash'     => 'backfill',
						'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
						'qualified_at' => '2026-07-03 00:00:00',
					),
				),
				0,
			),
			'timestamp tie uses higher id'    => array(
				array(
					array(
						'id'           => 10,
						'url_hash'     => 'tie',
						'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
						'qualified_at' => '2026-07-03 00:00:00',
					),
					array(
						'id'           => 11,
						'url_hash'     => 'tie',
						'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
						'qualified_at' => '2026-07-03 00:00:00',
					),
				),
				0,
			),
			'end boundary is excluded'        => array(
				array(
					array(
						'id'           => 1,
						'url_hash'     => 'boundary',
						'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
						'qualified_at' => '2026-07-08 00:00:00',
					),
				),
				0,
			),
			'recovered URL is excluded'       => array(
				array(
					array(
						'id'           => 1,
						'url_hash'     => 'recovered',
						'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
						'qualified_at' => '2026-07-02 00:00:00',
					),
					array(
						'id'           => 2,
						'url_hash'     => 'recovered',
						'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
						'qualified_at' => '2026-07-04 00:00:00',
					),
				),
				0,
			),
			'repeated rechecks count one URL' => array(
				array(
					array(
						'id'           => 1,
						'url_hash'     => 'repeat',
						'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
						'qualified_at' => '2026-07-02 00:00:00',
					),
					array(
						'id'           => 2,
						'url_hash'     => 'repeat',
						'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
						'qualified_at' => '2026-07-03 00:00:00',
					),
					array(
						'id'           => 3,
						'url_hash'     => 'repeat',
						'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
						'qualified_at' => '2026-07-04 00:00:00',
					),
				),
				1,
			),
		);
	}

	/** Test digest keyset paging and site-local half-open verdict bounds. */
	public function test_gather_data_pages_latest_rows_with_local_half_open_bounds(): void {
		global $wpdb;
		$GLOBALS['ec_digest_timezone'] = 'America/New_York';
		$original_wpdb                 = $wpdb ?? null;
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
			 * Number of cohort page queries.
			 *
			 * @var int
			 */
			public int $cohort_queries = 0;
			/**
			 * Number of snapshot-bound queries.
			 *
			 * @var int
			 */
			public int $snapshot_queries = 0;
			/**
			 * Simulated live maximum id, advanced after page one.
			 *
			 * @var int
			 */
			public int $live_max_id = 251;

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
			 * Simulate table existence and count queries.
			 *
			 * @param string $query Prepared query.
			 */
			public function get_var( string $query ) {
				if ( 0 === strpos( $query, 'SHOW TABLES' ) ) {
					return 'wp_dme_qualify_verdicts';
				}
				if ( false !== strpos( $query, 'COALESCE(MAX(id), 0)' ) ) {
					++$this->snapshot_queries;
					return $this->live_max_id;
				}
				return 0;
			}

			/**
			 * Return two bounded cohort pages.
			 *
			 * @param string $query  Prepared query.
			 * @param mixed  $output Requested output format.
			 */
			public function get_results( string $query, $output ): array {
				unset( $output );
				if ( false !== strpos( $query, 'SELECT flow_id' ) ) {
					return array(
						array(
							'flow_id'           => 1,
							'flow_name'         => 'Before boundary',
							'scheduling_config' => wp_json_encode(
								array(
									'paused_at'     => '2025-01-08 06:59:59',
									'paused_reason' => QualifyVerdict::EXTRACTION_GAP,
								)
							),
						),
						array(
							'flow_id'           => 2,
							'flow_name'         => 'Exact boundary',
							'scheduling_config' => wp_json_encode(
								array(
									'paused_at'     => '2025-01-08 07:00:00',
									'paused_reason' => QualifyVerdict::EXTRACTION_GAP,
								)
							),
						),
					);
				}
				if ( false === strpos( $query, 'SELECT v.id, v.url' ) ) {
					return array();
				}

				++$this->cohort_queries;
				if ( 1 === $this->cohort_queries ) {
					$this->live_max_id = 1000;
				}
				$start = 1 === $this->cohort_queries ? 1 : 251;
				$count = 1 === $this->cohort_queries ? 250 : 1;
				$rows  = array();
				for ( $id = $start; $id < $start + $count; ++$id ) {
					$rows[] = array(
						'id'          => $id,
						'url'         => 'https://venue-' . $id . '.example/events',
						'verdict'     => QualifyVerdict::EXTRACTION_GAP,
						'fingerprint' => wp_json_encode( array( 'http_status' => 200 ) ),
					);
				}
				return $rows;
			}
		};

		try {
			$ability = new QualifyDigestAbilities();
			$data    = $ability->gather_data( strtotime( '2025-01-01 12:00:00 UTC' ), strtotime( '2025-01-08 12:00:00 UTC' ) );

			$this->assertSame( 2, $wpdb->cohort_queries );
			$this->assertSame( 1, $wpdb->snapshot_queries );
			$this->assertSame( 1000, $wpdb->live_max_id, 'Fixture must append rows after the snapshot.' );
			$this->assertSame( 251, $data['top_extraction_gap'][0]['count'] );
			$this->assertCount( 3, $data['top_extraction_gap'][0]['representative_urls'] );
			$this->assertSame( 1, $data['counts']['paused_total'], 'Exact end boundary must be excluded.' );

			$verdict_queries = array_values(
				array_filter(
					$wpdb->prepared,
					static fn( array $prepared ): bool => false !== strpos( $prepared['query'], 'qualified_at >=' )
				)
			);
			$this->assertNotEmpty( $verdict_queries );
			foreach ( $verdict_queries as $prepared ) {
				$this->assertStringContainsString( 'qualified_at < %s', $prepared['query'] );
				$this->assertStringNotContainsString( 'qualified_at <=', $prepared['query'] );
				$this->assertNotContains( '2025-01-08 12:00:00', $prepared['args'] );
			}
			$this->assertContains( '2025-01-01 07:00:00', $verdict_queries[0]['args'] );
			$this->assertContains( '2025-01-08 07:00:00', $verdict_queries[0]['args'] );

			$cohort_queries = array_values(
				array_filter(
					$wpdb->prepared,
					static fn( array $prepared ): bool => false !== strpos( $prepared['query'], 'SELECT v.id, v.url' )
				)
			);
			$this->assertCount( 2, $cohort_queries );
			foreach ( $cohort_queries as $prepared ) {
				$this->assertStringContainsString( 'v.id <= %d', $prepared['query'] );
				$this->assertStringContainsString( 'newer.qualified_at > v.qualified_at', $prepared['query'] );
				$this->assertStringContainsString( 'newer.qualified_at = v.qualified_at AND newer.id > v.id', $prepared['query'] );
				$this->assertSame( 251, $prepared['args'][0] );
				$this->assertSame( 251, $prepared['args'][6] );
			}
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore original database object.
			$wpdb = $original_wpdb;
		}
	}
}
