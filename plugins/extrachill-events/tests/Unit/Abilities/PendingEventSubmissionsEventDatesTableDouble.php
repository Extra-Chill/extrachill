<?php
/** EventDatesTable double for the pending-event-submissions unit suite. */

namespace DataMachineEvents\Core;

if ( ! class_exists( EventDatesTable::class ) ) {
	class EventDatesTable {
		public static function get( int $post_id ) {
			return $GLOBALS['pending_digest_event_dates'][ $post_id ] ?? null;
		}
	}
}
