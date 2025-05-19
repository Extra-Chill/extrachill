<?php
/**
 * Extrch.co Link Page Analytics - Tracking Logic
 *
 * Handles AJAX requests for recording events and enqueues frontend tracking script.
 */

defined('ABSPATH') || exit;


/**
 * AJAX handler for recording link page view and click events.
 *
 * Hooked to wp_ajax_nopriv_extrch_record_link_event.
 */
function extrch_record_link_event_ajax() {

    // --- Restore original logic --- //
    // /* // REMOVE opening comment
    // Check nonce for basic verification (optional but recommended)
    // check_ajax_referer('extrch_link_page_tracking', 'security_nonce');

    // --- Input Sanitization ---
    $link_page_id = isset($_POST['link_page_id']) ? absint($_POST['link_page_id']) : 0;
    $event_type = isset($_POST['event_type']) ? sanitize_key($_POST['event_type']) : ''; // 'page_view' or 'link_click'
    $event_identifier = isset($_POST['event_identifier']) ? esc_url_raw($_POST['event_identifier']) : ''; // URL for clicks, 'page' for views


    // Basic validation
    if (!$link_page_id || !$event_type || !$event_identifier || !in_array($event_type, ['page_view', 'link_click'])) {
        wp_send_json_error(['message' => 'Invalid data.'], 400);
        return;
    }

    // Check if the link page exists and is the correct post type
    $actual_post_type = get_post_type($link_page_id);
    error_log('[EXTRCH Analytics Tracking] Actual post type for ID ' . $link_page_id . ': ' . $actual_post_type); // DEBUG
    if ($actual_post_type !== 'band_link_page') {
        wp_send_json_error(['message' => 'Invalid link page ID.'], 400);
        return;
    }

    // --- Database Interaction ---
    global $wpdb;
    $current_date = current_time('Y-m-d');
    $sql = '';

    if ($event_type === 'page_view') {
        $table_name = $wpdb->prefix . 'extrch_link_page_daily_views';
        $sql = $wpdb->prepare(
            "INSERT INTO {$table_name} (link_page_id, stat_date, view_count) " .
            "VALUES (%d, %s, 1) " .
            "ON DUPLICATE KEY UPDATE view_count = view_count + 1",
            $link_page_id,
            $current_date
        );
    } elseif ($event_type === 'link_click') {
        $table_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
        $sql = $wpdb->prepare(
            "INSERT INTO {$table_name} (link_page_id, stat_date, link_url, click_count) " .
            "VALUES (%d, %s, %s, 1) " .
            "ON DUPLICATE KEY UPDATE click_count = click_count + 1",
            $link_page_id,
            $current_date,
            $event_identifier // This is the link_url for clicks
        );
    }

    if (empty($sql)) {
        error_log('[EXTRCH Analytics Tracking] SQL query was empty, invalid event type?'); // DEBUG
        wp_send_json_error(['message' => 'Invalid event type.'], 400);
        return;
    }

    $result = $wpdb->query($sql);

    if ($result !== false) {
        wp_send_json_success(['message' => 'Event recorded.']);
    } else {
        error_log('[EXTRCH Analytics Tracking] DB Error: ' . $wpdb->last_error); // Log specific DB error
        wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error], 500);
    }
    // */ // REMOVE closing comment

    // --- Send Simple Success Response for Testing --- // // REMOVE or comment out this block
    // wp_send_json_success(['message' => 'AJAX handler reached successfully (simplified test).']);

}
add_action('wp_ajax_nopriv_extrch_record_link_event', 'extrch_record_link_event_ajax'); // Allow for non-logged-in users
add_action('wp_ajax_extrch_record_link_event', 'extrch_record_link_event_ajax'); // Add if logged-in users should also track

/**
 * Enqueues the frontend tracking script for the public link page.
 *
 * Uses the extrch_link_page_minimal_head action added previously.
 *
 * @param int $link_page_id
 * @param int $band_id
 */
function extrch_enqueue_public_tracking_script($link_page_id, $band_id) {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();
    $tracking_js_path = '/forum-features/band-platform/extrch.co-link-page/js/link-page-public-tracking.js';

    if (file_exists($theme_dir . $tracking_js_path)) {
        $script_handle = 'extrch-public-tracking';
        wp_enqueue_script(
            $script_handle,
            $theme_uri . $tracking_js_path,
            array(), // No dependencies
            filemtime($theme_dir . $tracking_js_path),
            true // Load in footer
        );

        // Localize data for the script
        wp_localize_script($script_handle, 'extrchTrackingData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'link_page_id' => $link_page_id,
            // 'nonce' => wp_create_nonce('extrch_link_page_tracking') // Create nonce if using check_ajax_referer
        ));
    } else {
        // Optionally log an error if the script file is missing
        error_log('Error: link-page-public-tracking.js not found.');
    }
}
// Hook into the custom action defined in extrch_link_page_custom_head
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_tracking_script', 10, 2);


// --- Functions for Fetching/Displaying Analytics in Admin ---

/**
 * AJAX handler for fetching aggregated analytics data for the admin dashboard.
 *
 * Hooked to wp_ajax_extrch_fetch_link_page_analytics (only for logged-in users).
 */
function extrch_fetch_link_page_analytics_ajax() {
    error_log('[EXTRCH_ANALYTICS_AJAX] Request received. POST data: ' . print_r($_POST, true));
    error_log('[EXTRCH_ANALYTICS_AJAX] Current user ID: ' . get_current_user_id());

    if ( !isset( $_POST['link_page_id'], $_POST['security_nonce'] ) ) {
        error_log('[EXTRCH_ANALYTICS_AJAX] Error: Missing link_page_id or security_nonce.');
        wp_send_json_error(['message' => 'Missing required parameters.'], 400);
        return;
    }

    $link_page_id = (int) $_POST['link_page_id'];
    $nonce = sanitize_text_field( $_POST['security_nonce'] );
    $date_range_days = isset($_POST['date_range']) ? (int) $_POST['date_range'] : 30;

    error_log('[EXTRCH_ANALYTICS_AJAX] Link Page ID: ' . $link_page_id . ', Nonce: ' . $nonce . ', Date Range: ' . $date_range_days);

    // Verify Nonce
    if ( ! wp_verify_nonce( $nonce, 'extrch_link_page_ajax_nonce' ) ) {
        error_log('[EXTRCH_ANALYTICS_AJAX] Nonce verification failed. Received Nonce: ' . $nonce . ' for action: extrch_link_page_ajax_nonce');
        wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
        return;
    }
    error_log('[EXTRCH_ANALYTICS_AJAX] Nonce verification PASSED.');
    
    // Permission Check:
    // Verify that the current user has the specific capability 'view_band_link_page_analytics' 
    // for the given $link_page_id. This capability is dynamically granted in 
    // 'forum-features/band-platform/band-permissions.php' based on band membership 
    // and association with the link page.
    // This is preferred over direct 'edit_post' checks on the link page or associated band profile
    // to ensure the permission logic is centralized and specific to this feature.
    $can_view_analytics = current_user_can( 'view_band_link_page_analytics', $link_page_id );
    error_log('[EXTRCH_ANALYTICS_AJAX] User can view_band_link_page_analytics for link_page_id (' . $link_page_id . '): ' . ($can_view_analytics ? 'YES' : 'NO'));

    if ( ! $can_view_analytics ) {
        error_log('[EXTRCH_ANALYTICS_AJAX] User permission check failed for view_band_link_page_analytics.');
        wp_send_json_error(['message' => 'You do not have permission to view these analytics.'], 403);
        return;
    }
    error_log('[EXTRCH_ANALYTICS_AJAX] User permission check PASSED for view_band_link_page_analytics.');

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

    // --- Format Top Links --- Need link text from saved link page data
    $link_sections_data = get_post_meta($link_page_id, '_link_page_links', true);
    $link_sections = [];
    if (is_string($link_sections_data) && !empty($link_sections_data)) {
        $link_sections = json_decode($link_sections_data, true);
    } elseif (is_array($link_sections_data)) {
        $link_sections = $link_sections_data;
    }
    // Ensure $link_sections is an array after processing, default to empty if json_decode failed or was null
    if (!is_array($link_sections)) {
        $link_sections = [];
    }

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
    $social_links_data = get_post_meta($link_page_id, '_link_page_social_links', true);
    $social_links = [];
    if (is_string($social_links_data) && !empty($social_links_data)) {
        $social_links = json_decode($social_links_data, true);
    } elseif (is_array($social_links_data)) {
        $social_links = $social_links_data;
    }
    // Ensure $social_links is an array after processing, default to empty if json_decode failed or was null
    if (!is_array($social_links)) {
        $social_links = [];
    }

    if (is_array($social_links)) {
        foreach ($social_links as $social) {
             if (!empty($social['url'])) {
                 // Check if 'network' key exists and is not empty
                 $network_text = !empty($social['network']) ? ucfirst($social['network']) : __('Social Link', 'generatepress_child');
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
}
add_action('wp_ajax_extrch_fetch_link_page_analytics', 'extrch_fetch_link_page_analytics_ajax');


// --- Analytics Data Pruning ---

/**
 * Prunes old analytics data (older than 90 days) from the database.
 */
function extrch_prune_old_analytics_data() {
    global $wpdb;
    error_log('[EXTRCH Analytics Pruning] Cron job extrch_prune_old_analytics_data started.'); // DEBUG

    $ninety_days_ago = date('Y-m-d', strtotime('-90 days', current_time('timestamp')));

    $table_views_name = $wpdb->prefix . 'extrch_link_page_daily_views';
    $table_clicks_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';

    // Prune daily views
    $sql_views = $wpdb->prepare(
        "DELETE FROM {$table_views_name} WHERE stat_date < %s",
        $ninety_days_ago
    );
    $result_views = $wpdb->query($sql_views);

    if ($result_views === false) {
        error_log('[EXTRCH Analytics Pruning] Error pruning daily views: ' . $wpdb->last_error);
    } else {
        error_log("[EXTRCH Analytics Pruning] Pruned {$result_views} rows from {$table_views_name}.");
    }

    // Prune daily link clicks
    $sql_clicks = $wpdb->prepare(
        "DELETE FROM {$table_clicks_name} WHERE stat_date < %s",
        $ninety_days_ago
    );
    $result_clicks = $wpdb->query($sql_clicks);

    if ($result_clicks === false) {
        error_log('[EXTRCH Analytics Pruning] Error pruning daily link clicks: ' . $wpdb->last_error);
    } else {
        error_log("[EXTRCH Analytics Pruning] Pruned {$result_clicks} rows from {$table_clicks_name}.");
    }
    error_log('[EXTRCH Analytics Pruning] Cron job extrch_prune_old_analytics_data finished.'); // DEBUG
}
add_action('extrch_daily_analytics_prune_event', 'extrch_prune_old_analytics_data');

/**
 * Schedules the daily analytics pruning cron job if not already scheduled.
 */
function extrch_schedule_analytics_pruning_cron() {
    if (!wp_next_scheduled('extrch_daily_analytics_prune_event')) {
        wp_schedule_event(time(), 'daily', 'extrch_daily_analytics_prune_event');
        error_log('[EXTRCH Analytics Pruning] Scheduled daily analytics pruning cron job.'); // DEBUG
    }
}
add_action('init', 'extrch_schedule_analytics_pruning_cron'); // Or admin_init, depending on when it should be checked. 'init' is generally fine.

/**
 * Unschedules the daily analytics pruning cron job.
 *
 * Typically called on theme/plugin deactivation.
 */
function extrch_unschedule_analytics_pruning_cron() {
    wp_clear_scheduled_hook('extrch_daily_analytics_prune_event');
    error_log('[EXTRCH Analytics Pruning] Unscheduled daily analytics pruning cron job.'); // DEBUG
}
// For a theme, deactivation is handled via 'switch_theme'.
// If this were a plugin, you'd use register_deactivation_hook(__FILE__, 'extrch_unschedule_analytics_pruning_cron');
// For a theme, you might hook into 'switch_theme' and check if the new theme is not your theme.
// Or, more simply, this can be manually run if the theme is ever changed/removed.
// For now, leaving it as a function that can be called. A common place to call this for a theme
// is by checking the old theme's stylesheet name on 'after_switch_theme'.

// Example for theme deactivation (if this code is part of a theme):
// function extrch_theme_deactivated() {
//     extrch_unschedule_analytics_pruning_cron();
// }
// add_action('switch_theme', 'extrch_theme_deactivated'); // This will run when any theme is activated.
// A more robust check would be needed inside extrch_theme_deactivated if it should only run when *this* theme is deactivated.

?> 