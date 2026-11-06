<?php
/**
 * Minimal Data Machine SystemTask stand-in for fatal alarm task tests.
 *
 * Data Machine is not a validation dependency of this plugin, so the real
 * base class is absent under PHPUnit. Defined only when missing.
 *
 * @package ExtraChill\Analytics
 */

namespace DataMachine\Engine\AI\System\Tasks;

if ( ! class_exists( SystemTask::class, false ) ) {
	/**
	 * Records job completion instead of persisting it.
	 */
	abstract class SystemTask {

		/**
		 * Result passed to completeJob().
		 *
		 * @var mixed
		 */
		public $completed;

		/**
		 * Record a completed job.
		 *
		 * @param int   $job_id Job ID.
		 * @param mixed $result Job result.
		 */
		public function completeJob( $job_id, $result ) {
			unset( $job_id );
			$this->completed = $result;
		}

		/**
		 * Fail a job loudly.
		 *
		 * @param int    $job_id Job ID.
		 * @param string $reason Failure reason.
		 * @throws \RuntimeException Always.
		 */
		public function failJob( $job_id, $reason ) {
			unset( $job_id );
			throw new \RuntimeException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture.
		}
	}
}
