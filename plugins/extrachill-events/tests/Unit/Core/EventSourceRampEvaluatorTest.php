<?php
/**
 * Event source ramp policy tests.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Abilities\EventSourceRampAbilities;
use ExtraChillEvents\Core\EventSourceRampEvaluator;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/EventSourceRampEvaluator.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventSourceRampAbilities.php';

/** Covers source profiles, gate decisions, schema, and mutation safety. */
class EventSourceRampEvaluatorTest extends TestCase {

	private const NOW = 1785164400; // 2026-07-27T15:00:00Z.

	/** Confirm each source uses the policy stages and owning handler. */
	public function test_source_profiles_are_explicit(): void {
		$profiles = EventSourceRampEvaluator::profiles();

		$this->assertSame( array( 1, 3, 5 ), $profiles['ticketmaster']['stages'] );
		$this->assertSame( 'ticketmaster', $profiles['ticketmaster']['handler'] );
		$this->assertSame( array( 1, 3, 10 ), $profiles['dice']['stages'] );
		$this->assertSame( 'dice_fm', $profiles['dice']['handler'] );
		$this->assertSame( array( 1, 2 ), $profiles['universal_scraper']['stages'] );
		$this->assertSame( 'universal_web_scraper', $profiles['universal_scraper']['handler'] );
	}

	/**
	 * Confirm passing evidence proposes each source's next stage.
	 *
	 * @dataProvider advancement_provider
	 * @param string $source  Source class.
	 * @param int    $current Current stage.
	 * @param int    $next    Expected stage.
	 */
	public function test_passing_evidence_proposes_each_source_next_stage( string $source, int $current, int $next ): void {
		$result = $this->evaluate( $source, $current, 'preflight', $this->passing_evidence() );

		$this->assertSame( 'advance', $result['decision'] );
		$this->assertTrue( $result['can_advance'] );
		$this->assertSame( $next, $result['proposed_stage'] );
		$this->assertStringContainsString( '"max_items":' . $next, $result['apply_plan']['apply_command'] );
	}

	/**
	 * Provide each source's first advancement.
	 *
	 * @return array<string,array{string,int,int}>
	 */
	public function advancement_provider(): array {
		return array(
			'ticketmaster'      => array( 'ticketmaster', 1, 3 ),
			'dice'              => array( 'dice', 1, 3 ),
			'universal scraper' => array( 'universal_scraper', 1, 2 ),
		);
	}

	/** Confirm a missing required metric produces a hold. */
	public function test_missing_evidence_refuses_advancement(): void {
		$evidence = $this->passing_evidence();
		unset( $evidence['metrics']['failed_rate'] );

		$result = $this->evaluate( 'ticketmaster', 1, 'preflight', $evidence );

		$this->assertSame( 'hold', $result['decision'] );
		$this->assertContains( 'failed_rate', $result['failed_gates'] );
		$this->assertNull( $result['apply_plan']['apply_command'] );
	}

	/** Confirm stale postflight evidence cannot cause advancement or rollback. */
	public function test_stale_evidence_refuses_advancement_without_rollback(): void {
		$evidence                = $this->passing_evidence();
		$evidence['observed_at'] = gmdate( 'c', self::NOW - EventSourceRampEvaluator::MAXIMUM_EVIDENCE_AGE_SECONDS - 1 );

		$result = $this->evaluate( 'dice', 3, 'postflight', $evidence );

		$this->assertSame( 'hold', $result['decision'] );
		$this->assertContains( 'freshness', $result['failed_gates'] );
		$this->assertNull( $result['apply_plan']['apply_command'] );
	}

	/** Confirm a failed preflight metric holds the current stage. */
	public function test_failed_preflight_gate_holds_current_stage(): void {
		$evidence                           = $this->passing_evidence();
		$evidence['metrics']['queue_depth'] = 26;

		$result = $this->evaluate( 'ticketmaster', 1, 'preflight', $evidence );

		$this->assertSame( 'hold', $result['decision'] );
		$this->assertContains( 'queue_depth', $result['failed_gates'] );
	}

	/** Confirm a passing final stage cannot advance beyond policy. */
	public function test_passing_final_stage_is_complete(): void {
		$result = $this->evaluate( 'dice', 10, 'postflight', $this->passing_evidence() );

		$this->assertSame( 'complete', $result['decision'] );
		$this->assertFalse( $result['can_advance'] );
		$this->assertNull( $result['proposed_stage'] );
		$this->assertNull( $result['apply_plan']['apply_command'] );
	}

	/** Confirm a trustworthy failed postflight reduces one stage reversibly. */
	public function test_failed_postflight_emits_reversible_reduction(): void {
		$evidence                           = $this->passing_evidence();
		$evidence['metrics']['failed_rate'] = 0.06;

		$result = $this->evaluate( 'ticketmaster', 3, 'postflight', $evidence );

		$this->assertSame( 'rollback', $result['decision'] );
		$this->assertStringContainsString( '"max_items":1', $result['apply_plan']['apply_command'] );
		$this->assertStringContainsString( '"max_items":3', $result['apply_plan']['rollback_command'] );
		$this->assertTrue( $result['apply_plan']['canonical_events_preserved'] );
	}

	/** Confirm a failed first stage pauses instead of deleting events. */
	public function test_failed_first_stage_postflight_pauses_reversibly(): void {
		$evidence                                  = $this->passing_evidence();
		$evidence['metrics']['ai_defer_exhausted'] = 1;

		$result = $this->evaluate( 'universal_scraper', 1, 'postflight', $evidence );

		$this->assertSame( 'rollback', $result['decision'] );
		$this->assertSame( 'wp datamachine flows pause --pipeline=10', $result['apply_plan']['apply_command'] );
		$this->assertSame( 'wp datamachine flows resume --pipeline=10', $result['apply_plan']['rollback_command'] );
	}

	/** Confirm evaluator output matches the declared top-level JSON schema. */
	public function test_output_matches_declared_json_schema(): void {
		$result = $this->evaluate( 'ticketmaster', 1, 'preflight', $this->passing_evidence() );
		$schema = EventSourceRampAbilities::output_schema();

		$this->assertSame( array(), array_diff( $schema['required'], array_keys( $result ) ) );
		$this->assertSame( array(), array_diff( array_keys( $result ), array_keys( $schema['properties'] ) ) );
		$this->assertSame( EventSourceRampEvaluator::SCHEMA_VERSION, $result['schema_version'] );
		$this->assertJson( wp_json_encode( $result ) );

		$input_schema = EventSourceRampAbilities::input_schema();
		$this->assertFalse( $input_schema['additionalProperties'] );
		$this->assertSame(
			array_keys( EventSourceRampEvaluator::profiles()['ticketmaster']['thresholds'] ),
			array_keys( $input_schema['properties']['evidence']['properties']['metrics']['properties'] )
		);
	}

	/** Confirm evaluation emits a plan but never calls a mutation hook. */
	public function test_evaluation_is_observational_and_does_not_call_mutation_owner(): void {
		$GLOBALS['ec_ramp_mutations'] = 0;
		add_filter(
			'extrachill_events_ramp_mutation',
			static function (): bool {
				++$GLOBALS['ec_ramp_mutations'];
				return true;
			}
		);

		$result = $this->evaluate( 'ticketmaster', 1, 'preflight', $this->passing_evidence() );

		$this->assertSame( 0, $GLOBALS['ec_ramp_mutations'] );
		$this->assertTrue( $result['apply_plan']['observational'] );
		$this->assertFalse( $result['apply_plan']['preview_available'] );
		$this->assertSame( 'Extra-Chill/data-machine#3019', $result['apply_plan']['preview_blocker'] );
		unset( $GLOBALS['ec_ramp_mutations'] );
	}

	/**
	 * Build passing evidence at a stable time.
	 *
	 * @return array<string,mixed>
	 */
	private function passing_evidence(): array {
		return array(
			'schema_version' => EventSourceRampEvaluator::SCHEMA_VERSION,
			'observed_at'    => gmdate( 'c', self::NOW - 60 ),
			'window_start'   => gmdate( 'c', self::NOW - DAY_IN_SECONDS ),
			'window_end'     => gmdate( 'c', self::NOW ),
			'provenance'     => array(
				'flow_settings'    => 'flow.json',
				'jobs_liveness'    => 'liveness.json',
				'job_metrics'      => 'jobs.json',
				'action_scheduler' => 'actions.json',
				'event_quality'    => 'quality.json',
				'bundle_wave'      => 'extrachill-event-bundles#10/london',
			),
			'metrics'        => array(
				'queue_depth'                     => 5,
				'oldest_queue_age_seconds'        => 600,
				'jobs_without_scheduler_path'     => 0,
				'failed_rate'                     => 0.01,
				'deferred_rate'                   => 0.04,
				'ai_defer_budget_used_rate'       => 0.20,
				'ai_defer_exhausted'              => 0,
				'action_scheduler_pending_growth' => 2,
				'source_rejection_rate'           => 0.02,
				'eligible_events'                 => 40,
				'duplicate_rate'                  => 0.03,
				'event_yield'                     => 0.70,
			),
			'cadence_status' => null,
		);
	}

	/**
	 * Evaluate one source with a stable clock and scope.
	 *
	 * @param string              $source   Source class.
	 * @param int                 $current  Current stage.
	 * @param string              $phase    Evidence phase.
	 * @param array<string,mixed> $evidence Evidence document.
	 * @return array<string,mixed>
	 */
	private function evaluate( string $source, int $current, string $phase, array $evidence ): array {
		return ( new EventSourceRampEvaluator() )->evaluate(
			array(
				'source_class'      => $source,
				'current_max_items' => $current,
				'phase'             => $phase,
				'scope'             => 'pipeline',
				'scope_id'          => 10,
				'evidence'          => $evidence,
			),
			self::NOW
		);
	}
}
