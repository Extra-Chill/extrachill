<?php
/**
 * Link Page Analytics — Provider, Write Path, and Prune
 *
 * ECA-owned analytics for artist link pages (extrachill-analytics#94). This is
 * a lift-and-shift of the logic that previously lived in extrachill-artist-
 * platform's `inc/link-pages/live/analytics.php`:
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
 * COEXISTENCE WITH ARTIST-PLATFORM (until extrachill-analytics#94's companion,
 * extrachill-artist-platform#89, lands):
 *
 *   AP currently still attaches its own copies of these write listeners and its
 *   own filter provider against the SAME tables and SAME hooks. If both ECA's
 *   and AP's listeners stayed attached, every view/click would be DOUBLE-
 *   counted and the filter would resolve twice. To guarantee a single writer
 *   and a single provider regardless of load order, ECA detaches AP's known
 *   callbacks (by their stable global function names) before attaching its own.
 *   `remove_action`/`remove_filter` against a callback that isn't attached is a
 *   harmless no-op, so this is safe whether AP is present, already removed by
 *   #89, or loads after ECA. ECA also unschedules AP's prune cron event and
 *   runs its own, so pruning happens exactly once.
 *
 *   When #89 lands and AP stops registering these, the detach calls simply
 *   become no-ops and ECA is the sole owner with no behavioural change.
 *
 * AP still owns (ECA only CALLS INTO these, guarded with function_exists /
 * apply_filters): the artist-analytics React block UI, the beacon JS, and the
 * ownership/resolution helpers ec_get_link_page_for_artist / ec_can_manage_artist
 * / ec_get_artist_id.
 *
 * @package ExtraChill\Analytics
 * @since 0.23.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detach artist-platform's legacy link-page analytics callbacks so ECA is the
 * single writer/provider during the coexistence window.
 *
 * Runs late on `init` (priority 99) — after AP's own `init`-time registration
 * (its create-table + prune scheduling hook on `init`, and its module-load-time
 * add_action/add_filter calls which all execute during plugin load, before
 * `init`). Detaching by the stable AP function names is idempotent: if AP is
 * gone (post-#89) or never loaded, every call is a no-op.
 */
function extrachill_analytics_link_page_detach_legacy_providers() {
	// Write listeners (AP: extrachill_handle_link_page_view_db_write @10,
	// extrachill_handle_link_click_db_write @10).
	remove_action( 'extrachill_link_page_view_recorded', 'extrachill_handle_link_page_view_db_write', 10 );
	remove_action( 'extrachill_link_click_recorded', 'extrachill_handle_link_click_db_write', 10 );

	// Read provider (AP: extrachill_provide_link_page_analytics @10).
	remove_filter( 'extrachill_get_link_page_analytics', 'extrachill_provide_link_page_analytics', 10 );

	// Prune (AP: extrachill_artist_prune_old_analytics_data on its own event).
	// Detach AP's listener and unschedule AP's event so only ECA's prune runs.
	remove_action( 'extrachill_artist_daily_analytics_prune_event', 'extrachill_artist_prune_old_analytics_data' );
	if ( wp_next_scheduled( 'extrachill_artist_daily_analytics_prune_event' ) ) {
		wp_clear_scheduled_hook( 'extrachill_artist_daily_analytics_prune_event' );
	}
}
add_action( 'init', 'extrachill_analytics_link_page_detach_legacy_providers', 99 );

/*
 * ECA write-path listeners. Attached at load time on the SAME action hooks the
 * write routes fire. AP's identically-named callbacks are detached on init@99
 * above, so exactly one increment happens per beacon.
 */
add_action( 'extrachill_link_page_view_recorded', 'extrachill_analytics_handle_link_page_view_db_write', 10, 1 );
add_action( 'extrachill_link_click_recorded', 'extrachill_analytics_handle_link_click_db_write', 10, 3 );

/*
 * ECA read provider. Registered at priority 20 so that — even in the unlikely
 * window before init@99 detaches AP's @10 provider — ECA's value is the one the
 * filter ultimately returns (later priority wins as the final filtered value).
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
			"SELECT stat_date, view_count FROM {$views_table} WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s ORDER BY stat_date ASC",
			$link_page_id,
			$start_date,
			$today
		)
	);

	$clicks = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT stat_date, SUM(click_count) AS click_count FROM {$clicks_table} WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s GROUP BY stat_date ORDER BY stat_date ASC",
			$link_page_id,
			$start_date,
			$today
		)
	);

	$top_links = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT link_url, link_text, SUM(click_count) AS total_clicks FROM {$clicks_table} WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s GROUP BY link_url, link_text ORDER BY total_clicks DESC LIMIT 20",
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
}

/**
 * Prunes link-page analytics data older than 90 days from both daily tables.
 */
function extrachill_analytics_prune_link_page_data() {
	global $wpdb;

	$ninety_days_ago = gmdate( 'Y-m-d', strtotime( '-90 days', current_time( 'timestamp' ) ) );

	$table_views  = extrachill_analytics_link_page_views_table();
	$result_views = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table_views} WHERE stat_date < %s",
			$ninety_days_ago
		)
	);

	if ( false === $result_views ) {
		error_log( '[ECA Link Page Analytics Pruning] Error pruning daily views: ' . $wpdb->last_error );
	}

	$table_clicks  = extrachill_analytics_link_page_clicks_table();
	$result_clicks = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table_clicks} WHERE stat_date < %s",
			$ninety_days_ago
		)
	);

	if ( false === $result_clicks ) {
		error_log( '[ECA Link Page Analytics Pruning] Error pruning daily link clicks: ' . $wpdb->last_error );
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
