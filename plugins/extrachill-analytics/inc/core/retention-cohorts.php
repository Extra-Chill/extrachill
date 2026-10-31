<?php
/**
 * Retention cohort response helpers.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply observation-horizon semantics to exact-week cohort aggregates.
 *
 * @param array<object> $rows Cohort query rows.
 * @param string        $as_of UTC observation cutoff.
 * @return array<int,array<string,int|float|string|bool|null>> Cohort response rows.
 */
function extrachill_analytics_build_cohort_retention( $rows, $as_of ) {
	$cohorts = array();
	$as_of   = strtotime( $as_of . ' UTC' );

	foreach ( (array) $rows as $row ) {
		$week_start  = (string) $row->cohort_week_start;
		$week_time   = strtotime( $week_start . ' UTC' );
		$size        = (int) $row->cohort_size;
		$w1_mature   = false !== $week_time && $as_of >= $week_time + ( 14 * DAY_IN_SECONDS );
		$w2_mature   = false !== $week_time && $as_of >= $week_time + ( 21 * DAY_IN_SECONDS );
		$returned_w1 = (int) $row->returned_w1;
		$returned_w2 = (int) $row->returned_w2;

		$cohorts[] = array(
			// cohort_week remains for consumers that previously used YEARWEEK labels.
			'cohort_week'       => false !== $week_time ? gmdate( 'oW', $week_time ) : $week_start,
			'cohort_week_start' => $week_start,
			'cohort_size'       => $size,
			'w1_mature'         => $w1_mature,
			'w2_mature'         => $w2_mature,
			'returned_w1'       => $w1_mature ? $returned_w1 : null,
			'returned_w2'       => $w2_mature ? $returned_w2 : null,
			'retention_w1'      => $w1_mature && $size > 0 ? round( $returned_w1 / $size, 4 ) : null,
			'retention_w2'      => $w2_mature && $size > 0 ? round( $returned_w2 / $size, 4 ) : null,
		);
	}

	return $cohorts;
}
