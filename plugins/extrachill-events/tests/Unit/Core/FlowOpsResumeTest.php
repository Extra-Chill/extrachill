<?php
/**
 * Tests for FlowOps::resume_flow_from_qualified() — events_url propagation.
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
use ExtraChillEvents\Cli\FlowOps;

/**
 * Exercises the real FlowOps class against a captured $wpdb stub and a
 * captured do_action() log stream so we can assert both the persisted
 * payload and the structured log entries.
 *
 * Runs each test in a separate process because QualifyRecheckHandlerTest
 * loads a fake FlowOps in the same namespace; without isolation, whichever
 * test file loads first wins the class-resolution race.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FlowOpsResumeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Loaded here (rather than at file top) so the parent PHPUnit
		// process that collects tests never pulls in the real FlowOps
		// class. QualifyRecheckHandlerTest installs a same-namespace
		// fake FlowOps and needs to win the class-resolution race in
		// its own process. Tests in THIS class run in isolated
		// subprocesses (see @runTestsInSeparateProcesses) so the real
		// FlowOps gets a clean load each time.
		require_once __DIR__ . '/Stubs/flowops-resume-stubs.php';
		require_once dirname( __DIR__, 3 ) . '/inc/Cli/FlowOps.php';

		$GLOBALS['wpdb']                  = new FlowOpsFakeWpdb();
		$GLOBALS['ec_test_log_entries']   = array();
		$GLOBALS['ec_test_async_actions'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['wpdb'],
			$GLOBALS['ec_test_log_entries'],
			$GLOBALS['ec_test_async_actions']
		);
		parent::tearDown();
	}

	/**
	 * Build a paused flow row seeded into the fake $wpdb.
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

		$scheduling = array(
			'interval'       => 'manual',
			'prior_interval' => 'daily',
			'paused_reason'  => 'unreachable',
			'paused_at'      => '2025-01-01 00:00:00',
		);

		$GLOBALS['wpdb']->seed_row(
			$flow_id,
			array(
				'flow_id'           => $flow_id,
				'flow_config'       => json_encode( $flow_config ),
				'scheduling_config' => json_encode( $scheduling ),
			)
		);
	}

	/**
	 * Pull the most recent flow_config update applied to a flow_id, decoded.
	 */
	private function captured_flow_config( int $flow_id ): ?array {
		foreach ( array_reverse( $GLOBALS['wpdb']->updates ) as $u ) {
			if ( ( $u['where']['flow_id'] ?? null ) === $flow_id && isset( $u['data']['flow_config'] ) ) {
				return json_decode( (string) $u['data']['flow_config'], true );
			}
		}
		return null;
	}

	private function last_update( int $flow_id ): ?array {
		foreach ( array_reverse( $GLOBALS['wpdb']->updates ) as $u ) {
			if ( ( $u['where']['flow_id'] ?? null ) === $flow_id ) {
				return $u;
			}
		}
		return null;
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

		// Scheduling restored too.
		$update     = $this->last_update( 100 );
		$scheduling = json_decode( (string) $update['data']['scheduling_config'], true );
		$this->assertSame( 'daily', $scheduling['interval'] );
		$this->assertArrayNotHasKey( 'paused_reason', $scheduling );
		$this->assertTrue( $scheduling['resumed_by_qualify'] );
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

		$update = $this->last_update( 101 );
		$this->assertArrayNotHasKey(
			'flow_config',
			$update['data'],
			'flow_config must not be in the update when events_url matches'
		);
		$this->assertArrayHasKey( 'scheduling_config', $update['data'] );
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

		$update = $this->last_update( 102 );
		$this->assertArrayNotHasKey( 'flow_config', $update['data'] );
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

		$update = $this->last_update( 103 );
		$this->assertArrayNotHasKey(
			'flow_config',
			$update['data'],
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
		$scheduling  = array(
			'interval'       => 'manual',
			'prior_interval' => 'daily',
			'paused_reason'  => 'unreachable',
		);
		$GLOBALS['wpdb']->seed_row(
			106,
			array(
				'flow_id'           => 106,
				'flow_config'       => json_encode( $flow_config ),
				'scheduling_config' => json_encode( $scheduling ),
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
	}

	public function test_confirmed_repair_rejects_cross_host_source_url(): void {
		$this->seed_paused_flow( 108, 'https://venue.com/calendar' );

		$this->assertFalse(
			FlowOps::repair_flow_source_url( 108, 'https://venue.com/calendar', 'https://other.example/events' )
		);
		$this->assertNull( $this->captured_flow_config( 108 ) );
	}
}
