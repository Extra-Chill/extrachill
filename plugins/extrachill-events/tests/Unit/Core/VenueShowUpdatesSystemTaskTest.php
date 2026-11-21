<?php
/** Venue show-updates SystemTask contract tests (extrachill#1724). */

namespace ExtraChillEvents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/digest-stubs.php';
require_once __DIR__ . '/Stubs/system-task-base-stub.php';
require_once dirname( __DIR__, 3 ) . '/inc/Steps/VenueShowUpdates/VenueShowUpdatesSystemTask.php';

class VenueShowUpdatesSystemTaskTest extends TestCase {
	public function test_task_is_feature_gated_and_manually_runnable(): void {
		$task = new \ExtraChillEvents\Steps\VenueShowUpdates\VenueShowUpdatesSystemTask();
		$meta = $task::getTaskMeta();
		$this->assertSame( 'extrachill_venue_show_updates', $task->getTaskType() );
		$this->assertSame( 'extrachill_venue_show_updates_enabled', $meta['setting_key'] );
		$this->assertFalse( $meta['default_enabled'] );
		$this->assertTrue( $meta['supports_run'] );
		$this->assertTrue( $meta['supports_dry_run'] );
		$this->assertSame( array( 'days', 'limit', 'dry_run' ), $meta['params_schema']['accepted'] );
		$this->assertFalse( $task->requiresAgentContext() );
	}

	public function test_disabled_live_run_is_skipped_but_dry_run_previews(): void {
		$GLOBALS['ec_test_systemtask_calls']      = array();
		$GLOBALS['ec_test_plugin_settings_reads'] = array();
		$GLOBALS['ec_test_plugin_settings']       = array( 'extrachill_venue_show_updates_enabled' => false );
		$GLOBALS['ec_test_digest_params']         = array();
		$task = new class() extends \ExtraChillEvents\Steps\VenueShowUpdates\VenueShowUpdatesSystemTask {
			protected function runDigest( array $params ): array {
				$GLOBALS['ec_test_digest_params'][] = $params;
				return array(
					'dry_run'           => ! empty( $params['dry_run'] ),
					'counts'            => array( 'venues_scanned' => 1 ),
					'retryable_failure' => false,
				);
			}
		};

		$task->executeTask( 51, array( 'dry_run' => false ) );
		$this->assertEmpty( $GLOBALS['ec_test_digest_params'] );
		$this->assertSame( array( 'skipped' => true, 'reason' => 'Weekly venue show updates disabled.' ), $GLOBALS['ec_test_systemtask_calls'][0]['data'] );

		$task->executeTask( 52, array( 'dry_run' => true ) );
		$this->assertCount( 1, $GLOBALS['ec_test_digest_params'] );
		$this->assertSame( 'completeJob', $GLOBALS['ec_test_systemtask_calls'][1]['method'] );
		$this->assertSame( 1, $GLOBALS['ec_test_systemtask_calls'][1]['data']['counts']['venues_scanned'] );
	}

	public function test_retryable_evidence_fails_the_job(): void {
		$GLOBALS['ec_test_systemtask_calls'] = array();
		$GLOBALS['ec_test_plugin_settings']  = array( 'extrachill_venue_show_updates_enabled' => true );
		$task = new class() extends \ExtraChillEvents\Steps\VenueShowUpdates\VenueShowUpdatesSystemTask {
			protected function runDigest( array $params ): array {
				return array( 'retryable_failure' => true );
			}
		};
		$task->executeTask( 53, array() );

		$this->assertSame( 'failJob', $GLOBALS['ec_test_systemtask_calls'][0]['method'] );
	}
}
