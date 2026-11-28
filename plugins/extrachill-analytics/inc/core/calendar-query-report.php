<?php
/**
 * Human calendar usage breakdown for WP-CLI.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Aggregate calendar interactions in a bounded date window, paging by ID.
 *
 * @param int $days    Days to look back (1–90, including today, UTC).
 * @param int $blog_id Site ID, or zero for the network.
 * @param int $limit   Maximum entries per top-N breakdown.
 * @return array Report buckets.
 */
function extrachill_analytics_calendar_query_report( int $days, int $blog_id, int $limit ): array {
	$days = max( 1, min( 90, $days ) );
	$args = array(
		'event_type' => EC_ANALYTICS_EVENT_CALENDAR_QUERY,
		'blog_id'    => $blog_id,
		'date_from'  => gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ),
		'orderby'    => 'id',
		'limit'      => 1000,
	);

	$triggers = array();
	$visitors = array();
	$top      = array(
		'scopes'       => array(),
		'taxonomies'   => array(),
		'search_terms' => array(),
	);
	$zeros    = 0;

	do {
		$rows = extrachill_get_analytics_events( $args );
		foreach ( $rows as $row ) {
			$args['before_id'] = (int) $row->id;
			$data              = $row->event_data;
			$trigger           = $data['trigger'] ?? '';
			if ( ! in_array( $trigger, EC_ANALYTICS_CALENDAR_TRIGGERS, true ) || ! empty( $data['is_bot'] ) ) {
				continue;
			}

			$triggers[ $trigger ] = ( $triggers[ $trigger ] ?? 0 ) + 1;
			if ( ! empty( $row->visitor_id ) ) {
				$visitors[ $trigger ][ $row->visitor_id ] = true;
			}
			if ( 0 === ( $data['result_count'] ?? null ) ) {
				++$zeros;
			}

			$scope                   = empty( $data['scope'] ) ? 'all' : $data['scope'];
			$top['scopes'][ $scope ] = ( $top['scopes'][ $scope ] ?? 0 ) + 1;
			$search                  = $data['search_term'] ?? '';
			if ( '' !== $search ) {
				$top['search_terms'][ $search ] = ( $top['search_terms'][ $search ] ?? 0 ) + 1;
			}
			foreach ( $data['taxonomies'] ?? array() as $taxonomy => $terms ) {
				foreach ( array_unique( $terms ) as $term ) {
					$key                       = $taxonomy . ':' . $term;
					$top['taxonomies'][ $key ] = ( $top['taxonomies'][ $key ] ?? 0 ) + 1;
				}
			}
		}
		$page_count = count( $rows );
	} while ( $page_count === $args['limit'] );

	$result = array(
		'triggers'     => array(),
		'zero_results' => $zeros,
	);
	foreach ( $triggers as $trigger => $count ) {
		$result['triggers'][] = array(
			'trigger'               => $trigger,
			'queries'               => $count,
			'unique_human_visitors' => count( $visitors[ $trigger ] ?? array() ),
		);
	}
	foreach ( $top as $bucket => $counts ) {
		ksort( $counts, SORT_STRING );
		arsort( $counts );
		$result[ $bucket ] = array();
		foreach ( array_slice( $counts, 0, max( 1, $limit ), true ) as $value => $count ) {
			$result[ $bucket ][] = array(
				'value'   => (string) $value,
				'queries' => $count,
			);
		}
	}
	return $result;
}
