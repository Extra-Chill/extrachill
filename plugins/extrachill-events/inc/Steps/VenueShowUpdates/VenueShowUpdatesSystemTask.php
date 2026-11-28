<?php
/**
 * Weekly venue show-updates system task (#1724).
 *
 * @package ExtraChillEvents\Steps\VenueShowUpdates
 */

namespace ExtraChillEvents\Steps\VenueShowUpdates;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Core\PluginSettings;

/** Bridge the venue show-updates runner into Data Machine's recurring task registry. */
class VenueShowUpdatesSystemTask extends SystemTask {
	public const TASK_TYPE   = 'extrachill_venue_show_updates';
	public const SETTING_KEY = 'extrachill_venue_show_updates_enabled';

	/** Return the stable task type. */
	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	/** Allow this site-scoped recurring task to run without agent ownership. */
	public function requiresAgentContext(): bool {
		return false;
	}

	/** Return task registry metadata. */
	public static function getTaskMeta(): array {
		return array(
			'label'            => 'Venue Show Updates (weekly)',
			'description'      => 'Emails "This week at {venue}" to fans who opted in to a venue\'s show updates.',
			'setting_key'      => self::SETTING_KEY,
			'default_enabled'  => false,
			'supports_run'     => true,
			'mutates'          => true,
			'supports_dry_run' => true,
			'params_schema'    => array(
				'type'       => 'object',
				'accepted'   => array( 'days', 'limit', 'dry_run' ),
				'properties' => array(
					'days'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 14,
					),
					'limit'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 20,
					),
					'dry_run' => array( 'type' => 'boolean' ),
				),
			),
		);
	}

	/**
	 * Run the feature-gated venue digest.
	 *
	 * @param int   $job_id Data Machine job ID.
	 * @param array $params Digest parameters.
	 */
	public function executeTask( int $job_id, array $params ): void {
		if ( empty( $params['dry_run'] ) && ! (bool) PluginSettings::get( self::SETTING_KEY, false ) ) {
			$this->completeJob(
				$job_id,
				array(
					'skipped' => true,
					'reason'  => 'Weekly venue show updates disabled.',
				)
			);
			return;
		}
		$result = $this->runDigest( $params );
		if ( ! empty( $result['retryable_failure'] ) ) {
			$this->failJob( $job_id, 'Venue show updates encountered a retryable delivery failure.' );
			return;
		}
		$this->completeJob( $job_id, $result );
	}

	/**
	 * Run the venue digest and return its privacy-safe aggregate evidence.
	 *
	 * @param array $params Bounded digest parameters.
	 * @return array
	 */
	protected function runDigest( array $params ): array {
		return extrachill_events_run_venue_show_updates( $params );
	}
}
