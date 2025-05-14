<?php
/**
 * Extrch.co Link Page Analytics - Database Management
 *
 * Handles creation and updates for the custom analytics database table.
 */

defined('ABSPATH') || exit;

// Define the required database version
define('EXTRCH_ANALYTICS_DB_VERSION', '1.1'); // UPDATED version
define('EXTRCH_ANALYTICS_DB_VERSION_OPTION', 'extrch_analytics_db_version');

/**
 * Creates or updates the custom analytics database table using dbDelta.
 *
 * Runs on admin_init via a version check to minimize overhead.
 */
function extrch_create_or_update_analytics_table() {
    // Check if the current DB version matches the required version
    $current_db_version = get_option(EXTRCH_ANALYTICS_DB_VERSION_OPTION);

    if ($current_db_version === EXTRCH_ANALYTICS_DB_VERSION) {
        // Database is up to date, do nothing.
        return;
    }

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Include the upgrade file for dbDelta
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // --- SQL for Daily Views Table ---
    $table_views = $wpdb->prefix . 'extrch_link_page_daily_views';
    $sql_views = "CREATE TABLE {$table_views} (
        view_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        link_page_id bigint(20) unsigned NOT NULL,
        stat_date date NOT NULL,
        view_count bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (view_id),
        UNIQUE KEY unique_daily_view (link_page_id, stat_date)
    ) {$charset_collate};";

    // --- SQL for Daily Link Clicks Table ---
    $table_clicks = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
    $sql_clicks = "CREATE TABLE {$table_clicks} (
        click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        link_page_id bigint(20) unsigned NOT NULL,
        stat_date date NOT NULL,
        link_url varchar(2083) NOT NULL,
        click_count bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (click_id),
        UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191)),
        KEY link_page_date (link_page_id, stat_date)
    ) {$charset_collate};";

    // Run dbDelta for both tables
    dbDelta($sql_views);
    dbDelta($sql_clicks);

    // Update the database version option
    update_option(EXTRCH_ANALYTICS_DB_VERSION_OPTION, EXTRCH_ANALYTICS_DB_VERSION);

}

// Hook the function to run on admin initialization
add_action('admin_init', 'extrch_create_or_update_analytics_table');

?> 