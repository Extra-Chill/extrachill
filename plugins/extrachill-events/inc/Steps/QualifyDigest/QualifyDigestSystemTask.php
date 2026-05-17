<?php
/**
 * Qualify Digest — Weekly system task.
 *
 * Bridges the extrachill/qualify-digest ability into Data Machine's
 * recurring system-task surface. Registered via the datamachine_tasks
 * filter; scheduled weekly (Mondays 09:00 UTC by default) via
 * datamachine_recurring_schedules.
 *
 * Gating: the dme_qualify_digest_enabled network option must be true
 * (default) AND the wp_get_ability( extrachill/qualify-digest ) call must
 * resolve. Either condition failing produces a "skipped" outcome on the
 * task body, not a failure.
 *
 * @package ExtraChillEvents\Steps\QualifyDigest
 * @since   0.21.0
 */

namespace ExtraChillEvents\Steps\QualifyDigest;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

class QualifyDigestSystemTask extends SystemTask {

	public const TASK_TYPE = 'extrachill_qualify_digest';

	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Qualify Digest (weekly)',
			'description'     => 'Weekly summary of qualify v2 activity: paused/resumed flows, new venues, standing inventory, top extraction gaps, stale paused flows.',
			'setting_key'     => 'dme_qualify_digest_enabled',
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	/**
	 * Execute the digest ability and report outcome on the job.
	 *
	 * @param int   $jobId  Job ID.
	 * @param array $params Task params (forwarded to the ability).
	 */
	public function executeTask( int $jobId, array $params ): void {
		$enabled = (bool) get_site_option( 'dme_qualify_digest_enabled', true );
		if ( ! $enabled ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => 'Weekly digest disabled (dme_qualify_digest_enabled=false).',
				)
			);
			return;
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->failJob( $jobId, 'Abilities API not available.' );
			return;
		}

		$ability = wp_get_ability( 'extrachill/qualify-digest' );
		if ( ! $ability ) {
			$this->failJob( $jobId, 'extrachill/qualify-digest ability not registered.' );
			return;
		}

		$input = array(
			'since'   => (string) ( $params['since'] ?? '1 week ago' ),
			'format'  => (string) ( $params['format'] ?? 'html' ),
			'dry_run' => (bool) ( $params['dry_run'] ?? false ),
		);
		if ( ! empty( $params['recipient'] ) ) {
			$input['recipient'] = (string) $params['recipient'];
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$counts = is_array( $result ) ? ( $result['counts'] ?? array() ) : array();
		$this->completeJob(
			$jobId,
			array(
				'sent'      => (bool) ( $result['sent'] ?? false ),
				'dry_run'   => (bool) ( $result['dry_run'] ?? false ),
				'recipient' => (string) ( $result['recipient'] ?? '' ),
				'counts'    => $counts,
			)
		);
	}
}
