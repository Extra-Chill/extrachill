<?php
/**
 * Database table creation and management for the Band Subscriber feature.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the custom database table for band subscribers.
 * This function is intended to be run on theme activation.
 */
function extrch_create_subscribers_table() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $table_name = $wpdb->prefix . 'band_subscribers';
    $charset_collate = $wpdb->get_charset_collate();

    // SQL statement to create the table
    $sql = "CREATE TABLE $table_name (
        subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        band_profile_id BIGINT(20) UNSIGNED NOT NULL,
        subscriber_email VARCHAR(255) NOT NULL,
        username VARCHAR(60) NULL DEFAULT NULL,
        subscribed_at DATETIME NOT NULL,
        exported TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (subscriber_id),
        UNIQUE KEY email_band (subscriber_email, band_profile_id),
        KEY band_profile_id (band_profile_id),
        KEY exported (exported)
    ) $charset_collate;";

    // Use dbDelta to create or update the table
    dbDelta( $sql );

    // Optionally, check for errors or success, though dbDelta handles most cases
    // $wpdb->print_error(); // For debugging
}

// Note: The activation hook registration will be in band-platform-includes.php 