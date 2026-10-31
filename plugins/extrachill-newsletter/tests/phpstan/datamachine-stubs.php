<?php
/**
 * Static-analysis declarations for the public Data Machine task contract.
 *
 * @package ExtraChillNewsletter\Tests
 */

namespace DataMachine\Engine\AI\System\Tasks;

abstract class SystemTask {
	abstract public function executeTask( int $jobId, array $params ): void;
	abstract public function getTaskType(): string;
	public function requiresAgentContext(): bool {}
	protected function completeJob( int $jobId, array $data ): void {}
	protected function failJob( int $jobId, string $message ): void {}
}
