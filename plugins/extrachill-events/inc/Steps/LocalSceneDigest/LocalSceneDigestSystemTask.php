<?php
/**
 * Weekly Local Scene digest system task.
 *
 * @package ExtraChillEvents\Steps\LocalSceneDigest
 */

namespace ExtraChillEvents\Steps\LocalSceneDigest;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Core\PluginSettings;

/** Bridge the digest runner into Data Machine's recurring task registry. */
class LocalSceneDigestSystemTask extends SystemTask {
	public const TASK_TYPE = 'extrachill_local_scene_digest';

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
			'label'            => 'Local Scene Digest (weekly)',
			'description'      => 'Delivers bounded weekly event picks to explicit Local Scene subscribers.',
			'setting_key'      => 'extrachill_local_scene_digest_enabled',
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
	 * Run the feature-gated digest.
	 *
	 * @param int   $jobId  Data Machine job ID.
	 * @param array $params Digest parameters.
	 */
	public function executeTask( int $jobId, array $params ): void {
		if ( ! (bool) PluginSettings::get( 'extrachill_local_scene_digest_enabled', false ) ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => 'Weekly Local Scene digest disabled.',
				)
			);
			return;
		}
		$result = $this->runDigest( $params );
		if ( ! empty( $result['retryable_failure'] ) ) {
			$this->failJob( $jobId, 'Local Scene digest encountered a retryable delivery failure.' );
			return;
		}
		$this->completeJob( $jobId, $result );
	}

	/**
	 * Run the digest and return its privacy-safe aggregate evidence.
	 *
	 * @param array $params Bounded digest parameters.
	 * @return array
	 */
	protected function runDigest( array $params ): array {
		return extrachill_events_run_local_scene_digest( $params );
	}
}
