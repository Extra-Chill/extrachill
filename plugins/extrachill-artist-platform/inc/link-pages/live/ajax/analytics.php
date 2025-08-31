<?php
/**
 * Analytics AJAX Handlers
 * 
 * Single responsibility: Handle all AJAX requests and functions related to analytics functionality
 */

// Register analytics AJAX actions using WordPress native patterns
add_action( 'wp_ajax_extrch_record_link_event', 'extrch_record_link_event_ajax' );
add_action( 'wp_ajax_nopriv_extrch_record_link_event', 'extrch_record_link_event_ajax' );

add_action( 'wp_ajax_link_page_click_tracking', 'handle_link_click_tracking' );
add_action( 'wp_ajax_nopriv_link_page_click_tracking', 'handle_link_click_tracking' );


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
    $link_page_id = apply_filters('ec_get_link_page_id', $_POST);
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
    if ($actual_post_type !== 'artist_link_page') {
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

/**
 * Legacy AJAX handler for link click tracking
 * 
 * Maintains backwards compatibility for existing tracking implementations.
 * Records individual click events with detailed metadata.
 */
function handle_link_click_tracking() {
    if ( ! isset( $_POST['link_page_id'] ) || ! isset( $_POST['link_url'] ) ) {
        wp_die( 'Invalid request', 'Error', array( 'response' => 400 ) );
    }

    global $wpdb;

    $link_page_id = apply_filters('ec_get_link_page_id', $_POST);
    $link_url = esc_url_raw( $_POST['link_url'] );
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    $table_name = $wpdb->prefix . 'link_page_analytics';

    $wpdb->insert(
        $table_name,
        array(
            'link_page_id' => $link_page_id,
            'link_url' => $link_url,
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'referer' => $referer,
            'clicked_at' => current_time( 'mysql' )
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s' )
    );

    wp_die( 'success' );
}

// Management analytics function moved to inc/link-pages/management/ajax/analytics.php

/**
 * Enqueues the frontend tracking script for the public link page.
 *
 * Uses the extrch_link_page_minimal_head action added previously.
 *
 * @param int $link_page_id
 * @param int $artist_id
 */
function extrch_enqueue_public_tracking_script($link_page_id, $artist_id) {
    $theme_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
    $theme_uri = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
    $tracking_js_path = '/inc/link-pages/live/assets/js/link-page-public-tracking.js';

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
            'nonce' => wp_create_nonce('extrch_link_page_tracking_nonce')
        ));
    } else {
        // Optionally log an error if the script file is missing
        error_log('Error: link-page-public-tracking.js not found.');
    }
}
// Hook into the custom action defined in extrch_link_page_custom_head
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_tracking_script', 10, 2);

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