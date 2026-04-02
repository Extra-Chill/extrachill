<?php
/**
 * Giveaway Task
 *
 * Thin wrapper around the extrachill/run-giveaway ability for the
 * Data Machine Task System. Makes giveaways schedulable via
 * TaskScheduler::schedule(), Action Scheduler, CLI, and REST.
 *
 * @package    ExtraChillStudio
 * @subpackage Tasks
 * @since      0.5.0
 */

namespace ExtraChillStudio\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

defined( 'ABSPATH' ) || exit;

class GiveawayTask extends SystemTask {

	/**
	 * Execute the giveaway.
	 *
	 * @param int   $jobId  Job ID from the DM job engine.
	 * @param array $params Task parameters (same shape as run-giveaway ability input).
	 * @return void
	 */
	public function execute( int $jobId, array $params ): void {
		$ability = wp_get_ability( 'extrachill/run-giveaway' );

		if ( ! $ability ) {
			$this->failJob( $jobId, 'extrachill/run-giveaway ability not available.' );
			return;
		}

		$result = $ability->execute( $params );

		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$this->completeJob( $jobId, $result );
	}

	/**
	 * Task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'giveaway';
	}

	/**
	 * Task metadata for the registry.
	 *
	 * @return array
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Giveaway',
			'description'     => 'Pick random winners from Instagram post comments and optionally announce them.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'Manual or scheduled',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
		);
	}
}
