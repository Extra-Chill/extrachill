<?php
/**
 * Refresh token table + installers.
 *
 * Token auth is stored network-wide using {$wpdb->base_prefix}.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get refresh token table name.
 */
function extrachill_users_refresh_token_table_name() {
	global $wpdb;

	return $wpdb->base_prefix . 'extrachill_refresh_tokens';
}

/**
 * Install/upgrade refresh token table.
 */
function extrachill_users_install_refresh_token_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = extrachill_users_refresh_token_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		device_id char(36) NOT NULL,
		device_name varchar(191) NULL,
		refresh_token_hash char(64) NOT NULL,
		created_at datetime NOT NULL,
		last_used_at datetime NULL,
		expires_at datetime NOT NULL,
		revoked_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_device (user_id, device_id),
		KEY user_id (user_id),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	dbDelta( $sql );
}
