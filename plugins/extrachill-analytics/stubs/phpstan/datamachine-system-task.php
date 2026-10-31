<?php
/**
 * PHPStan stub for Data Machine system tasks.
 *
 * extrachill-analytics registers `FatalRateAlarmTask` as a Data Machine system
 * task (issue #98). The `SystemTask` base lives in the Data Machine plugin,
 * which is not in this component's analysis scope, so this stub gives PHPStan
 * the minimal shape it needs to resolve the parent class and its protected job
 * lifecycle helpers.
 *
 * @package ExtraChill\Analytics\Stubs
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

/**
 * Static-analysis shape for the external Data Machine SystemTask class.
 */
abstract class SystemTask {

	/**
	 * @param array<string,mixed> $data Job result payload.
	 */
	protected function completeJob( int $jobId, array $data = array() ): void {
	}

	protected function failJob( int $jobId, string $message ): void {
	}
}
