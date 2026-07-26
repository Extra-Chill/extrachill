<?php
/**
 * Data Machine task for the bounded delegated campaign action.
 *
 * @package ExtraChillNewsletter\Tasks
 */

namespace ExtraChillNewsletter\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

defined( 'ABSPATH' ) || exit;

final class DelegatedCampaignTask extends SystemTask {

	/** Execute the Newsletter-owned operation. */
	public function executeTask( int $jobId, array $params ): void {
		$input = \extrachill_newsletter_verify_delegated_campaign_task( $params );
		if ( \is_wp_error( $input ) ) {
			$this->failJob( $jobId, (string) $input->get_error_code() );
			return;
		}

		$result        = \extrachill_newsletter_execute_delegated_campaign( $input );
		$operation_ref = isset( $params['owner_context']['operation_ref'] ) && is_string( $params['owner_context']['operation_ref'] ) ? $params['owner_context']['operation_ref'] : '';
		if ( ! \extrachill_newsletter_record_delegated_campaign_outcome( $operation_ref, $result ) ) {
			$this->failJob( $jobId, 'newsletter_campaign_receipt_failed' );
			return;
		}
		if ( 'failed' === ( $result['status'] ?? '' ) ) {
			$this->failJob( $jobId, (string) ( $result['error_code'] ?? 'newsletter_campaign_failed' ) );
			return;
		}

		$this->completeJob(
			$jobId,
			array(
				'effect_count'        => 'executed' === ( $result['status'] ?? '' ) ? 1 : 0,
				'newsletter_campaign' => $result,
			)
		);
	}

	/** Return the stable owner task identifier. */
	public function getTaskType(): string {
		return EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_TASK;
	}

	/** This deterministic owner task does not require an agent envelope. */
	public function requiresAgentContext(): bool {
		return false;
	}
}
