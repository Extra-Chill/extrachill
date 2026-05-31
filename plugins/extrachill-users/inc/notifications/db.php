<?php
/**
 * Network notification table + installers.
 *
 * The notification SUBSTRATE for the entire ExtraChill network. Stores one ROW
 * per notification (not a per-user array blob) in a network-wide table keyed by
 * {$wpdb->base_prefix} so ANY site (community, events, artist, shop) writes to
 * and reads from the same table. This supersedes the legacy single-blob
 * `extrachill_notifications` user_meta on the community site.
 *
 * Mirrors the concert-tracking table pattern in this same plugin
 * (inc/concert-tracking/db.php): base_prefix table + idempotent dbDelta.
 *
 * Parent epic: Extra-Chill/extrachill-community#82.
 *
 * @package ExtraChill\Users
 * @since 0.15.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notification table schema version.
 *
 * Bump this whenever the CREATE TABLE string changes so the maybe-install
 * guard re-runs dbDelta network-wide (dbDelta is the canonical add-column
 * upgrade path).
 */
if ( ! defined( 'EXTRACHILL_USERS_NOTIFICATIONS_SCHEMA_VERSION' ) ) {
	define( 'EXTRACHILL_USERS_NOTIFICATIONS_SCHEMA_VERSION', '1' );
}

/**
 * Get the network notification table name.
 *
 * Uses base_prefix so it is the SAME table from every site in the network.
 *
 * @return string Full table name with base prefix.
 */
function extrachill_users_notifications_table_name() {
	global $wpdb;

	return $wpdb->base_prefix . 'ec_notifications';
}

/**
 * Install/upgrade the network notification table.
 *
 * Uses dbDelta for idempotent creation. One row per notification.
 *
 * Columns:
 *   - id         PK auto-increment
 *   - user_id    recipient
 *   - actor_id   who triggered the notification
 *   - type       notification type identifier (e.g. reply, mention)
 *   - title      human-readable title/subject
 *   - link       URL to the notification target
 *   - item_id    optional related object ID (post/topic/reply/event)
 *   - is_read    0 unread, 1 read
 *   - created_at when the notification was generated (UTC)
 *
 * Indexes:
 *   - idx_user_unread  (user_id, is_read, created_at) — bell badge + unread list
 *   - idx_user_created (user_id, created_at)          — full paginated list
 */
function extrachill_users_install_notifications_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = extrachill_users_notifications_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
		type varchar(64) NOT NULL DEFAULT '',
		title text NOT NULL,
		link text NOT NULL,
		item_id bigint(20) unsigned DEFAULT NULL,
		is_read tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_user_unread (user_id, is_read, created_at),
		KEY idx_user_created (user_id, created_at)
	) {$charset_collate};";

	dbDelta( $sql );
}
