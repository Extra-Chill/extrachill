<?php
/**
 * Tests for QualifyRecheckHandler.
 *
 * Runs the real FlowOps implementation against an in-memory Data Machine
 * gateway and
 * a controlled qualification ability. Validates the handler's branching:
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

use ExtraChillEvents\Core\FlowOps;
use ExtraChillEvents\Core\QualifyRecheckHandler;
use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/recheck-handler-runtime-stubs.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/FlowOps.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyRecheckHandler.php';

class QualifyRecheckHandlerTest extends TestCase {
	private FakeFlowGateway $gateway;
	private $original_log_hooks;
	private $schedule_callback;
	private $original_ability_resolver;
	private bool $registered_test_category = false;

	protected function setUp(): void {
		parent::setUp();
		$this->original_log_hooks             = $GLOBALS['wp_filter']['datamachine_log'] ?? null;
		$this->original_ability_resolver       = $GLOBALS['ec_test_ability_resolver'] ?? null;
		$GLOBALS['ec_test_ability_resolver']   = static function ( string $name ) {
			if ( 'extrachill/qualify-venue' !== $name ) {
				return null;
			}

			return new class() {
				public function execute( array $input ) {
					unset( $input );
					return $GLOBALS['ec_test_ability_result'];
				}
			};
		};
		if ( function_exists( 'remove_all_actions' ) ) {
			remove_all_actions( 'datamachine_log' );
		}
		$this->gateway                       = new FakeFlowGateway();
		FlowOps::set_gateway( $this->gateway );
		$GLOBALS['ec_test_action_scheduler'] = array();
		$GLOBALS['ec_test_ability_result']   = null;
		$this->schedule_callback             = static function ( $pre, int $timestamp, string $hook, array $args, string $group ) {
			$GLOBALS['ec_test_action_scheduler'][] = compact( 'timestamp', 'hook', 'args', 'group' );
			return 12345;
		};
		add_filter( 'pre_as_schedule_single_action', $this->schedule_callback, 10, 5 );
		$this->register_ability();
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_unregister_ability' ) && wp_has_ability( 'extrachill/qualify-venue' ) ) {
			wp_unregister_ability( 'extrachill/qualify-venue' );
		}
		if ( $this->registered_test_category && function_exists( 'wp_unregister_ability_category' ) && wp_has_ability_category( 'extrachill-events-tests' ) ) {
			wp_unregister_ability_category( 'extrachill-events-tests' );
		}
		remove_filter( 'pre_as_schedule_single_action', $this->schedule_callback, 10 );
		if ( null === $this->original_log_hooks ) {
			unset( $GLOBALS['wp_filter']['datamachine_log'] );
		} else {
			$GLOBALS['wp_filter']['datamachine_log'] = $this->original_log_hooks;
		}
		FlowOps::set_gateway( null );
		if ( null === $this->original_ability_resolver ) {
			unset( $GLOBALS['ec_test_ability_resolver'] );
		} else {
			$GLOBALS['ec_test_ability_resolver'] = $this->original_ability_resolver;
		}
		unset(
			$GLOBALS['ec_test_action_scheduler'],
			$GLOBALS['ec_test_ability_result']
		);
		parent::tearDown();
	}

	private function register_ability(): void {
		if ( ! class_exists( '\\WP_Abilities_Registry' ) ) {
			return;
		}
		if ( wp_has_ability( 'extrachill/qualify-venue' ) ) {
			wp_unregister_ability( 'extrachill/qualify-venue' );
		}
		if ( ! wp_has_ability_category( 'extrachill-events-tests' ) ) {
			$this->registered_test_category = true;
			\WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-events-tests',
				array(
					'label'       => 'Extra Chill Events tests',
					'description' => 'Test-only abilities for managed runtime validation.',
				)
			);
		}
		$ability = \WP_Abilities_Registry::get_instance()->register(
			'extrachill/qualify-venue',
			array(
				'label'               => 'Qualify venue test',
				'description'         => 'Returns the controlled qualification result.',
				'category'            => 'extrachill-events-tests',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array( 'type' => 'string' ),
					),
					'required'   => array( 'url' ),
				),
				'execute_callback'    => static fn( array $input ) => $GLOBALS['ec_test_ability_result'],
				'permission_callback' => '__return_true',
			)
		);
		if ( null === $ability ) {
			throw new \RuntimeException( 'Failed to register the controlled qualification ability.' );
		}
	}

	private function seed_paused_flow( int $flow_id ): void {
		$this->gateway->seed(
			$flow_id,
			array(),
			array(
				'interval'      => 'manual',
				'paused_reason' => QualifyVerdict::EXTRACTION_GAP,
			)
		);
	}

	public function test_qualified_structured_triggers_resume(): void {
		$this->seed_paused_flow( 42 );
		$GLOBALS['ec_test_ability_result'] = array(
			'verdict'     => QualifyVerdict::QUALIFIED_STRUCTURED,
			'event_count' => 8,
		);

		QualifyRecheckHandler::handle(
			array(
				'flow_id'              => 42,
				'url'                  => 'https://example.com/events',
				'verdict'              => QualifyVerdict::EXTRACTION_GAP,
				'consecutive_failures' => 0,
			)
		);

		$this->assertSame( 'daily', $this->gateway->flows[42]['scheduling_config']['interval'] );
		$this->assertCount( 1, $this->gateway->calls_to( 'run_flow' ), 'Resume starts a run through Data Machine' );
		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'], 'No reschedule on resume' );
	}

	public function test_still_failing_reschedules_with_incremented_failures(): void {
		$this->seed_paused_flow( 43 );
		$GLOBALS['ec_test_ability_result'] = array(
			'verdict' => QualifyVerdict::EXTRACTION_GAP,
		);

		QualifyRecheckHandler::handle(
			array(
				'flow_id'              => 43,
				'url'                  => 'https://example.com/events',
				'verdict'              => QualifyVerdict::EXTRACTION_GAP,
				'consecutive_failures' => 2,
			)
		);

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

		QualifyRecheckHandler::handle(
			array(
				'flow_id'              => 44,
				'url'                  => 'https://example.com/events',
				'verdict'              => QualifyVerdict::UNREACHABLE,
				'consecutive_failures' => 0,
			)
		);

		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'] );
		$this->assertSame( QualifyVerdict::RESERVATION_ONLY, $this->gateway->flows[44]['scheduling_config']['paused_reason'] );
	}

	public function test_unpaused_flow_is_noop(): void {
		// Flow is no longer paused — handler must short-circuit.
		$this->gateway->seed( 45, array(), array( 'interval' => 'daily' ), 'Already unpaused' );

		QualifyRecheckHandler::handle(
			array(
				'flow_id'              => 45,
				'url'                  => 'https://example.com/events',
				'verdict'              => QualifyVerdict::EXTRACTION_GAP,
				'consecutive_failures' => 0,
			)
		);

		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'] );
		$this->assertEmpty( $this->gateway->writes() );
	}

	public function test_consecutive_failures_threshold_flags_stale(): void {
		$this->seed_paused_flow( 46 );
		$GLOBALS['ec_test_ability_result'] = array(
			'verdict' => QualifyVerdict::EXTRACTION_GAP,
		);

		// consecutive_failures=5 + this run = 6 → at threshold.
		QualifyRecheckHandler::handle(
			array(
				'flow_id'              => 46,
				'url'                  => 'https://example.com/events',
				'verdict'              => QualifyVerdict::EXTRACTION_GAP,
				'consecutive_failures' => 5,
			)
		);

		$this->assertSame( 6, $this->gateway->flows[46]['scheduling_config']['stale_flag']['consecutive_failures'] );
		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'], 'No reschedule after stale flag' );
	}

	public function test_missing_flow_is_noop(): void {
		// No flow seeded — handler should bail without errors.
		QualifyRecheckHandler::handle(
			array(
				'flow_id'              => 999,
				'url'                  => 'https://example.com/events',
				'verdict'              => QualifyVerdict::EXTRACTION_GAP,
				'consecutive_failures' => 0,
			)
		);

		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'] );
		$this->assertEmpty( $this->gateway->writes() );
	}

	public function test_invalid_payload_is_noop(): void {
		QualifyRecheckHandler::handle( array() );
		QualifyRecheckHandler::handle( 'not-an-array' );
		$this->assertEmpty( $GLOBALS['ec_test_action_scheduler'] );
	}
}
