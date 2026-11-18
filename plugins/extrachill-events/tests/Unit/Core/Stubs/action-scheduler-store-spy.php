<?php
/**
 * Minimal Action Scheduler double that records cancelled action IDs.
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! class_exists( 'ActionScheduler' ) ) {
	/**
	 * Action Scheduler facade double; its store is itself.
	 */
	class ActionScheduler {
		/**
		 * Cancelled action IDs, in call order.
		 *
		 * @var int[]
		 */
		public static $cancelled = array();

		/**
		 * Return the store double.
		 */
		public static function store(): self {
			return new self();
		}

		/**
		 * Record a cancellation.
		 *
		 * @param int $action_id Action ID.
		 */
		public function cancel_action( $action_id ): void {
			self::$cancelled[] = (int) $action_id;
		}
	}
}
