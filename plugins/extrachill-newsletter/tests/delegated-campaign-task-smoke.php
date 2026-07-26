<?php
/**
 * Smoke tests for the delegated campaign Data Machine task adapter.
 *
 * Run with: php tests/delegated-campaign-task-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_TASK', 'extrachill_newsletter_delegated_campaign' );
	$GLOBALS['newsletter_task_test'] = array(
		'mode'      => 'executed',
		'completed' => array(),
		'failed'    => array(),
	);

	class WP_Error {
		public function __construct( private string $code ) {}
		public function get_error_code(): string {
			return $this->code;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function extrachill_newsletter_verify_delegated_campaign_task( $params ) {
		return 'forbidden' === $GLOBALS['newsletter_task_test']['mode']
			? new WP_Error( 'newsletter_campaign_task_forbidden' )
			: $params;
	}

	function extrachill_newsletter_record_delegated_campaign_outcome( $operation_ref, $result ) {
		$GLOBALS['newsletter_task_test']['outcome'] = array( $operation_ref, $result );
		return true;
	}

	function extrachill_newsletter_execute_delegated_campaign( $params ) {
		unset( $params );
		$mode = $GLOBALS['newsletter_task_test']['mode'];
		return array(
			'status'     => $mode,
			'record'     => 'executed' === $mode ? array( 'newsletter_post_id' => 12, 'campaign_id' => 'campaign-12' ) : null,
			'error_code' => 'failed' === $mode ? 'newsletter_campaign_push_failed' : null,
		);
	}
}

namespace DataMachine\Engine\AI\System\Tasks {
	abstract class SystemTask {
		abstract public function executeTask( int $jobId, array $params ): void;
		abstract public function getTaskType(): string;

		protected function completeJob( int $jobId, array $data ): void {
			$GLOBALS['newsletter_task_test']['completed'][] = array( $jobId, $data );
		}

		protected function failJob( int $jobId, string $message ): void {
			$GLOBALS['newsletter_task_test']['failed'][] = array( $jobId, $message );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/core/tasks/class-delegated-campaign-task.php';

	$failures = array();
	$passes   = 0;
	$assert   = static function ( $condition, $message ) use ( &$failures, &$passes ) {
		if ( $condition ) {
			++$passes;
			return;
		}
		$failures[] = $message;
	};
	$task = new \ExtraChillNewsletter\Tasks\DelegatedCampaignTask();
	$assert( 'extrachill_newsletter_delegated_campaign' === $task->getTaskType(), 'task exposes the frozen owner task type' );
	$assert( false === $task->requiresAgentContext(), 'task uses the stable owner user without an agent requirement' );

	$task->executeTask( 41, array( 'source' => array( 'site_id' => 7, 'post_id' => 901 ), 'owner_context' => array( 'operation_ref' => 'dop_' . str_repeat( 'a', 64 ) ) ) );
	$assert( 1 === $GLOBALS['newsletter_task_test']['completed'][0][1]['effect_count'], 'executed campaign records one consequential effect' );
	$assert( array() === $GLOBALS['newsletter_task_test']['failed'], 'successful campaign does not fail the task' );
	$assert( 'dop_' . str_repeat( 'a', 64 ) === $GLOBALS['newsletter_task_test']['outcome'][0], 'task persists outcome under the exact operation reference' );

	$GLOBALS['newsletter_task_test']['mode'] = 'no-op';
	$task->executeTask( 42, array() );
	$assert( 0 === $GLOBALS['newsletter_task_test']['completed'][1][1]['effect_count'], 'no-op completes with zero effects' );

	$GLOBALS['newsletter_task_test']['mode'] = 'failed';
	$task->executeTask( 43, array() );
	$assert( array( 43, 'newsletter_campaign_push_failed' ) === $GLOBALS['newsletter_task_test']['failed'][0], 'failure exposes only the bounded owner code' );

	$GLOBALS['newsletter_task_test']['mode'] = 'forbidden';
	$task->executeTask( 44, array() );
	$assert( array( 44, 'newsletter_campaign_task_forbidden' ) === $GLOBALS['newsletter_task_test']['failed'][1], 'direct task invocation fails before owner execution' );

	if ( $failures ) {
		fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
		exit( 1 );
	}

	echo "All {$passes} delegated campaign task assertions passed.\n";
}
