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
					'hint'  => 'squarespace — no upcoming array',
					'count' => 8,
				),
				array(
					'hint'  => 'wordpress_generic — no Tribe plugin',
					'count' => 5,
				),
				array(
					'hint'  => 'wix — no events block',
					'count' => 3,
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

	public function test_text_renderer_contains_top_3_fingerprints(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_text( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( 'squarespace — no upcoming array', $body );
		$this->assertStringContainsString( 'wordpress_generic — no Tribe plugin', $body );
		$this->assertStringContainsString( 'wix — no events block', $body );
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
		$this->assertStringContainsString( '<h2>Top extraction_gap fingerprints</h2>', $body );
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
		$this->assertStringContainsString( 'No extraction_gap verdicts this week.', $body );
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
		$this->assertSame( array( QualifyVerdict::UNSUPPORTED_SOURCE, '2026-07-01 00:00:00', '2026-07-08 00:00:00' ), $wpdb->unsupported_args );
		$this->assertStringContainsString( 'COUNT(DISTINCT current_verdict.url_hash)', $wpdb->unsupported_query );
		$this->assertStringContainsString( 'current_verdict.qualified_at <', $wpdb->unsupported_query );
		$this->assertStringContainsString( 'newer_verdict.qualified_at > current_verdict.qualified_at', $wpdb->unsupported_query );
		$this->assertStringContainsString( 'newer_verdict.id > current_verdict.id', $wpdb->unsupported_query );
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
		$this->assertSame(
			array(
				array(
					'hint'  => 'inside gap',
					'count' => 1,
				),
			),
			$data['top_extraction_gap']
		);
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
}
