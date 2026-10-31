<?php
/**
 * Display preference reporting from existing pageview JSON.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the network report ability.
 */
function extrachill_analytics_register_display_preferences_ability() {
	wp_register_ability(
		'extrachill/get-display-preferences',
		array(
			'label'               => __( 'Get Display Preferences', 'extrachill-analytics' ),
			'description'         => __( 'Dark, light and unknown counts and shares for pageviews and unique visitors, overall and by blog.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'start_date'   => array(
						'type'        => 'string',
						'description' => 'Inclusive UTC Y-m-d; must be paired with end_date.',
					),
					'end_date'     => array(
						'type'        => 'string',
						'description' => 'Inclusive UTC Y-m-d.',
					),
					'days'         => array(
						'type'    => 'integer',
						'default' => 28,
					),
					'end_days_ago' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'blog_id'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => 'UTC window, overall and by_blog splits. Shares are fractions (0–1).',
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_display_preferences',
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
 * Query aggregate splits without fetching visitor identifiers into PHP.
 *
 * @param  array $input Report parameters.
 * @return array|WP_Error
 */
function extrachill_analytics_ability_get_display_preferences( $input ) {
	global $wpdb;

	$range = extrachill_analytics_resolve_date_range( $input );
	if ( is_wp_error( $range ) ) {
		return $range;
	}
	$days    = isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : 28;
	$offset  = isset( $input['end_days_ago'] ) ? max( 0, (int) $input['end_days_ago'] ) : 0;
	$blog_id = isset( $input['blog_id'] ) ? max( 0, (int) $input['blog_id'] ) : 0;
	$until   = $range ? $range['end_exclusive'] : gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$offset} days" ) );
	$since   = $range ? $range['start_at'] : gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . ( $days + $offset ) . ' days' ) );
	$table   = extrachill_analytics_events_table();
	$where   = 'event_type = %s AND created_at >= %s AND created_at < %s';
	$values  = array( 'pageview', $since, $until );
	if ( $blog_id > 0 ) {
		$where   .= ' AND blog_id = %d';
		$values[] = $blog_id;
	}

	$scheme = "CASE
		WHEN JSON_EXTRACT(event_data, '$.color_scheme') = JSON_EXTRACT('\"dark\"', '$') THEN 'dark'
		WHEN JSON_EXTRACT(event_data, '$.color_scheme') = JSON_EXTRACT('\"light\"', '$') THEN 'light'
		ELSE 'unknown' END";
	$result = array(
		'since'      => $since,
		'until'      => $until,
		'start_date' => $range ? $range['start_date'] : substr( $since, 0, 10 ),
		'end_date'   => $range ? $range['end_date'] : substr( $until, 0, 10 ),
		'blog_id'    => $blog_id,
		'overall'    => array(),
		'by_blog'    => array(),
		'definition' => 'Shares are fractions of the scope total. Unique visitors exclude NULL/empty visitor_id and use their latest pageview in the window, independently per blog and overall (created_at, then id). Missing or invalid color_scheme is unknown, including legacy rows. Anonymous views still count in pageviews.',
	);

	foreach ( array( false, true ) as $by_blog ) {
		$select = $by_blog ? 'blog_id' : '0 AS blog_id';
		$group  = $by_blog ? 'blog_id, ' : '';
		foreach ( array( 'pageviews', 'unique_visitors' ) as $metric ) {
			if ( 'pageviews' === $metric ) {
				$sql = "SELECT {$select}, {$scheme} AS color_scheme, COUNT(*) AS count FROM {$table} WHERE {$where} GROUP BY {$group}color_scheme";
			} else {
				// Retain exactly one observation per visitor per scope. The
				// visitor/time index bounds this anti-join to that visitor.
				$blog_match = ( $by_blog || $blog_id > 0 ) ? ' AND newer.blog_id = views.blog_id' : '';
				$sql        = "SELECT {$select}, {$scheme} AS color_scheme, COUNT(*) AS count
					FROM {$table} AS views WHERE {$where} AND visitor_id IS NOT NULL AND visitor_id != ''
					AND NOT EXISTS (
						SELECT 1 FROM {$table} AS newer
						WHERE newer.visitor_id = views.visitor_id AND newer.event_type = views.event_type
						AND newer.created_at >= %s AND newer.created_at < %s {$blog_match}
						AND (newer.created_at > views.created_at OR (newer.created_at = views.created_at AND newer.id > views.id))
					) GROUP BY {$group}color_scheme";
			}
			// phpcs:ignore WordPress.DB.PreparedSQL -- Code-defined table/expressions; all request values bound below.
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, 'unique_visitors' === $metric ? array_merge( $values, array( $since, $until ) ) : $values ) );
			$splits = extrachill_analytics_display_preference_splits( (array) $rows );
			if ( ! $by_blog ) {
				$result['overall'][ $metric ] = $splits[0] ?? extrachill_analytics_display_preference_split( array() );
			} else {
				foreach ( $splits as $id => $split ) {
					$result['by_blog'][ $id ][ $metric ] = $split;
				}
			}
		}
	}
	foreach ( $result['by_blog'] as $id => $metrics ) {
		if ( ! isset( $metrics['unique_visitors'] ) ) {
			$result['by_blog'][ $id ]['unique_visitors'] = extrachill_analytics_display_preference_split( array() );
		}
	}
	return $result;
}

/**
 * Convert database aggregate rows into per-scope counts and shares.
 *
 * @param  array $rows Aggregate rows.
 * @return array
 */
function extrachill_analytics_display_preference_splits( array $rows ): array {
	$counts = array();
	foreach ( $rows as $row ) {
		$counts[ (int) $row->blog_id ][ $row->color_scheme ] = (int) $row->count;
	}
	return array_map( 'extrachill_analytics_display_preference_split', $counts );
}

/**
 * Build a zero-safe exclusive three-bucket split.
 *
 * @param  array $counts Bucket counts.
 * @return array
 */
function extrachill_analytics_display_preference_split( array $counts ): array {
	$counts = array_replace(
		array(
			'dark'    => 0,
			'light'   => 0,
			'unknown' => 0,
		),
		$counts
	);
	$total  = array_sum( $counts );
	$shares = array();
	foreach ( $counts as $scheme => $count ) {
		$shares[ $scheme ] = $total > 0 ? round( $count / $total, 4 ) : 0.0;
	}
	return array(
		'total'  => $total,
		'counts' => $counts,
		'shares' => $shares,
	);
}
