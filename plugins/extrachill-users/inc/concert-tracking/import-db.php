<?php
/**
 * Concert import runs table + installer.
 *
 * Tracks a single end-to-end import attempt per user per source. The row is
 * the durable state for the resume-across-days semantics: the Action Scheduler
 * worker reads it to know which page to fetch next, updates counters as it
 * processes results, and the React UI polls it for progress.
 *
 * @package ExtraChill\Users
 * @since 0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema version. Bump whenever the table's column set changes so the
 * `admin_init` fallback in activation.php re-runs dbDelta to apply the
 * new columns on existing installs.
 *
 *  1 — initial schema (0.13.0)
 *  2 — adds `total_events_created` column (0.14.0, fixes #54)
 */
if ( ! defined( 'EXTRACHILL_USERS_CONCERT_IMPORT_RUNS_SCHEMA_VERSION' ) ) {
	define( 'EXTRACHILL_USERS_CONCERT_IMPORT_RUNS_SCHEMA_VERSION', '2' );
}

/**
 * Get the concert import runs table name.
 *
 * Network-scoped (lives under $wpdb->base_prefix) so a single user can have
 * one import history across the whole platform — concert tracking itself is
 * also network-scoped.
 *
 * @return string
 */
function extrachill_users_concert_import_runs_table_name() {
	global $wpdb;
	return $wpdb->base_prefix . 'ec_concert_import_runs';
}

/**
 * Install/upgrade the concert import runs table.
 *
 * Schema notes:
 * - `next_page` is the 1-indexed page number to fetch next; null when complete.
 * - `next_attempt_at` is a UTC datetime; when not null, the AS worker is
 *   scheduled (or should be re-scheduled) for that timestamp.
 * - `rate_limit_*` counters cover the rolling daily-quota accounting per
 *   source-defined window; we record requests issued today so resume logic
 *   can re-check whether we still have quota left.
 */
function extrachill_users_install_concert_import_runs_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = extrachill_users_concert_import_runs_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		source_slug varchar(64) NOT NULL,
		status varchar(32) NOT NULL DEFAULT 'pending',
		external_username varchar(190) DEFAULT NULL,
		next_page int unsigned DEFAULT 1,
		next_attempt_at datetime DEFAULT NULL,
		requests_today int unsigned NOT NULL DEFAULT 0,
		requests_today_date date DEFAULT NULL,
		total_events_seen int unsigned NOT NULL DEFAULT 0,
		total_events_matched int unsigned NOT NULL DEFAULT 0,
		total_events_created int unsigned NOT NULL DEFAULT 0,
		total_events_unmatched int unsigned NOT NULL DEFAULT 0,
		total_events_skipped int unsigned NOT NULL DEFAULT 0,
		total_pages int unsigned DEFAULT NULL,
		error_message text DEFAULT NULL,
		started_at datetime DEFAULT NULL,
		updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		completed_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY idx_user_started (user_id, started_at),
		KEY idx_status (status),
		KEY idx_user_source_status (user_id, source_slug, status)
	) {$charset_collate};";

	dbDelta( $sql );
}
