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
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/QualifyDigestAbilities.php';

class QualifyDigestAbilityTest extends TestCase {

	private function seed_data(): array {
		return array(
			'counts'             => array(
				'paused_total'        => 4,
				'resumed_total'       => 12,
				'new_qualified_total' => 18,
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
	}
}
