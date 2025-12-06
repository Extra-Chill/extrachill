<?php
/**
 * Link Page Analytics - Views and Click Tracking
 *
 * Handles analytics tracking for public link pages:
 * - Page views: Tracked on load via REST API, stored in both ec_post_views (all-time)
 *   and extrch_link_page_daily_views table (90-day rolling).
 * - Link clicks: Tracked on click via REST API, stored in extrch_link_page_daily_link_clicks table.
 *
 * Both use the same pattern: JS beacon → REST endpoint → action hook → direct table write.
 */

add_action( 'extrachill_link_page_view_recorded', 'extrachill_handle_link_page_view_db_write', 10, 1 );
add_action( 'extrachill_link_click_recorded', 'extrachill_handle_link_click_db_write', 10, 2 );

/**
 * Writes page view data to the daily views table
 *
 * @param int $link_page_id The link page post ID.
 */
function extrachill_handle_link_page_view_db_write( $link_page_id ) {
    global $wpdb;

    $today      = current_time( 'Y-m-d' );
    $table_name = $wpdb->prefix . 'extrch_link_page_daily_views';

    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table_name}
            (link_page_id, stat_date, view_count)
        VALUES
            (%d, %s, 1)
        ON DUPLICATE KEY UPDATE
            view_count = view_count + 1",
        $link_page_id,
        $today
    ) );
}

/**
 * Writes link click data to the daily aggregation table
 *
 * @param int    $link_page_id   The link page post ID.
 * @param string $link_url       The clicked URL (already normalized by API).
 */
function extrachill_handle_link_click_db_write( $link_page_id, $link_url ) {
    global $wpdb;

    $today      = current_time( 'Y-m-d' );
    $table_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';

    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table_name}
            (link_page_id, stat_date, link_url, click_count)
        VALUES
            (%d, %s, %s, 1)
        ON DUPLICATE KEY UPDATE
            click_count = click_count + 1",
        $link_page_id,
        $today,
        $link_url
    ) );
}


/**
 * Enqueues tracking script for link page analytics (views and clicks)
 */
function extrch_enqueue_public_tracking_script( $link_page_id, $artist_id ) {
    $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
    $plugin_uri = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
    $tracking_js_path = 'inc/link-pages/live/assets/js/link-page-public-tracking.js';

    if ( ! file_exists( $plugin_dir . $tracking_js_path ) ) {
        return;
    }

    $script_handle = 'extrch-public-tracking';
    wp_enqueue_script(
        $script_handle,
        $plugin_uri . $tracking_js_path,
        array(),
        filemtime( $plugin_dir . $tracking_js_path ),
        true
    );

    wp_localize_script( $script_handle, 'extrchTrackingData', array(
        'clickRestUrl' => rest_url( 'extrachill/v1/analytics/link-click' ),
        'viewRestUrl'  => rest_url( 'extrachill/v1/analytics/view' ),
        'link_page_id' => $link_page_id,
    ) );
}
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_tracking_script', 10, 2);

/**
 * Prunes analytics data older than 90 days
 *
 * Removes old records from both daily views and link clicks tables.
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