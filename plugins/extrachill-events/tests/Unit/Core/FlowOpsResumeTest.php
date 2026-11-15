<?php
/**
 * Tests for FlowOps — resume/events_url propagation, pause, and metadata.
 *
 * Covers the bug fix from extrachill-events#81: when qualify discovers a
 * new events_url within the same host, the flow's universal_web_scraper
 * source_url must be patched as part of the resume. Cross-host changes
 * are intentionally skipped (operator review).
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ExtraChillEvents\Core\FlowOps;

require_once __DIR__ . '/Stubs/flow-gateway-stubs.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/FlowOps.php';

/**
 * Exercises the real FlowOps class against an in-memory Data Machine
 * gateway and a captured `datamachine_log` stream so we can assert the
 * resulting flow state, the Data Machine calls made, and the structured
 * log entries.
 */
class FlowOpsResumeTest extends TestCase {
	private FakeFlowGateway $gateway;
	private $log_callback;
	private $original_log_hooks;

	protected function setUp(): void {
		parent::setUp();
		$this->original_log_hooks = $GLOBALS['wp_filter']['datamachine_log'] ?? null;

		if ( function_exists( 'remove_all_actions' ) ) {
			remove_all_actions( 'datamachine_log' );
		}
		$this->gateway = new FakeFlowGateway();
		FlowOps::set_gateway( $this->gateway );
		$GLOBALS['ec_test_log_entries'] = array();
		$this->log_callback             = static function ( string $level, string $message, array $context ): void {
			$GLOBALS['ec_test_log_entries'][] = compact( 'level', 'message', 'context' );
		};
		add_action( 'datamachine_log', $this->log_callback, 10, 3 );
		add_filter( 'pre_option_timezone_string', array( $this, 'filter_timezone_string' ) );
	}

	public function filter_timezone_string(): string {
		return 'UTC';
	}

	protected function tearDown(): void {
		FlowOps::set_gateway( null );
		remove_action( 'datamachine_log', $this->log_callback, 10 );
		if ( null === $this->original_log_hooks ) {
			unset( $GLOBALS['wp_filter']['datamachine_log'] );
		} else {
			$GLOBALS['wp_filter']['datamachine_log'] = $this->original_log_hooks;
		}
		remove_filter( 'pre_option_timezone_string', array( $this, 'filter_timezone_string' ) );
		unset( $GLOBALS['ec_test_log_entries'] );
		parent::tearDown();
	}

	/**
	 * Seed a paused flow into the fake gateway.
	 *
	 * @param int    $flow_id
	 * @param string $source_url Initial universal_web_scraper source_url.
	 * @param array  $extra_steps Optional additional steps appended to flow_config.
	 */
	private function seed_paused_flow( int $flow_id, string $source_url, array $extra_steps = array() ): void {
		$flow_config = array_merge(
			array(
				array(
					'step_type'      => 'event_import',
					'handler_slug'   => 'universal_web_scraper',
					'handler_config' => array(
						'source_url' => $source_url,
					),
				),
			),
			$extra_steps
		);

		$this->gateway->seed(
			$flow_id,
			$flow_config,
			array(
				'interval'       => 'manual',
				'prior_interval' => 'daily',
				'paused_reason'  => 'unreachable',
				'paused_at'      => '2025-01-01 00:00:00',
			)
		);
	}

	/**
	 * Current flow_config, or null when no step source URL was updated.
	 */
	private function captured_flow_config( int $flow_id ): ?array {
		if ( empty( $this->gateway->calls_to( 'update_step_source_url' ) ) ) {
			return null;
		}
		return $this->gateway->flows[ $flow_id ]['flow_config'];
	}

	private function scheduling( int $flow_id ): array {
		return $this->gateway->flows[ $flow_id ]['scheduling_config'];
	}

	private function find_log_entries( string $action ): array {
		return array_values(
			array_filter(
				$GLOBALS['ec_test_log_entries'],
				fn( $entry ) => ( $entry['context']['action'] ?? null ) === $action
			)
		);
	}

	public function test_resume_propagates_events_url_when_changed_within_host(): void {
		$this->seed_paused_flow( 100, 'https://venue.com/calendar' );

		FlowOps::resume_flow_from_qualified(
			100,
			array(
				'verdict'     => 'qualified_structured',
				'events_url'  => 'https://venue.com/events',
				'event_count' => 5,
			)
		);

		$patched = $this->captured_flow_config( 100 );
		$this->assertNotNull( $patched, 'flow_config must be included in the update' );
		$this->assertSame(
			'https://venue.com/events',
			$patched[0]['handler_config']['source_url']
		);

		// Scheduling restored too, through Data Machine's schedule sync.
		$scheduling = $this->scheduling( 100 );
		$this->assertSame( 'daily', $scheduling['interval'] );
		$this->assertArrayNotHasKey( 'paused_reason', $scheduling );
		$this->assertArrayNotHasKey( 'prior_interval', $scheduling );
		$this->assertTrue( $scheduling['resumed_by_qualify'] );
		$this->assertSame( 'daily', $this->gateway->calls_to( 'update_scheduling' )[0]['args'][1]['interval'] );
		$this->assertSame( array( array( 'method' => 'run_flow', 'args' => array( 100 ) ) ), $this->gateway->calls_to( 'run_flow' ) );
	}

	public function test_resume_skips_propagation_when_events_url_matches(): void {
		$this->seed_paused_flow( 101, 'https://venue.com/events' );

		FlowOps::resume_flow_from_qualified(
			101,
			array(
				'verdict'    => 'qualified_structured',
				'events_url' => 'https://venue.com/events',
			)
		);

		$this->assertNull(
			$this->captured_flow_config( 101 ),
			'flow_config must not be updated when events_url matches'
		);
		$this->assertSame( 'daily', $this->scheduling( 101 )['interval'] );
		$this->assertEmpty( $this->find_log_entries( 'flow_source_url_updated_by_qualify' ) );
	}

	public function test_resume_skips_propagation_when_events_url_empty(): void {
		$this->seed_paused_flow( 102, 'https://venue.com/calendar' );

		FlowOps::resume_flow_from_qualified(
			102,
			array(
				'verdict' => 'qualified_structured',
			// no events_url key at all
			)
		);

		$this->assertNull( $this->captured_flow_config( 102 ) );
		$this->assertEmpty( $this->find_log_entries( 'flow_source_url_updated_by_qualify' ) );
	}

	public function test_resume_skips_propagation_when_cross_host(): void {
		$this->seed_paused_flow( 103, 'https://venue.com/calendar' );

		FlowOps::resume_flow_from_qualified(
			103,
			array(
				'verdict'    => 'qualified_structured',
				'events_url' => 'https://different-host.com/events',
			)
		);

		$this->assertNull(
			$this->captured_flow_config( 103 ),
			'Cross-host events_url must not patch flow_config'
		);

		$warnings = $this->find_log_entries( 'flow_source_url_cross_host_skip' );
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'warning', $warnings[0]['level'] );
		$this->assertSame( 'https://venue.com/calendar', $warnings[0]['context']['old_source_url'] );
		$this->assertSame( 'https://different-host.com/events', $warnings[0]['context']['new_events_url'] );
	}

	public function test_resume_patches_only_matching_steps(): void {
		// Flow has 2 universal_web_scraper steps with DIFFERENT source_urls.
		// Recheck matches only the first one. The second must remain untouched.
		$this->seed_paused_flow(
			104,
			'https://venue.com/calendar',
			array(
				array(
					'step_type'      => 'event_import',
					'handler_slug'   => 'universal_web_scraper',
					'handler_config' => array(
						'source_url' => 'https://venue.com/special-shows',
					),
				),
			)
		);

		FlowOps::resume_flow_from_qualified(
			104,
			array(
				'verdict'    => 'qualified_structured',
				'events_url' => 'https://venue.com/events',
			)
		);

		$patched = $this->captured_flow_config( 104 );
		$this->assertNotNull( $patched );
		$this->assertSame( 'https://venue.com/events', $patched[0]['handler_config']['source_url'] );
		$this->assertSame(
			'https://venue.com/special-shows',
			$patched[1]['handler_config']['source_url'],
			'Operator-set second step must not be auto-rewritten'
		);

		$updates = $this->find_log_entries( 'flow_source_url_updated_by_qualify' );
		$this->assertCount( 1, $updates );
		$this->assertSame( 1, $updates[0]['context']['steps_patched'] );
	}

	public function test_resume_logs_source_url_change(): void {
		$this->seed_paused_flow( 105, 'https://venue.com/calendar' );

		FlowOps::resume_flow_from_qualified(
			105,
			array(
				'verdict'    => 'qualified_structured',
				'events_url' => 'https://venue.com/events',
			)
		);

		$entries = $this->find_log_entries( 'flow_source_url_updated_by_qualify' );
		$this->assertCount( 1, $entries );
		$entry = $entries[0];

		$this->assertSame( 'info', $entry['level'] );
		$this->assertSame( 105, $entry['context']['flow_id'] );
		$this->assertSame( 'https://venue.com/calendar', $entry['context']['old_source_url'] );
		$this->assertSame( 'https://venue.com/events', $entry['context']['new_source_url'] );
		$this->assertSame( 1, $entry['context']['steps_patched'] );
	}

	public function test_resume_patches_legacy_handler_configs_shape(): void {
		// Older flow_config shape: handler_configs{slug: config}.
		$flow_config = array(
			array(
				'step_type'       => 'event_import',
				'handler_slugs'   => array( 'universal_web_scraper' ),
				'handler_configs' => array(
					'universal_web_scraper' => array(
						'source_url' => 'https://venue.com/calendar',
					),
				),
			),
		);
		$this->gateway->seed(
			106,
			$flow_config,
			array(
				'interval'       => 'manual',
				'prior_interval' => 'daily',
				'paused_reason'  => 'unreachable',
			)
		);

		FlowOps::resume_flow_from_qualified(
			106,
			array(
				'verdict'    => 'qualified_structured',
				'events_url' => 'https://venue.com/events',
			)
		);

		$patched = $this->captured_flow_config( 106 );
		$this->assertNotNull( $patched );
		$this->assertSame(
			'https://venue.com/events',
			$patched[0]['handler_configs']['universal_web_scraper']['source_url']
		);
	}

	public function test_confirmed_repair_only_updates_same_host_source_url(): void {
		$this->seed_paused_flow( 107, 'https://venue.com/calendar' );

		$this->assertTrue(
			FlowOps::repair_flow_source_url( 107, 'https://venue.com/calendar', 'https://venue.com/events' )
		);
		$patched = $this->captured_flow_config( 107 );
		$this->assertSame( 'https://venue.com/events', $patched[0]['handler_config']['source_url'] );
		$this->assertEmpty( $this->gateway->calls_to( 'update_scheduling' ), 'Repair must not touch scheduling' );
		$this->assertEmpty( $this->gateway->calls_to( 'run_flow' ), 'Repair must not start a run' );
	}

	public function test_confirmed_repair_rejects_cross_host_source_url(): void {
		$this->seed_paused_flow( 108, 'https://venue.com/calendar' );

		$this->assertFalse(
			FlowOps::repair_flow_source_url( 108, 'https://venue.com/calendar', 'https://other.example/events' )
		);
		$this->assertNull( $this->captured_flow_config( 108 ) );
	}

	public function test_confirmed_repair_rejects_stale_current_source_url(): void {
		$this->seed_paused_flow( 109, 'https://venue.com/shows' );

		$this->assertFalse(
			FlowOps::repair_flow_source_url( 109, 'https://venue.com/calendar', 'https://venue.com/events' )
		);
		$this->assertNull( $this->captured_flow_config( 109 ) );
	}

	public function test_pause_sets_manual_interval_through_data_machine(): void {
		$this->gateway->seed(
			110,
			array(),
			array(
				'interval'          => 'every_6_hours',
				'stale_flag'        => array( 'consecutive_failures' => 6 ),
				'recheck_action_id' => 0,
			)
		);

		$this->assertTrue( FlowOps::pause_flow_by_verdict( 110, 'bot_blocked' ) );

		$synced = $this->gateway->calls_to( 'update_scheduling' );
		$this->assertCount( 1, $synced );
		$this->assertSame( 'manual', $synced[0]['args'][1]['interval'] );

		$scheduling = $this->scheduling( 110 );
		$this->assertSame( 'manual', $scheduling['interval'] );
		$this->assertSame( 'every_6_hours', $scheduling['prior_interval'] );
		$this->assertSame( 'bot_blocked', $scheduling['paused_reason'] );
		$this->assertArrayNotHasKey( 'stale_flag', $scheduling );
		$this->assertArrayNotHasKey( 'recheck_action_id', $scheduling );
	}

	public function test_repause_keeps_original_prior_interval(): void {
		$this->gateway->seed(
			111,
			array(),
			array(
				'interval'       => 'manual',
				'prior_interval' => 'daily',
				'paused_reason'  => 'unreachable',
			)
		);

		$this->assertTrue( FlowOps::pause_flow_by_verdict( 111, 'bot_blocked' ) );

		$scheduling = $this->scheduling( 111 );
		$this->assertSame( 'daily', $scheduling['prior_interval'] );
		$this->assertSame( 'bot_blocked', $scheduling['paused_reason'] );
	}

	public function test_metadata_updates_never_resync_the_schedule(): void {
		$this->gateway->seed(
			112,
			array(),
			array(
				'interval'              => 'manual',
				'paused_reason'         => 'unreachable',
				'recheck_action_id'     => 5,
				'recheck_scheduled_for' => '2025-01-01 00:00:00',
			)
		);

		$this->assertTrue( FlowOps::update_paused_reason( 112, 'bot_blocked' ) );
		$this->assertTrue( FlowOps::set_recheck_metadata( 112, 77, 1767225600 ) );
		$this->assertTrue( FlowOps::flag_stale_paused( 112, 'bot_blocked', 6 ) );

		$this->assertEmpty( $this->gateway->calls_to( 'update_scheduling' ) );
		$scheduling = $this->scheduling( 112 );
		$this->assertSame( 'manual', $scheduling['interval'] );
		$this->assertSame( 'bot_blocked', $scheduling['paused_reason'] );
		$this->assertSame( 6, $scheduling['stale_flag']['consecutive_failures'] );
		$this->assertArrayNotHasKey( 'recheck_action_id', $scheduling );
		$this->assertArrayNotHasKey( 'recheck_scheduled_for', $scheduling );
	}

	public function test_missing_flow_writes_nothing(): void {
		$this->assertFalse( FlowOps::resume_flow_from_qualified( 999, array( 'verdict' => 'qualified_structured' ) ) );
		$this->assertFalse( FlowOps::pause_flow_by_verdict( 999, 'bot_blocked' ) );
		$this->assertEmpty( $this->gateway->writes() );
	}
}
