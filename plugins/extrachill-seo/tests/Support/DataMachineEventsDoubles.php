<?php
/**
 * Shared test doubles for the Data Machine Events APIs this plugin consumes.
 *
 * One declaration keeps every test (and static analysis) on the full owner
 * contract: EventDatesTable::get()/table_name() and UpcomingFilter's
 * upcoming_where()/past_where() SQL primitives.
 *
 * @package ExtraChill\SEO\Tests
 */

namespace DataMachineEvents\Core {
	if ( ! class_exists( EventDatesTable::class ) ) {
		class EventDatesTable {
			public static $dates;
			public static function get( int $post_id ): ?object {
				return self::$dates;
			}
			public static function table_name(): string {
				return 'wp_7_datamachine_event_dates';
			}
		}
	}
}

namespace DataMachineEvents\Blocks\Calendar\Query {
	if ( ! class_exists( UpcomingFilter::class ) ) {
		class UpcomingFilter {
			public static function upcoming_where( string $datetime ): string {
				return "(ed.start_datetime >= '{$datetime}' OR ed.end_datetime >= '{$datetime}')";
			}
			// Matches the owning plugin's public SQL contract.
			public static function past_where( string $datetime ): string {
				return "(ed.start_datetime < '{$datetime}' AND (ed.end_datetime < '{$datetime}' OR ed.end_datetime IS NULL))";
			}
		}
	}
}
