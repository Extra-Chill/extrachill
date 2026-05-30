<?php
/**
 * Stub for DataMachine\Engine\AI\System\Tasks\SystemTask base class.
 *
 * The real class is defined in the data-machine plugin. For unit tests
 * that exercise our QualifyDigestSystemTask scaffolding we only need
 * something concrete to extend.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace DataMachine\Engine\AI\System\Tasks;

if ( ! class_exists( __NAMESPACE__ . '\\SystemTask' ) ) {
	abstract class SystemTask {

		abstract public function getTaskType(): string;

		abstract public function executeTask( int $jobId, array $params ): void;

		public static function getTaskMeta(): array {
			return array(
				'label'           => '',
				'description'     => '',
				'setting_key'     => null,
				'default_enabled' => true,
			);
		}

		protected function completeJob( int $jobId, array $data ): void {
			$GLOBALS['ec_test_systemtask_calls'][] = array(
				'method' => 'completeJob',
				'job_id' => $jobId,
				'data'   => $data,
			);
		}

		protected function failJob( int $jobId, string $message ): void {
			$GLOBALS['ec_test_systemtask_calls'][] = array(
				'method'  => 'failJob',
				'job_id'  => $jobId,
				'message' => $message,
			);
		}
	}
}
