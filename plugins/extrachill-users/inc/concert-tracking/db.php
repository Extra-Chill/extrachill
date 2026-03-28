<?php
/**
 * Concert tracking table + installers.
 *
 * Stores user-event relationships network-wide using {$wpdb->base_prefix}.
 * A record existing means the user has marked the event — the label
 * (Going / Check In / I Was There) is derived at render time from event date.
 *
 * @package ExtraChill\Users
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get concert tracking table name.
 *
 * @return string Full table name with base prefix.
 */
function extrachill_users_concert_tracking_table_name() {
	global $wpdb;

	return $wpdb->base_prefix . 'ec_concert_tracking';
}

/**
 * Install/upgrade concert tracking table.
 *
 * Uses dbDelta for idempotent creation. The table stores one record per
 * user+event+blog combination — no status column needed since the
 * meaning is derived from event timing at render time.
 */
function extrachill_users_install_concert_tracking_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = extrachill_users_concert_tracking_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		event_id bigint(20) unsigned NOT NULL,
		blog_id bigint(20) unsigned NOT NULL DEFAULT 7,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY user_event_blog (user_id, event_id, blog_id),
		KEY idx_user (user_id, created_at),
		KEY idx_event (event_id),
		KEY idx_blog_event (blog_id, event_id)
	) {$charset_collate};";

	dbDelta( $sql );
}
