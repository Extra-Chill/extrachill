<?php
/**
 * Management Analytics AJAX Handlers
 * 
 * Single responsibility: Handle AJAX requests related to analytics management (admin-only functions)
 */

// Register management analytics AJAX actions using WordPress native patterns
add_action( 'wp_ajax_extrch_fetch_link_page_analytics', 'extrch_fetch_link_page_analytics_ajax' );

/**
 * AJAX handler for fetching aggregated analytics data for the admin dashboard.
 *
 * Hooked to wp_ajax_extrch_fetch_link_page_analytics (only for logged-in users).
 */
function extrch_fetch_link_page_analytics_ajax() {
    try {
        // Verify standardized nonce (matches pattern used by all other AJAX handlers)
        check_ajax_referer('ec_ajax_nonce', 'nonce');
        
        // Check permissions using centralized permission system
        if (!ec_ajax_can_manage_link_page($_POST)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        // Get and sanitize parameters
        $link_page_id = isset($_POST['link_page_id']) ? (int) $_POST['link_page_id'] : 0;
        $date_range_days = isset($_POST['date_range']) ? (int) $_POST['date_range'] : 30;

        if (!$link_page_id) {
            wp_send_json_error(['message' => 'Missing link page ID.'], 400);
            return;
        }

    // --- Date Calculation ---
    $end_date = current_time('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-$date_range_days days", current_time('timestamp')));

    // --- Database Query ---
    global $wpdb;
    $table_views_name = $wpdb->prefix . 'extrch_link_page_daily_views';
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
    $view_results = $wpdb->get_results($sql_views);

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
    $click_results = $wpdb->get_results($sql_clicks);

    // --- Data Processing ---
    $summary = ['total_views' => 0, 'total_clicks' => 0];
    $chart_data = ['labels' => [], 'datasets' => [
        ['label' => 'Page Views', 'data' => [], 'borderColor' => 'rgb(75, 192, 192)', 'tension' => 0.1],
        ['label' => 'Link Clicks', 'data' => [], 'borderColor' => 'rgb(255, 99, 132)', 'tension' => 0.1]
    ]];
    $top_links_raw = []; // Store clicks per identifier (link_url)
    $dates_processed = []; // Track dates for chart labels
    $daily_views = []; // Store views per date
    $daily_clicks = []; // Store clicks per date

    // Process View Results
    if ($view_results) {
        foreach ($view_results as $row) {
            $date = $row->stat_date;
            $count = (int)$row->daily_total_views;
            $summary['total_views'] += $count;
            $daily_views[$date] = $count;
            $dates_processed[$date] = true; // Mark date as seen
        }
    }

    // Process Click Results
    if ($click_results) {
        foreach ($click_results as $row) {
            $date = $row->stat_date;
            $count = (int)$row->total_clicks_for_link;
            $link_url = $row->link_url;

            $summary['total_clicks'] += $count;

            // Aggregate total daily clicks for chart
            if (!isset($daily_clicks[$date])) {
                $daily_clicks[$date] = 0;
            }
            $daily_clicks[$date] += $count;
            $dates_processed[$date] = true; // Mark date as seen

            // Aggregate clicks per link_url for top links list
            if (!isset($top_links_raw[$link_url])) {
                $top_links_raw[$link_url] = 0;
            }
            $top_links_raw[$link_url] += $count;
        }
    }

    // --- Format Chart Data ---
    // Create a full date range for the chart labels
    $current_loop_date = strtotime($start_date);
    $end_loop_date = strtotime($end_date);
    while ($current_loop_date <= $end_loop_date) {
        $formatted_date = date('Y-m-d', $current_loop_date);
        $chart_data['labels'][] = $formatted_date; // Use YYYY-MM-DD or format as needed
        $chart_data['datasets'][0]['data'][] = isset($daily_views[$formatted_date]) ? $daily_views[$formatted_date] : 0;
        $chart_data['datasets'][1]['data'][] = isset($daily_clicks[$formatted_date]) ? $daily_clicks[$formatted_date] : 0;
        $current_loop_date = strtotime('+1 day', $current_loop_date);
    }

    // --- Format Top Links --- Use centralized data provider function
    $artist_id = apply_filters('ec_get_artist_id', $link_page_id);
    $data = ec_get_link_page_data($artist_id, $link_page_id);
    $link_sections = $data['links'] ?? [];

    $url_to_text_map = [];
    if (is_array($link_sections)) {
        foreach ($link_sections as $section) {
            if (!empty($section['links']) && is_array($section['links'])) {
                foreach ($section['links'] as $link) {
                    if (!empty($link['link_url']) && !empty($link['link_text'])) {
                        $url_to_text_map[$link['link_url']] = $link['link_text'];
                    }
                }
            }
        }
    }
    // Add social links too
    // Use centralized data provider for social links (already retrieved above)
    $social_links = $data['socials'] ?? [];

    if (is_array($social_links)) {
        foreach ($social_links as $social) {
             if (!empty($social['url'])) {
                 // Check if 'network' key exists and is not empty
                 $network_text = !empty($social['network']) ? ucfirst($social['network']) : __('Social Link', 'extrachill-artist-platform');
                 $url_to_text_map[$social['url']] = $network_text; 
             }
        }
    }

    $top_links_formatted = [];
    arsort($top_links_raw); // Sort by clicks descending
    foreach ($top_links_raw as $identifier => $clicks) {
        $top_links_formatted[] = [
            'identifier' => $identifier,
            'text' => isset($url_to_text_map[$identifier]) ? $url_to_text_map[$identifier] : $identifier, // Fallback to URL if text not found
            'clicks' => $clicks
        ];
    }
    // Limit top links if desired (e.g., top 10)
    // $top_links_formatted = array_slice($top_links_formatted, 0, 10);

    // --- Prepare Response ---
    $response_data = [
        'summary' => $summary,
        'chart_data' => $chart_data,
        'top_links' => $top_links_formatted
    ];

    wp_send_json_success($response_data);
    
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'An error occurred while fetching analytics data.'));
    }
}