<?php
/**
 * Tests for QualifyRecheckHandler.
 *
 * Stubs FlowOps via a global recorder array + replaces wp_get_ability with
 * a closure-returning fake. Validates the handler's branching:
 *
 *  - qualified_structured → resume + no further schedule.
 *  - still-failing same verdict → reschedule with incremented failure count.
 *  - different non-qualifying verdict → update paused_reason + reschedule.
 *  - permanent verdict → update paused_reason + STOP rescheduling.
 *  - already-unpaused flow → no-op.
 *  - consecutive_failures crosses threshold → flag stale + stop.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyRecheckHandler;
use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

// Stubs MUST load before the handler so the fake FlowOps wins the
// class-resolution race (the real FlowOps in inc/Cli/FlowOps.php is
// deliberately not required by this test file).
require_once __DIR__ . '/Stubs/recheck-handler-stubs.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyRecheckHandler.php';

class QualifyRecheckHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_test_flow_rows']        = array();
		$GLOBALS['ec_test_flowops_calls']    = array();
		$GLOBALS['ec_test_action_scheduler'] = array();
		$GLOBALS['ec_test_ability_result']   = null;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ec_test_flow_rows'],
			$GLOBALS['ec_test_flowops_calls'],
			$GLOBALS['ec_test_action_scheduler'],
			$GLOBALS['ec_test_ability_result']
		);
		parent::tearDown();
	}

	private function seed_paused_flow( int $flow_id ): void {
		$GLOBALS['ec_test_flow_rows'][ $flow_id ] = array(
			'flow_id'           => $flow_id,
			'flow_name'         => 'Test flow',
			'scheduling_config' => array(
				'interval'      => 'manual',
				'paused_reason' => QualifyVerdict::EXTRACTION_GAP,
			),
		);
	}

	public function test_qualified_structured_triggers_resume(): void {
		$this->seed_paused_flow( 42 );
		$GLOBALS['ec_test_ability_result'] = array(
			'verdict'     => QualifyVerdict::QUALIFIED_STRUCTURED,
			'event_count' => 8,
		);

		QualifyRecheckHandler::handle( array(
			'flow_id'              => 42,
			'url'                  => 'https://example.com/events',
			'verdict'              => QualifyVerdict::EXTRACTION_GAP,
			'consecutive_failures' => 0,
		) );

		$resume_calls = array_filter(
			$GLOBALS['ec_test_flowops_calls'],
			fn( $c ) => 'resume_flow_from_qualified' === $c['method']
		);
		$this->assertCount( 1, $resume_calls );
		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'], 'No reschedule on resume' );
	}

	public function test_still_failing_reschedules_with_incremented_failures(): void {
		$this->seed_paused_flow( 43 );
		$GLOBALS['ec_test_ability_result'] = array(
			'verdict' => QualifyVerdict::EXTRACTION_GAP,
		);

		QualifyRecheckHandler::handle( array(
			'flow_id'              => 43,
			'url'                  => 'https://example.com/events',
			'verdict'              => QualifyVerdict::EXTRACTION_GAP,
			'consecutive_failures' => 2,
		) );

		$this->assertCount( 1, $GLOBALS['ec_test_action_scheduler'] );
		$scheduled = $GLOBALS['ec_test_action_scheduler'][0];
		$this->assertSame( 'dme/qualify_recheck', $scheduled['hook'] );
		$this->assertSame( 'dme_qualify', $scheduled['group'] );
		$this->assertSame( 3, $scheduled['args'][0]['consecutive_failures'] );
	}

	public function test_permanent_verdict_stops_rescheduling(): void {
		$this->seed_paused_flow( 44 );
		$GLOBALS['ec_test_ability_result'] = array(
			'verdict' => QualifyVerdict::RESERVATION_ONLY,
		);

		QualifyRecheckHandler::handle( array(
			'flow_id'              => 44,
			'url'                  => 'https://example.com/events',
			'verdict'              => QualifyVerdict::UNREACHABLE,
			'consecutive_failures' => 0,
		) );

		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'] );
		$update_calls = array_filter(
			$GLOBALS['ec_test_flowops_calls'],
			fn( $c ) => 'update_paused_reason' === $c['method']
		);
		$this->assertNotEmpty( $update_calls );
	}

	public function test_unpaused_flow_is_noop(): void {
		// Flow is no longer paused — handler must short-circuit.
		$GLOBALS['ec_test_flow_rows'][45] = array(
			'flow_id'           => 45,
			'flow_name'         => 'Already unpaused',
			'scheduling_config' => array( 'interval' => 'daily' ),
		);

		QualifyRecheckHandler::handle( array(
			'flow_id'              => 45,
			'url'                  => 'https://example.com/events',
			'verdict'              => QualifyVerdict::EXTRACTION_GAP,
			'consecutive_failures' => 0,
		) );

		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'] );
		$this->assertEmpty( $GLOBALS['ec_test_flowops_calls'] );
	}

	public function test_consecutive_failures_threshold_flags_stale(): void {
		$this->seed_paused_flow( 46 );
		$GLOBALS['ec_test_ability_result'] = array(
			'verdict' => QualifyVerdict::EXTRACTION_GAP,
		);

		// consecutive_failures=5 + this run = 6 → at threshold.
		QualifyRecheckHandler::handle( array(
			'flow_id'              => 46,
			'url'                  => 'https://example.com/events',
			'verdict'              => QualifyVerdict::EXTRACTION_GAP,
			'consecutive_failures' => 5,
		) );

		$flag_calls = array_filter(
			$GLOBALS['ec_test_flowops_calls'],
			fn( $c ) => 'flag_stale_paused' === $c['method']
		);
		$this->assertCount( 1, $flag_calls );
		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'], 'No reschedule after stale flag' );
	}

	public function test_missing_flow_is_noop(): void {
		// No flow seeded — handler should bail without errors.
		QualifyRecheckHandler::handle( array(
			'flow_id'              => 999,
			'url'                  => 'https://example.com/events',
			'verdict'              => QualifyVerdict::EXTRACTION_GAP,
			'consecutive_failures' => 0,
		) );

		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'] );
		$this->assertEmpty( $GLOBALS['ec_test_flowops_calls'] );
	}

	public function test_invalid_payload_is_noop(): void {
		QualifyRecheckHandler::handle( array() );
		QualifyRecheckHandler::handle( 'not-an-array' );
		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'] );
	}
}
