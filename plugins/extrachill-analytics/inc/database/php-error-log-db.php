<?php
/**
 * PHP Error Log Daily Counts Database Table Management
 *
 * Creates and manages the network-wide table that persists per-day, per-signature
 * PHP error counts. This durable rollup is what lets per-day error rates survive
 * debug.log rotation — point-in-time grep resets every rotation, this table does not.
 *
 * @package ExtraChill\Analytics
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION', '1.0' );
define( 'EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION', 'extrachill_analytics_php_error_db_version' );

/**
 * Creates or updates the PHP error daily counts table when the version changes.
 *
 * Uses base_prefix for a network-wide table shared across all sites.
 */
function extrachill_analytics_php_error_create_table() {
	$current_db_version = get_site_option( EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION );

	if ( EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION === $current_db_version ) {
		return;
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = $wpdb->base_prefix . 'extrachill_analytics_php_errors';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		snapshot_day date NOT NULL,
		signature varchar(32) NOT NULL,
		severity varchar(20) NOT NULL DEFAULT 'notice',
		file_line varchar(255) NOT NULL DEFAULT '',
		sample_message text,
		count bigint(20) unsigned NOT NULL DEFAULT 0,
		first_seen datetime DEFAULT NULL,
		last_seen datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY day_signature (snapshot_day, signature),
		KEY signature_idx (signature),
		KEY severity_idx (severity),
		KEY snapshot_day_idx (snapshot_day)
	) {$charset_collate};";

	dbDelta( $sql );

	update_site_option( EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION_OPTION, EXTRACHILL_ANALYTICS_PHP_ERROR_DB_VERSION );
}

add_action( 'admin_init', 'extrachill_analytics_php_error_create_table' );

/**
 * Get the PHP error daily counts table name.
 *
 * @return string Table name with network base prefix.
 */
function extrachill_analytics_php_error_table() {
	global $wpdb;
	return $wpdb->base_prefix . 'extrachill_analytics_php_errors';
}
