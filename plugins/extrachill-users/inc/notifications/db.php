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
	define( 'EXTRACHILL_USERS_NOTIFICATIONS_SCHEMA_VERSION', '3' );
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
 *   - emailed_at when this notification was first included in a digest email
 *                (NULL == never emailed). Drives "nudge once per notification":
 *                a notification is eligible for the digest exactly once, then
 *                its emailed_at is stamped so it never re-triggers an email.
 *   - producer / idempotency_key identify an optional producer-owned delivery
 *   - delivery_key is their SHA-256 digest, used for compact atomic uniqueness
 *
 * Indexes:
 *   - idx_user_unread  (user_id, is_read, created_at) — bell badge + unread list
 *   - idx_user_created (user_id, created_at)          — full paginated list
 *   - idx_email_sweep  (is_read, emailed_at, created_at) — digest eligibility scan
 *   - uq_delivery      (user_id, delivery_key) — per-recipient replay protection
 *
 * @return bool True when the receipt columns and unique index are installed.
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
		emailed_at datetime DEFAULT NULL,
		producer varchar(64) DEFAULT NULL,
		idempotency_key varchar(191) DEFAULT NULL,
		delivery_key char(64) DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY idx_user_unread (user_id, is_read, created_at),
		KEY idx_user_created (user_id, created_at),
		KEY idx_email_sweep (is_read, emailed_at, created_at),
		UNIQUE KEY uq_delivery (user_id, delivery_key)
	) {$charset_collate};";

	dbDelta( $sql );

	extrachill_users_backfill_notifications_emailed_at();

	return extrachill_users_notifications_receipt_schema_is_healthy();
}

/**
 * Verify the columns and unique index required for atomic delivery receipts.
 *
 * This is deliberately called after dbDelta rather than on every request. The
 * schema version is advanced only after the migration is actually usable, so a
 * failed migration is retried by the existing init guard.
 *
 * @return bool True when the receipt schema is ready.
 */
function extrachill_users_notifications_receipt_schema_is_healthy() {
	global $wpdb;

	$table   = extrachill_users_notifications_table_name();
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.

	foreach ( array( 'producer', 'idempotency_key', 'delivery_key' ) as $required_column ) {
		if ( ! in_array( $required_column, (array) $columns, true ) ) {
			return false;
		}
	}

	$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uq_delivery'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper and static index name.
	if ( 2 !== count( (array) $indexes ) ) {
		return false;
	}

	$indexed_columns = array_column( $indexes, 'Column_name' );

	return array( 'user_id', 'delivery_key' ) === array_values( $indexed_columns )
		&& 0 === (int) $indexes[0]['Non_unique'];
}

/**
 * Backfill emailed_at for notifications that predate the column (schema v1 → v2).
 *
 * Without this, adding the column leaves every pre-existing unread notification
 * with emailed_at = NULL, so the once-per-notification sweep would treat them as
 * never-emailed and send one more digest for notifications users were already
 * nudged about. We approximate "already emailed" from the legacy per-user
 * ec_notifications_last_emailed timestamp: any unread notification created at or
 * before a user's last-emailed time was included in that prior digest, so we
 * stamp it as emailed (using the user's last-emailed time as the stamp).
 *
 * Idempotent: only touches rows where emailed_at IS NULL, so re-running (or
 * running after new notifications arrive) never re-stamps already-handled rows.
 * Runs once per install via the version-gated maybe-install guard.
 *
 * @return void
 */
function extrachill_users_backfill_notifications_emailed_at() {
	global $wpdb;

	$table    = extrachill_users_notifications_table_name();
	$meta_key = 'ec_notifications_last_emailed';
	$usermeta = $wpdb->usermeta;

	// Pull every user with a recorded last-emailed timestamp. User_meta is
	// network-wide, so this is correct from the (single) owner-site install run.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT user_id, meta_value FROM {$usermeta} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
			$meta_key
		)
	);

	foreach ( (array) $rows as $row ) {
		$user_id = (int) $row->user_id;
		$last    = (int) $row->meta_value;
		if ( $user_id <= 0 || $last <= 0 ) {
			continue;
		}

		$stamp = gmdate( 'Y-m-d H:i:s', $last );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET emailed_at = %s WHERE user_id = %d AND emailed_at IS NULL AND created_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$stamp,
				$user_id,
				$stamp
			)
		);
	}
}
