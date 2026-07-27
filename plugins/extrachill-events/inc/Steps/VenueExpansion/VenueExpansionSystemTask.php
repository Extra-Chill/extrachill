<?php
/** Data Machine task for one bounded city expansion. */

namespace ExtraChillEvents\Steps\VenueExpansion;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use ExtraChillEvents\Core\VenueExpansionRunner;

class VenueExpansionSystemTask extends SystemTask {
	public const TASK_TYPE = 'extrachill_venue_expansion';

	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'            => 'Venue expansion (one city)',
			'description'      => 'Runs bounded venue discovery, qualification, and flow creation for one city.',
			'setting_key'      => null,
			'default_enabled'  => true,
			'supports_run'     => false,
			'mutates'          => true,
			'supports_dry_run' => false,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$report = $this->runCity( $params );
		if ( ! empty( $report['retryable_failure'] ) ) {
			$this->persistPartialReport( $jobId, $report );
			$this->failJob( $jobId, 'Venue expansion stopped after a partial failure; rerun explicitly to resume.' );
			return;
		}
		$this->completeJob( $jobId, array( 'venue_expansion_report' => $report ) );
	}

	protected function runCity( array $params ): array {
		return ( new VenueExpansionRunner() )->runCity( $params );
	}

	/** Persist partial evidence before entering Data Machine's failure path. */
	protected function persistPartialReport( int $job_id, array $report ): void {
		$jobs_db                               = new Jobs();
		$engine_data                           = $jobs_db->retrieve_engine_data( $job_id );
		$engine_data['venue_expansion_report'] = $report;
		$jobs_db->store_engine_data( $job_id, $engine_data );
	}
}
