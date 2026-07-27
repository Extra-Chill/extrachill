<?php
/** Local Scene digest SystemTask contract tests. */

namespace ExtraChillEvents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/digest-stubs.php';
require_once __DIR__ . '/Stubs/system-task-base-stub.php';
require_once dirname( __DIR__, 3 ) . '/inc/Steps/LocalSceneDigest/LocalSceneDigestSystemTask.php';

class LocalSceneDigestSystemTaskTest extends TestCase {
	public function test_task_is_feature_gated_and_manually_runnable(): void {
		$task = new \ExtraChillEvents\Steps\LocalSceneDigest\LocalSceneDigestSystemTask();
		$meta = $task::getTaskMeta();
		$this->assertSame( 'extrachill_local_scene_digest', $task->getTaskType() );
		$this->assertSame( 'extrachill_local_scene_digest_enabled', $meta['setting_key'] );
		$this->assertFalse( $meta['default_enabled'] );
		$this->assertTrue( $meta['supports_run'] );
		$this->assertTrue( $meta['mutates'] );
		$this->assertTrue( $meta['supports_dry_run'] );
		$this->assertSame( array( 'days', 'limit', 'dry_run' ), $meta['params_schema']['accepted'] );
		$this->assertSame( 14, $meta['params_schema']['properties']['days']['maximum'] );
		$this->assertSame( 20, $meta['params_schema']['properties']['limit']['maximum'] );
		$this->assertFalse( $task->requiresAgentContext(), 'Recurring site-scoped execution must not require an agent envelope.' );
	}

	public function test_execute_fails_job_for_retryable_runner_evidence(): void {
		$GLOBALS['ec_test_systemtask_calls']          = array();
		$GLOBALS['ec_test_plugin_settings_reads']     = array();
		$GLOBALS['ec_test_plugin_settings']           = array( 'extrachill_local_scene_digest_enabled' => true );
		$task = new class() extends \ExtraChillEvents\Steps\LocalSceneDigest\LocalSceneDigestSystemTask {
			protected function runDigest( array $params ): array {
				return array(
					'counts'            => array( 'retryable_failures' => 1 ),
					'failures'          => array( 'candidate_query_failed' => 1 ),
					'retryable_failure' => true,
				);
			}
		};
		$task->executeTask( 41, array( 'dry_run' => true ) );

		$this->assertSame( array( 'extrachill_local_scene_digest_enabled', false ), $GLOBALS['ec_test_plugin_settings_reads'][0] );
		$this->assertSame( 'failJob', $GLOBALS['ec_test_systemtask_calls'][0]['method'] );
		$this->assertSame( 'Local Scene digest encountered a retryable delivery failure.', $GLOBALS['ec_test_systemtask_calls'][0]['message'] );
		$this->assertStringNotContainsString( 'location', strtolower( $GLOBALS['ec_test_systemtask_calls'][0]['message'] ) );
	}

	public function test_execute_completes_nonretryable_evidence(): void {
		$GLOBALS['ec_test_systemtask_calls'] = array();
		$GLOBALS['ec_test_plugin_settings']  = array( 'extrachill_local_scene_digest_enabled' => true );
		$task = new class() extends \ExtraChillEvents\Steps\LocalSceneDigest\LocalSceneDigestSystemTask {
			protected function runDigest( array $params ): array {
				return array(
					'counts'            => array( 'candidate_queries_truncated' => 1, 'retryable_failures' => 0 ),
					'failures'          => array( 'candidate_query_truncated' => 1 ),
					'retryable_failure' => false,
				);
			}
		};
		$task->executeTask( 42, array() );

		$this->assertSame( 'completeJob', $GLOBALS['ec_test_systemtask_calls'][0]['method'] );
		$this->assertFalse( $GLOBALS['ec_test_systemtask_calls'][0]['data']['retryable_failure'] );
		$this->assertSame( 1, $GLOBALS['ec_test_systemtask_calls'][0]['data']['counts']['candidate_queries_truncated'] );
	}

	public function test_recurring_schedule_is_default_disabled_and_explicitly_delivers(): void {
		$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/extrachill-events.php' );
		$this->assertIsString( $bootstrap );
		$this->assertMatchesRegularExpression( "/'enabled_setting'\\s*=>\\s*'extrachill_local_scene_digest_enabled'/", $bootstrap );
		$this->assertMatchesRegularExpression( "/'default_enabled'\\s*=>\\s*false/", $bootstrap );
		$this->assertMatchesRegularExpression( "/'dry_run'\\s*=>\\s*false/", $bootstrap );
	}
}
