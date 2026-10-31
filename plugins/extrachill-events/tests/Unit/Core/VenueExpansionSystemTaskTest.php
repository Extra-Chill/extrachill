<?php
/** Venue expansion Data Machine task contract tests. */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\VenueExpansionRunner;
use ExtraChillEvents\Steps\VenueExpansion\VenueExpansionSystemTask;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/system-task-base-stub.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueExpansionRunner.php';
require_once dirname( __DIR__, 3 ) . '/inc/Steps/VenueExpansion/VenueExpansionSystemTask.php';

class VenueExpansionSystemTaskTest extends TestCase {
	public function test_task_is_batch_only_and_site_scoped(): void {
		$task = new VenueExpansionSystemTask();
		$meta = $task::getTaskMeta();

		$this->assertSame( 'extrachill_venue_expansion', $task->getTaskType() );
		$this->assertTrue( $task->requiresAgentContext() );
		$this->assertFalse( $meta['supports_run'] );
		$this->assertTrue( $meta['mutates'] );
	}

	public function test_task_completes_with_machine_readable_report(): void {
		$GLOBALS['ec_test_systemtask_calls'] = array();
		$task = new class() extends VenueExpansionSystemTask {
			protected function runCity( array $params ): array {
				$report           = VenueExpansionRunner::emptyReport( 'Austin, TX', 1 );
				$report['status'] = 'completed';
				return $report;
			}

			protected function completeJob( int $jobId, array $data ): void {
				$GLOBALS['ec_test_systemtask_calls'][] = array(
					'method' => 'completeJob',
					'job_id' => $jobId,
					'data'   => $data,
				);
			}
		};
		$task->executeTask( 91, array() );

		$this->assertSame( 'completeJob', $GLOBALS['ec_test_systemtask_calls'][0]['method'] );
		$this->assertSame( VenueExpansionRunner::REPORT_SCHEMA, $GLOBALS['ec_test_systemtask_calls'][0]['data']['venue_expansion_report']['schema'] );
	}

	public function test_task_fails_for_retryable_partial_city_failure(): void {
		$GLOBALS['ec_test_systemtask_calls'] = array();
		$task = new class() extends VenueExpansionSystemTask {
			protected function runCity( array $params ): array {
				return array(
					'retryable_failure' => true,
					'error'             => array( 'message' => 'Rate limited.' ),
				);
			}

			protected function persistPartialReport( int $job_id, array $report ): void {
				$GLOBALS['ec_test_partial_report'] = $report;
			}

			protected function failJob( int $jobId, string $message ): void {
				$GLOBALS['ec_test_systemtask_calls'][] = array(
					'method'  => 'failJob',
					'job_id'  => $jobId,
					'message' => $message,
				);
			}
		};
		$task->executeTask( 92, array() );

		$this->assertSame( 'failJob', $GLOBALS['ec_test_systemtask_calls'][0]['method'] );
		$this->assertSame( 'Rate limited.', $GLOBALS['ec_test_partial_report']['error']['message'] );
	}
}
