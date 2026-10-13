<?php
/** Daily reviewer reminder for pending public event submissions. */

namespace ExtraChillEvents\Steps\PendingEventSubmissions;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

class PendingEventSubmissionsSystemTask extends SystemTask {
	public const TASK_TYPE = 'extrachill_pending_event_submissions';

	public function getTaskType(): string { return self::TASK_TYPE; }
	public function requiresAgentContext(): bool { return false; }

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Pending Event Submissions (daily)',
			'description'     => 'Daily reviewer digest of pending public event submissions.',
			'setting_key'     => 'extrachill_pending_event_submissions_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$ability = new \ExtraChillEvents\Abilities\PendingEventSubmissionsAbility();
		$this->completeJob( $jobId, $ability->sendDigest() );
	}
}
