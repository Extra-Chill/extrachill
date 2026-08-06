<?php
/**
 * Analytics Events Database Table Management
 *
 * Creates and manages the network-wide events table for analytics tracking.
 *
 * @package ExtraChill\Analytics
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION', '1.2' );
define( 'EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION', 'extrachill_analytics_events_db_version' );

/**
 * Creates or updates the analytics events table when database version changes.
 *
 * Uses base_prefix for network-wide table shared across all sites.
 */
function extrachill_analytics_events_create_table() {
	$current_db_version = get_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION );

	if ( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION === $current_db_version ) {
		return true;
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = $wpdb->base_prefix . 'extrachill_analytics_events';

	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event_type varchar(50) NOT NULL,
		event_data longtext,
		source_url varchar(2083) DEFAULT '',
		blog_id int(11) NOT NULL DEFAULT 1,
		user_id bigint(20) unsigned DEFAULT NULL,
		visitor_id char(36) DEFAULT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY event_type_idx (event_type),
		KEY blog_id_idx (blog_id),
		KEY user_id_idx (user_id),
		KEY created_at_idx (created_at),
		KEY event_type_created (event_type, created_at),
		KEY visitor_created (visitor_id, created_at)
	) {$charset_collate};";

	$wpdb->last_error = '';
	dbDelta( $sql );

	if ( '' !== $wpdb->last_error ) {
		return false;
	}

	update_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION, EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION );
	return true;
}

/**
 * Check the cheap network option that marks the events schema ready.
 *
 * @return bool Whether the current events schema has been installed.
 */
function extrachill_analytics_events_schema_is_ready() {
	return EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION === get_site_option( EXTRACHILL_ANALYTICS_EVENTS_DB_VERSION_OPTION );
}

/**
 * Ensure the events table is ready before the first write in this request.
 *
 * @return bool Whether writes may proceed.
 */
function extrachill_analytics_events_ensure_ready() {
	static $ready = false;

	if ( $ready || extrachill_analytics_events_schema_is_ready() ) {
		$ready = true;
		return true;
	}

	if ( function_exists( 'extrachill_analytics_install_network_schema' ) ) {
		extrachill_analytics_install_network_schema();
	} else {
		extrachill_analytics_events_create_table();
	}

	$ready = extrachill_analytics_events_schema_is_ready();
	return $ready;
}

/**
 * Get the analytics events table name.
 *
 * @return string Table name with prefix.
 */
function extrachill_analytics_events_table() {
	global $wpdb;
	return $wpdb->base_prefix . 'extrachill_analytics_events';
}
