<?php
/**
 * data-machine-events soft-dependency double: UpcomingFilter.
 *
 * Real method signature mirrored from
 * \DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter::upcoming_sql()
 * so the resolver's class_exists() guard in
 * extrachill_get_promoted_events_for_location_term() sees it as present,
 * matching the "dependency available" branch. The "dependency missing"
 * branch is covered by a source-level assertion in
 * PromotedEventsResolverTest — the guard can't be exercised in the same
 * process once this class is declared.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace DataMachineEvents\Blocks\Calendar\Query;

if ( ! class_exists( __NAMESPACE__ . '\\UpcomingFilter' ) ) {
	class UpcomingFilter {
		public static function upcoming_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
			$status_clause = $include_status ? " AND ed.post_status = 'publish'" : '';
			return array(
				'joins'       => "INNER JOIN wp_datamachine_event_dates ed ON {$join_column} = ed.post_id",
				'where'       => "(ed.start_datetime >= %s OR ed.end_datetime >= %s){$status_clause}",
				'param_count' => 2,
			);
		}
	}
}
