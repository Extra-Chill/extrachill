<?php
/**
 * Link Page Analytics — Provider, Write Path, and Prune
 *
 * ECA is the sole owner of artist link-page analytics (extrachill-analytics#94):
 * the store (see inc/database/link-page-analytics-db.php), the write path, the
 * read provider, and the daily prune.
 *
 *   - WRITE PATH: listeners on `extrachill_link_page_view_recorded` (fired by
 *     ECA's own track-page-view ability) and `extrachill_link_click_recorded`
 *     (fired by the extrachill-api click route) that INSERT ... ON DUPLICATE
 *     KEY UPDATE into the daily-aggregate tables.
 *   - READ PROVIDER: `extrachill_analytics_provide_link_page_analytics()`
 *     registered on the `extrachill_get_link_page_analytics` filter, returning the unchanged
 *     frontend contract (summary / chart_data{labels,datasets} / top_links).
 *   - PRUNE: a daily cron that drops rows older than 90 days.
 *
 * Ownership history: this logic was lifted from extrachill-artist-platform (AP)
 * and the ownership flip completed in AP#89 / ECA#94 (both shipped 2026-06-27;
 * first AP release without the duplicate callbacks was v1.13.1). A temporary
 * coexistence shim detached AP's duplicate callbacks during the cutover; it was
 * removed in extrachill-analytics#132 once AP stopped registering them. ECA
 * declares no dependency on AP and the Extra Chill network upgrades atomically,
 * so no minimum-version gap requires retaining any of that shim.
 *
 * AP now only CONSUMES the read primitive (the artist-analytics React block UI,
 * the beacon JS, and the ownership/resolution helpers ec_get_link_page_for_artist
 * / ec_can_manage_artist / ec_get_artist_id).
 *
 * @package ExtraChill\Analytics
 * @since 0.23.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * ECA write-path listeners. Attached at load time on the action hooks the write
 * routes fire; ECA is the sole writer, so exactly one increment happens per beacon.
 */
add_action( 'extrachill_link_page_view_recorded', 'extrachill_analytics_handle_link_page_view_db_write', 10, 1 );
add_action( 'extrachill_link_click_recorded', 'extrachill_analytics_handle_link_click_db_write', 10, 3 );

/*
 * ECA read provider for the extrachill_get_link_page_analytics filter.
 */
add_filter( 'extrachill_get_link_page_analytics', 'extrachill_analytics_provide_link_page_analytics', 20, 3 );

/**
 * Supplies link-page analytics data for the extrachill_get_link_page_analytics
 * filter (consumed by the extrachill-api REST route, ECA's
 * get-link-page-analytics ability, and AP's artist-get-analytics ability).
 *
 * Frontend contract is UNCHANGED from AP's prior provider:
 *   summary{total_views,total_clicks}
 *   chart_data{labels[],datasets[{label,data[]}]}
 *   top_links[{text,identifier,clicks}]
 *
 * @param mixed $data         Prior filter value (unused).
 * @param int   $link_page_id Link page post ID.
 * @param int   $date_range   Number of days to include (1-90).
 * @return array|WP_Error
 */
function extrachill_analytics_provide_link_page_analytics( $data, $link_page_id, $date_range ) {
	global $wpdb;

	$link_page_id = absint( $link_page_id );
	if ( ! $link_page_id ) {
		return new WP_Error( 'invalid_link_page', 'Invalid or missing link page ID.', array( 'status' => 400 ) );
	}

	$range = absint( $date_range );
	$range = $range ? $range : 30;
	$range = max( 1, min( 90, $range ) );

	$today       = current_time( 'Y-m-d' );
	$start_stamp = strtotime( $today . ' -' . ( $range - 1 ) . ' days' );
	$start_date  = gmdate( 'Y-m-d', $start_stamp );

	$views_table  = extrachill_analytics_link_page_views_table();
	$clicks_table = extrachill_analytics_link_page_clicks_table();

	$views = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT stat_date, view_count FROM {$views_table} WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s ORDER BY stat_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $views_table is a code-defined table name; all values bound via prepare().
			$link_page_id,
			$start_date,
			$today
		)
	);

	$clicks = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT stat_date, SUM(click_count) AS click_count FROM {$clicks_table} WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s GROUP BY stat_date ORDER BY stat_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clicks_table is a code-defined table name; all values bound via prepare().
			$link_page_id,
			$start_date,
			$today
		)
	);

	$top_links = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT link_url, link_text, SUM(click_count) AS total_clicks FROM {$clicks_table} WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s GROUP BY link_url, link_text ORDER BY total_clicks DESC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clicks_table is a code-defined table name; all values bound via prepare().
			$link_page_id,
			$start_date,
			$today
		)
	);

	$view_map  = array();
	$click_map = array();

	foreach ( $views as $row ) {
		$view_map[ $row->stat_date ] = (int) $row->view_count;
	}

	foreach ( $clicks as $row ) {
		$click_map[ $row->stat_date ] = (int) $row->click_count;
	}

	$labels       = array();
	$view_series  = array();
	$click_series = array();

	for ( $i = 0; $i < $range; $i++ ) {
		$date           = gmdate( 'Y-m-d', strtotime( $start_date . ' +' . $i . ' days' ) );
		$labels[]       = $date;
		$view_series[]  = isset( $view_map[ $date ] ) ? $view_map[ $date ] : 0;
		$click_series[] = isset( $click_map[ $date ] ) ? $click_map[ $date ] : 0;
	}

	$total_views  = array_sum( $view_series );
	$total_clicks = array_sum( $click_series );

	$formatted_top_links = array_map(
		static function ( $row ) {
			return array(
				'text'       => $row->link_text,
				'identifier' => $row->link_url,
				'clicks'     => (int) $row->total_clicks,
			);
		},
		$top_links
	);

	return array(
		'summary'    => array(
			'total_views'  => $total_views,
			'total_clicks' => $total_clicks,
		),
		'chart_data' => array(
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label' => 'Page Views',
					'data'  => $view_series,
				),
				array(
					'label' => 'Link Clicks',
					'data'  => $click_series,
				),
			),
		),
		'top_links'  => $formatted_top_links,
	);
}

/**
 * Writes page-view data to the daily views table.
 *
 * @param int $link_page_id The link page post ID.
 */
function extrachill_analytics_handle_link_page_view_db_write( $link_page_id ) {
	global $wpdb;

	$today      = current_time( 'Y-m-d' );
	$table_name = extrachill_analytics_link_page_views_table();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a code-defined table name; all values bound via prepare().
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table_name}
				(link_page_id, stat_date, view_count)
			VALUES
				(%d, %s, 1)
			ON DUPLICATE KEY UPDATE
				view_count = view_count + 1",
			$link_page_id,
			$today
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Writes link-click data to the daily aggregation table.
 *
 * @param int    $link_page_id The link page post ID.
 * @param string $link_url     The clicked URL (already normalized by the API).
 * @param string $link_text    The link text at time of click.
 */
function extrachill_analytics_handle_link_click_db_write( $link_page_id, $link_url, $link_text = '' ) {
	global $wpdb;

	$today      = current_time( 'Y-m-d' );
	$table_name = extrachill_analytics_link_page_clicks_table();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a code-defined table name; all values bound via prepare().
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table_name}
				(link_page_id, stat_date, link_url, link_text, click_count)
			VALUES
				(%d, %s, %s, %s, 1)
			ON DUPLICATE KEY UPDATE
				click_count = click_count + 1",
			$link_page_id,
			$today,
			$link_url,
			$link_text
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Prunes link-page analytics data older than 90 days from both daily tables.
 */
function extrachill_analytics_prune_link_page_data() {
	global $wpdb;

	$ninety_days_ago = gmdate( 'Y-m-d', strtotime( '-90 days', time() ) );

	$table_views  = extrachill_analytics_link_page_views_table();
	$result_views = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table_views} WHERE stat_date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_views is a code-defined table name; value bound via prepare().
			$ninety_days_ago
		)
	);

	if ( false === $result_views ) {
		error_log( '[ECA Link Page Analytics Pruning] Error pruning daily views: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional prune-failure log surfacing $wpdb->last_error.
	}

	$table_clicks  = extrachill_analytics_link_page_clicks_table();
	$result_clicks = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table_clicks} WHERE stat_date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_clicks is a code-defined table name; value bound via prepare().
			$ninety_days_ago
		)
	);

	if ( false === $result_clicks ) {
		error_log( '[ECA Link Page Analytics Pruning] Error pruning daily link clicks: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional prune-failure log surfacing $wpdb->last_error.
	}
}
add_action( 'extrachill_analytics_link_page_prune_event', 'extrachill_analytics_prune_link_page_data' );

/**
 * Schedules the ECA-owned daily prune cron if not already scheduled.
 */
function extrachill_analytics_schedule_link_page_prune_cron() {
	if ( ! wp_next_scheduled( 'extrachill_analytics_link_page_prune_event' ) ) {
		wp_schedule_event( time(), 'daily', 'extrachill_analytics_link_page_prune_event' );
	}
}
add_action( 'init', 'extrachill_analytics_schedule_link_page_prune_cron' );
