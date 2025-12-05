<?php
/**
 * Link Page Analytics Data Provider
 * 
 * Provides analytics data via REST API filter hook.
 * Database queries for link page views and clicks remain in this plugin
 * since it owns the custom tables.
 */

add_filter( 'extrachill_get_link_page_analytics', 'ec_handle_link_page_analytics', 10, 3 );

/**
 * Filter handler for fetching aggregated analytics data.
 *
 * Hooked to extrachill_get_link_page_analytics filter from extrachill-api plugin.
 * Returns analytics data array or WP_Error on failure.
 *
 * @param mixed $result        Previous filter result (null if no handler has run).
 * @param int   $link_page_id  The link page post ID.
 * @param int   $date_range    Number of days to query.
 * @return array|WP_Error Analytics data on success, WP_Error on failure.
 */
function ec_handle_link_page_analytics( $result, $link_page_id, $date_range ) {
	// If a previous handler already processed this, pass through
	if ( is_wp_error( $result ) || is_array( $result ) ) {
		return $result;
	}

	$date_range_days = absint( $date_range ) ?: 30;

	// Date Calculation
	$end_date   = current_time( 'Y-m-d' );
	$start_date = gmdate( 'Y-m-d', strtotime( "-$date_range_days days", current_time( 'timestamp' ) ) );

	// Database Query
	global $wpdb;
	$table_views_name  = $wpdb->prefix . 'extrch_link_page_daily_views';
	$table_clicks_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';

	// Query for Views
	$sql_views = $wpdb->prepare(
		"SELECT stat_date, SUM(view_count) as daily_total_views " .
		"FROM {$table_views_name} " .
		"WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s " .
		"GROUP BY stat_date " .
		"ORDER BY stat_date ASC",
		$link_page_id,
		$start_date,
		$end_date
	);
	$view_results = $wpdb->get_results( $sql_views );

	// Query for Clicks (aggregate daily and get individual links)
	$sql_clicks = $wpdb->prepare(
		"SELECT stat_date, link_url, SUM(click_count) as total_clicks_for_link " .
		"FROM {$table_clicks_name} " .
		"WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s " .
		"GROUP BY stat_date, link_url " .
		"ORDER BY stat_date ASC",
		$link_page_id,
		$start_date,
		$end_date
	);
	$click_results = $wpdb->get_results( $sql_clicks );

	// Data Processing
	$summary = array(
		'total_views'  => 0,
		'total_clicks' => 0,
	);
	$chart_data = array(
		'labels'   => array(),
		'datasets' => array(
			array(
				'label'       => 'Page Views',
				'data'        => array(),
				'borderColor' => 'rgb(75, 192, 192)',
				'tension'     => 0.1,
			),
			array(
				'label'       => 'Link Clicks',
				'data'        => array(),
				'borderColor' => 'rgb(255, 99, 132)',
				'tension'     => 0.1,
			),
		),
	);
	$top_links_raw = array();
	$daily_views   = array();
	$daily_clicks  = array();

	// Process View Results
	if ( $view_results ) {
		foreach ( $view_results as $row ) {
			$date                        = $row->stat_date;
			$count                       = (int) $row->daily_total_views;
			$summary['total_views']     += $count;
			$daily_views[ $date ]        = $count;
		}
	}

	// Process Click Results
	if ( $click_results ) {
		foreach ( $click_results as $row ) {
			$date     = $row->stat_date;
			$count    = (int) $row->total_clicks_for_link;
			$link_url = $row->link_url;

			$summary['total_clicks'] += $count;

			// Aggregate total daily clicks for chart
			if ( ! isset( $daily_clicks[ $date ] ) ) {
				$daily_clicks[ $date ] = 0;
			}
			$daily_clicks[ $date ] += $count;

			// Aggregate clicks per link_url for top links list
			if ( ! isset( $top_links_raw[ $link_url ] ) ) {
				$top_links_raw[ $link_url ] = 0;
			}
			$top_links_raw[ $link_url ] += $count;
		}
	}

	// Format Chart Data - create full date range for labels
	$current_loop_date = strtotime( $start_date );
	$end_loop_date     = strtotime( $end_date );
	while ( $current_loop_date <= $end_loop_date ) {
		$formatted_date                    = gmdate( 'Y-m-d', $current_loop_date );
		$chart_data['labels'][]            = $formatted_date;
		$chart_data['datasets'][0]['data'][] = isset( $daily_views[ $formatted_date ] ) ? $daily_views[ $formatted_date ] : 0;
		$chart_data['datasets'][1]['data'][] = isset( $daily_clicks[ $formatted_date ] ) ? $daily_clicks[ $formatted_date ] : 0;
		$current_loop_date                 = strtotime( '+1 day', $current_loop_date );
	}

	// Format Top Links - use centralized data provider function
	$artist_id     = apply_filters( 'ec_get_artist_id', $link_page_id );
	$data          = ec_get_link_page_data( $artist_id, $link_page_id );
	$link_sections = $data['links'] ?? array();

	$url_to_text_map = array();
	if ( is_array( $link_sections ) ) {
		foreach ( $link_sections as $section ) {
			if ( ! empty( $section['links'] ) && is_array( $section['links'] ) ) {
				foreach ( $section['links'] as $link ) {
					if ( ! empty( $link['link_url'] ) && ! empty( $link['link_text'] ) ) {
						$url_to_text_map[ $link['link_url'] ] = $link['link_text'];
					}
				}
			}
		}
	}

	// Add social links
	$social_links = $data['socials'] ?? array();
	if ( is_array( $social_links ) ) {
		foreach ( $social_links as $social ) {
			if ( ! empty( $social['url'] ) ) {
				$network_text                      = ! empty( $social['network'] ) ? ucfirst( $social['network'] ) : __( 'Social Link', 'extrachill-artist-platform' );
				$url_to_text_map[ $social['url'] ] = $network_text;
			}
		}
	}

	$top_links_formatted = array();
	arsort( $top_links_raw );
	foreach ( $top_links_raw as $identifier => $clicks ) {
		$top_links_formatted[] = array(
			'identifier' => $identifier,
			'text'       => isset( $url_to_text_map[ $identifier ] ) ? $url_to_text_map[ $identifier ] : $identifier,
			'clicks'     => $clicks,
		);
	}

	return array(
		'summary'    => $summary,
		'chart_data' => $chart_data,
		'top_links'  => $top_links_formatted,
	);
}
