<?php
/**
 * Get Analytics Summary Ability
 *
 * Read-side ability that returns event counts grouped by type
 * with optional date filtering.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-analytics-summary ability.
 */
function extrachill_analytics_register_summary_ability() {
	wp_register_ability(
		'extrachill/get-analytics-summary',
		array(
			'label'               => __( 'Get Analytics Summary', 'extrachill-analytics' ),
			'description'         => __( 'Returns event counts grouped by type, plus date, source, and context detail when one event type is requested.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'       => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'event_type' => array(
						'type'        => 'string',
						'description' => __( 'Filter to a specific event type. Empty for all types.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'blog_id'    => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Summary with event_types and total count. An event_type filter also adds by_date, by_source, and by_context arrays.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_summary',
			'permission_callback' => 'extrachill_analytics_can_read_reports',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for get-analytics-summary ability.
 *
 * @param array $input Input parameters.
 * @return array Summary data.
 */
function extrachill_analytics_ability_get_summary( $input ) {
	global $wpdb;

	$days       = isset( $input['days'] ) ? (int) $input['days'] : 28;
	$event_type = isset( $input['event_type'] ) ? sanitize_key( $input['event_type'] ) : '';
	$blog_id    = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	$table  = extrachill_analytics_events_table();
	$where  = array( '1=1' );
	$values = array();

	// Capture the exact UTC instant this summary is computed at, and the rolling
	// lower bound derived from it, so the reported counts are reproducible to the
	// second. created_at is stored in UTC via current_time( 'mysql', true ), so the
	// bound must be computed in UTC too. Both values are returned to the caller so a
	// raw COUNT(*) can be reproduced exactly against the same window — the summary
	// applies no dedup, DISTINCT, or normalization; it is a plain COUNT(*) over this
	// half-open-on-now, closed-on-since window.
	$now_utc = gmdate( 'Y-m-d H:i:s' );
	$since   = '';

	if ( $days > 0 ) {
		$since    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$where[]  = 'created_at >= %s';
		$values[] = $since;
	}

	if ( ! empty( $event_type ) ) {
		$where[]  = 'event_type = %s';
		$values[] = $event_type;
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	$where_clause = implode( ' AND ', $where );

	$sql = "SELECT event_type, COUNT(*) as count FROM {$table} WHERE {$where_clause} GROUP BY event_type ORDER BY count DESC";

	// phpcs:disable WordPress.DB.PreparedSQL -- $sql interpolates only a code-defined table name and a where_clause of %s/%d placeholders bound via prepare().
	if ( ! empty( $values ) ) {
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	} else {
		$results = $wpdb->get_results( $sql );
	}
	// phpcs:enable WordPress.DB.PreparedSQL

	$event_types = array();
	$total       = 0;

	foreach ( $results as $row ) {
		$count     = (int) $row->count;
		$daily_avg = $days > 0 ? round( $count / $days, 1 ) : $count;

		$event_types[] = array(
			'event_type' => $row->event_type,
			'count'      => $count,
			'daily_avg'  => $daily_avg,
		);

		$total += $count;
	}

	$summary = array(
		'event_types' => $event_types,
		'total'       => $total,
		'days'        => $days,
		'period'      => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		// Exact UTC window the counts were computed over. 'since' is the inclusive
		// lower bound (empty for all-time); 'as_of' is the instant the summary ran.
		// Counts are reproducible via a raw COUNT(*) using created_at >= since.
		'since'       => $since,
		'as_of'       => $now_utc,
	);

	// Preserve the compact all-event response while exposing the existing
	// aggregation detail when the caller requests one event type.
	if ( ! empty( $event_type ) && function_exists( 'extrachill_get_analytics_event_stats' ) ) {
		$detail = extrachill_get_analytics_event_stats( $event_type, $days, $blog_id );

		$summary['by_date']    = array_map(
			static function ( $row ) {
				return array(
					'date'  => (string) $row->date,
					'count' => (int) $row->count,
				);
			},
			(array) $detail['by_date']
		);
		$summary['by_source']  = array_map(
			static function ( $row ) {
				return array(
					'source_url' => (string) $row->source_url,
					'count'      => (int) $row->count,
				);
			},
			(array) $detail['by_source']
		);
		$summary['by_context'] = array_map(
			static function ( $row ) {
				return array(
					'context' => (string) $row->context,
					'count'   => (int) $row->count,
				);
			},
			(array) $detail['by_context']
		);
	}

	return $summary;
}
