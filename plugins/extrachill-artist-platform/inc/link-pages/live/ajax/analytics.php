<?php
/**
 * Analytics tracking for public link pages with data pruning and WordPress cron integration
 */

add_action( 'wp_ajax_extrch_record_link_event', 'extrch_record_link_event_ajax' );
add_action( 'wp_ajax_nopriv_extrch_record_link_event', 'extrch_record_link_event_ajax' );

add_action( 'wp_ajax_link_page_click_tracking', 'handle_link_click_tracking' );
add_action( 'wp_ajax_nopriv_link_page_click_tracking', 'handle_link_click_tracking' );


/**
 * Records analytics events with daily aggregation and automatic data validation
 */
function extrch_record_link_event_ajax() {

    $link_page_id = apply_filters('ec_get_link_page_id', $_POST);
    $event_type = isset($_POST['event_type']) ? sanitize_key($_POST['event_type']) : ''; // 'page_view' or 'link_click'
    $event_identifier = isset($_POST['event_identifier']) ? esc_url_raw($_POST['event_identifier']) : ''; // URL for clicks, 'page' for views


    if (!$link_page_id || !$event_type || !$event_identifier || !in_array($event_type, ['page_view', 'link_click'])) {
        wp_send_json_error(['message' => 'Invalid data.'], 400);
        return;
    }

    $actual_post_type = get_post_type($link_page_id);
    if ($actual_post_type !== 'artist_link_page') {
        wp_send_json_error(['message' => 'Invalid link page ID.'], 400);
        return;
    }

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
            $event_identifier
        );
    }

    if (empty($sql)) {
        wp_send_json_error(['message' => 'Invalid event type.'], 400);
        return;
    }

    $result = $wpdb->query($sql);

    if ($result !== false) {
        wp_send_json_success(['message' => 'Event recorded.']);
    } else {
        error_log('[EXTRCH Analytics Tracking] DB Error: ' . $wpdb->last_error);
        wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error], 500);
    }

}

/**
 * Legacy click tracking with individual event records and user metadata
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


/**
 * Enqueues tracking script with file existence verification and nonce security
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
            array(),
            filemtime($theme_dir . $tracking_js_path),
            true
        );

        // Localize data for the script
        wp_localize_script($script_handle, 'extrchTrackingData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'link_page_id' => $link_page_id,
            'nonce' => wp_create_nonce('extrch_link_page_tracking_nonce')
        ));
    } else {
        error_log('Error: link-page-public-tracking.js not found.');
    }
}
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_tracking_script', 10, 2);

/**
 * Automated 90-day data retention with error logging
 */
function extrch_prune_old_analytics_data() {
    global $wpdb;

    $ninety_days_ago = date('Y-m-d', strtotime('-90 days', current_time('timestamp')));

    $table_views_name = $wpdb->prefix . 'extrch_link_page_daily_views';
    $table_clicks_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';

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
}
add_action('extrch_daily_analytics_prune_event', 'extrch_prune_old_analytics_data');

function extrch_schedule_analytics_pruning_cron() {
    if (!wp_next_scheduled('extrch_daily_analytics_prune_event')) {
        wp_schedule_event(time(), 'daily', 'extrch_daily_analytics_prune_event');
    }
}
add_action('init', 'extrch_schedule_analytics_pruning_cron');

function extrch_unschedule_analytics_pruning_cron() {
    wp_clear_scheduled_hook('extrch_daily_analytics_prune_event');
}