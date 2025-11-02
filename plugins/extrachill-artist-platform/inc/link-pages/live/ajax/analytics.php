<?php
/**
 * Link click analytics tracking for public link pages
 *
 * Page views are now handled by theme-level ec_post_views system.
 * This file only handles link-specific click analytics.
 */

add_action( 'wp_ajax_link_page_click_tracking', 'handle_link_click_tracking' );
add_action( 'wp_ajax_nopriv_link_page_click_tracking', 'handle_link_click_tracking' );

/**
 * Records link click events to daily aggregation table
 */
function handle_link_click_tracking() {
    if ( ! isset( $_POST['link_page_id'] ) || ! isset( $_POST['link_url'] ) ) {
        wp_die( 'Invalid request', 'Error', array( 'response' => 400 ) );
    }

    global $wpdb;

    $link_page_id = apply_filters('ec_get_link_page_id', $_POST);
    $link_url = esc_url_raw( $_POST['link_url'] );
    $today = current_time('Y-m-d');

    $table_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';

    // Increment daily click count for this link
    $wpdb->query($wpdb->prepare("
        INSERT INTO {$table_name}
            (link_page_id, stat_date, link_url, click_count)
        VALUES
            (%d, %s, %s, 1)
        ON DUPLICATE KEY UPDATE
            click_count = click_count + 1
    ", $link_page_id, $today, $link_url));

    wp_die( 'success' );
}


/**
 * Enqueues tracking script for link page analytics
 */
function extrch_enqueue_public_tracking_script($link_page_id, $artist_id) {
    $theme_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
    $theme_uri = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
    $tracking_js_path = 'inc/link-pages/live/assets/js/link-page-public-tracking.js';

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
 * Prunes analytics data older than 90 days
 *
 * Removes old records from both daily views and link clicks tables.
 * Page views tracked via ec_post_views are aggregated daily before pruning.
 */
function extrch_prune_old_analytics_data() {
    global $wpdb;

    $ninety_days_ago = date('Y-m-d', strtotime('-90 days', current_time('timestamp')));

    // Prune daily views table
    $table_views_name = $wpdb->prefix . 'extrch_link_page_daily_views';
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

    // Prune daily clicks table
    $table_clicks_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
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

/**
 * Aggregates daily view counts from ec_post_views into analytics table
 *
 * Calculates daily increments by comparing current totals with historical data.
 */
function extrch_aggregate_daily_link_page_views() {
    global $wpdb;

    $link_pages = get_posts(array(
        'post_type' => 'artist_link_page',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_status' => 'any'
    ));

    $today = current_time('Y-m-d');
    $table_name = $wpdb->prefix . 'extrch_link_page_daily_views';
    $aggregated = 0;

    foreach ($link_pages as $link_page_id) {
        // Get current total from universal counter
        $current_total = (int) get_post_meta($link_page_id, 'ec_post_views', true);

        // Get cumulative total from daily table (all records before today)
        $historical_total = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(view_count), 0)
            FROM {$table_name}
            WHERE link_page_id = %d
            AND stat_date < %s
        ", $link_page_id, $today));

        // Calculate daily increment
        $daily_increment = $current_total - (int)$historical_total;

        // Only insert if there's an increment
        if ($daily_increment > 0) {
            // Use REPLACE to handle re-runs on same day
            $wpdb->replace(
                $table_name,
                array(
                    'link_page_id' => $link_page_id,
                    'stat_date' => $today,
                    'view_count' => $daily_increment
                ),
                array('%d', '%s', '%d')
            );
            $aggregated++;
        }
    }

    error_log("[EXTRCH Analytics Aggregation] Aggregated daily views for {$aggregated} link pages.");
}
add_action('extrch_daily_analytics_aggregate_event', 'extrch_aggregate_daily_link_page_views');

/**
 * Schedules daily analytics aggregation cron event
 */
function extrch_schedule_analytics_aggregation_cron() {
    if (!wp_next_scheduled('extrch_daily_analytics_aggregate_event')) {
        wp_schedule_event(time(), 'daily', 'extrch_daily_analytics_aggregate_event');
    }
}
add_action('init', 'extrch_schedule_analytics_aggregation_cron');

/**
 * Unschedules analytics aggregation cron event
 */
function extrch_unschedule_analytics_aggregation_cron() {
    wp_clear_scheduled_hook('extrch_daily_analytics_aggregate_event');
}