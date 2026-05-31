<?php
/**
 * Get Scanner 404 Summary Ability
 *
 * Read-side ability that surfaces the URL/path scanner storm — the slice of
 * 404_error events whose categorized URL is scanner/attack-shaped (secret
 * probes, config/backup hunting, plugin/wp-includes probing, user enumeration,
 * SQLi-in-URL, etc.).
 *
 * This is a deliberate companion to get-attack-summary. The search_attack
 * counter (get-attack-summary) measures injection submitted through the ON-SITE
 * SEARCH form. This counter measures the URL/PATH scanner storm that 404s
 * without ever touching the search box. Two distinct attack surfaces.
 *
 * Categorization happens at QUERY TIME via extrachill_analytics_categorize_404_url()
 * — no new columns or tables are added. The stored requested_url lives in the
 * event_data JSON under '$.requested_url'.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.5
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_scanner_404_summary_ability' );

/**
 * Register the get-scanner-404-summary ability.
 */
function extrachill_analytics_register_scanner_404_summary_ability() {
	wp_register_ability(
		'extrachill/get-scanner-404-summary',
		array(
			'label'       => __( 'Get Scanner 404 Summary', 'extrachill-analytics' ),
			'description' => __( 'Summarizes scanner/attack-shaped 404 events (secret/config probes, plugin/wp-includes probing, user enumeration, SQLi-in-URL) by category, day, IP, or URL. Companion to get-attack-summary, which covers on-site search injection.', 'extrachill-analytics' ),
			'category'    => 'extrachill-analytics',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'days'     => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'group_by' => array(
						'type'        => 'string',
						'description' => __( 'Grouping dimension: category, day, ip, or url.', 'extrachill-analytics' ),
						'enum'        => array( 'category', 'day', 'ip', 'url' ),
						'default'     => 'category',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => __( 'Maximum rows to return. 0 for unlimited.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'blog_id'  => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
				),
			),
			'output_schema' => array(
				'type'        => 'object',
				'description' => __( 'Summary with rows array, scanner total, benign total, and period metadata.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_scanner_404_summary',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
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
 * Execute callback for get-scanner-404-summary ability.
 *
 * Pulls every distinct 404 URL in the window, categorizes each at read time,
 * keeps only scanner/attack-shaped categories, then aggregates by the requested
 * dimension. The 'ip' dimension regroups by the hashed IP stored in event_data
 * (404 tracking stores 'ip_hash', not a raw IP).
 *
 * @param array $input Input parameters.
 * @return array Summary data:
 *   [
 *     'rows'          => [ ['key' => ..., 'count' => N], ... ],
 *     'scanner_total' => int,  // total scanner-shaped 404 hits in window
 *     'benign_total'  => int,  // total non-scanner 404 hits in window
 *     'total'         => int,  // all 404 hits in window
 *     'scanner_pct'   => float,
 *     'distinct'      => int,  // distinct keys for the chosen dimension
 *     'group_by'      => string,
 *     'days'          => int,
 *     'period'        => string,
 *     'truncated'     => bool,
 *   ]
 */
function extrachill_analytics_ability_get_scanner_404_summary( $input ) {
	global $wpdb;

	$days     = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 28;
	$group_by = isset( $input['group_by'] ) ? (string) $input['group_by'] : 'category';
	$limit    = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 25;
	$blog_id  = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	$valid_groups = array( 'category', 'day', 'ip', 'url' );
	if ( ! in_array( $group_by, $valid_groups, true ) ) {
		$group_by = 'category';
	}

	$table  = extrachill_analytics_events_table();
	$where  = array( "event_type = '404_error'" );
	$values = array();

	if ( $days > 0 ) {
		$where[]  = 'created_at >= %s';
		$values[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	$where_clause = implode( ' AND ', $where );

	// Pull every 404 row in the window with the fields needed to categorize and
	// group. Categorization can't be expressed in SQL (it mirrors the PHP
	// categorizer), so we aggregate in PHP. Rows are grouped by URL first to
	// keep the working set small even on a multi-hundred-thousand-row window.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "SELECT
		JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.requested_url')) AS url,
		JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.ip_hash')) AS ip_hash,
		DATE(created_at) AS day,
		COUNT(*) AS hits
		FROM {$table}
		WHERE {$where_clause}
		GROUP BY url, ip_hash, day";

	if ( ! empty( $values ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	} else {
		$rows = $wpdb->get_results( $sql );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$scanner_total = 0;
	$benign_total  = 0;
	$agg           = array();

	foreach ( $rows as $row ) {
		$url      = (string) $row->url;
		$hits     = (int) $row->hits;
		$category = extrachill_analytics_categorize_404_url( $url );

		if ( ! extrachill_analytics_is_scanner_404_category( $category ) ) {
			$benign_total += $hits;
			continue;
		}

		$scanner_total += $hits;

		switch ( $group_by ) {
			case 'day':
				$key = (string) $row->day;
				break;
			case 'ip':
				$key = ( null === $row->ip_hash || '' === $row->ip_hash ) ? '(unknown)' : (string) $row->ip_hash;
				break;
			case 'url':
				$key = $url;
				break;
			case 'category':
			default:
				$key = $category;
				break;
		}

		if ( ! isset( $agg[ $key ] ) ) {
			$agg[ $key ] = 0;
		}
		$agg[ $key ] += $hits;
	}

	if ( 'day' === $group_by ) {
		krsort( $agg );
	} else {
		arsort( $agg );
	}

	$distinct = count( $agg );

	$rows_out = array();
	foreach ( $agg as $key => $count ) {
		$rows_out[] = array(
			'key'   => $key,
			'count' => (int) $count,
		);
	}

	$truncated = false;
	if ( $limit > 0 && count( $rows_out ) > $limit ) {
		$rows_out  = array_slice( $rows_out, 0, $limit );
		$truncated = true;
	}

	$total = $scanner_total + $benign_total;

	return array(
		'rows'          => $rows_out,
		'scanner_total' => $scanner_total,
		'benign_total'  => $benign_total,
		'total'         => $total,
		'scanner_pct'   => $total > 0 ? round( ( $scanner_total / $total ) * 100, 1 ) : 0.0,
		'distinct'      => $distinct,
		'group_by'      => $group_by,
		'days'          => $days,
		'period'        => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'truncated'     => $truncated,
	);
}
