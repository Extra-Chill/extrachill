<?php
/**
 * Network-wide activity stream database table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function extrachill_api_activity_get_table_name() {
    global $wpdb;
    return $wpdb->base_prefix . 'extrachill_activity';
}

function extrachill_api_activity_install_table() {
    global $wpdb;

    $table_name = extrachill_api_activity_get_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL,
        type varchar(191) NOT NULL,
        blog_id int(11) unsigned NOT NULL,
        actor_id bigint(20) unsigned NULL,
        primary_object_type varchar(64) NOT NULL,
        primary_object_blog_id int(11) unsigned NOT NULL,
        primary_object_id varchar(64) NOT NULL,
        secondary_object_type varchar(64) NULL,
        secondary_object_blog_id int(11) unsigned NULL,
        secondary_object_id varchar(64) NULL,
        summary text NOT NULL,
        visibility varchar(32) NOT NULL DEFAULT 'private',
        data longtext NULL,
        PRIMARY KEY  (id),
        KEY created_at_idx (created_at),
        KEY type_id_idx (type, id),
        KEY blog_id_idx (blog_id, id),
        KEY actor_id_idx (actor_id, id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
