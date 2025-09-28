<?php
/**
 * Database Management for ExtraChill Shop
 *
 * Handles database table creation and management for plugin functionality.
 *
 * @package ExtraChillShop
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create ad-free license table on plugin activation
 */
function extrachill_shop_create_ad_free_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'extrachill_ad_free';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        username varchar(255) NOT NULL,
        date_purchased datetime DEFAULT CURRENT_TIMESTAMP,
        order_id int(11) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY username (username),
        KEY order_id (order_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Log table creation
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        error_log("✅ ExtraChill Shop: Ad-free table created successfully");
    } else {
        error_log("❌ ExtraChill Shop: Failed to create ad-free table");
    }
}

/**
 * Check if ad-free table exists
 *
 * @return bool True if table exists, false otherwise.
 */
function extrachill_shop_ad_free_table_exists() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extrachill_ad_free';
    return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
}