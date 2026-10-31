<?php
/** Spy double for data-machine-events' SubmissionNotification (publish-email unit suite). */

namespace DataMachineEvents\Core;

if ( ! class_exists( SubmissionNotification::class ) ) {
	class SubmissionNotification {
		/** @var array<int,array{0:string,1:string,2:int}> */
		public static array $calls = array();

		public static function on_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
			self::$calls[] = array( $new_status, $old_status, $post->ID );
		}
	}
}
