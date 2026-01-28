<?php
/**
 * Artist Subscribers Database - Creation and management of artist_subscribers table.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates artist_subscribers table with indexes and constraints.
 */
function extrachill_artist_create_subscribers_table() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $table_name = $wpdb->prefix . 'artist_subscribers';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NULL,
        artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
        subscriber_email VARCHAR(255) NOT NULL,
        username VARCHAR(60) NULL DEFAULT NULL,
        source VARCHAR(50) NOT NULL DEFAULT 'platform_follow_consent',
        subscribed_at DATETIME NOT NULL,
        exported TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (subscriber_id),
        UNIQUE KEY email_artist (subscriber_email, artist_profile_id),
        KEY artist_profile_id (artist_profile_id),
        KEY exported (exported),
        KEY user_id (user_id),
        KEY user_artist_source (user_id, artist_profile_id, source)
    ) $charset_collate;";

    dbDelta( $sql );

}

