<?php
/**
 * data-machine-events soft-dependency double: EventDatesTable.
 *
 * Real method signatures mirrored from
 * \DataMachineEvents\Core\EventDatesTable so the resolver's class_exists()
 * guard in extrachill_get_promoted_events_for_location_term() sees it as
 * present, matching the "dependency available" branch. The "dependency
 * missing" branch is covered by a source-level assertion in
 * PromotedEventsResolverTest — the guard can't be exercised in the same
 * process once this class is declared.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace DataMachineEvents\Core;

if ( ! class_exists( __NAMESPACE__ . '\\EventDatesTable' ) ) {
	class EventDatesTable {
		public static function table_name(): string {
			return 'wp_datamachine_event_dates';
		}

		public static function get( int $post_id ): ?object {
			return $GLOBALS['test_event_dates'][ $post_id ] ?? null;
		}
	}
}
