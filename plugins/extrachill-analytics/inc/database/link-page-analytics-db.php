<?php
/**
 * Link Page Analytics Database Management
 *
 * Owns the two daily-aggregate tables that back artist link-page analytics:
 *   - {prefix}extrch_link_page_daily_views        (one row per link page per day)
 *   - {prefix}extrch_link_page_daily_link_clicks  (one row per link page / day / link)
 *
 * These tables historically lived in extrachill-artist-platform (AP). Ownership
 * is being flipped to extrachill-analytics (ECA) per extrachill-analytics#94 so
 * the network analytics plugin owns the link-page analytics store, write path,
 * prune, and read provider. AP retains only the artist-analytics block UI, the
 * beacon JS, and its artist/ownership resolution helpers — it now CONSUMES the
 * ECA-provided read primitive.
 *
 * IMPORTANT — table names and prefix are intentionally IDENTICAL to AP's prior
 * schema (per-site `$wpdb->prefix`, same table names, same columns/keys) so the
 * existing data is shared and continuous: no migration, no backfill. dbDelta on
 * an already-correct table is a no-op, so ECA adopting the same schema is safe
 * even while AP's create-table routine is still registered (#89 removes it).
 *
 * @package ExtraChill\Analytics
 * @since 0.23.0
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION', '1.2' );
define( 'EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION', 'extrachill_analytics_link_page_db_version' );

/**
 * Get the daily-views table name.
 *
 * Per-site table (`$wpdb->prefix`) — link pages live on the artist site, so the
 * data is scoped to that site exactly as it was under AP ownership.
 *
 * @return string Table name with the site prefix.
 */
function extrachill_analytics_link_page_views_table() {
	global $wpdb;
	return $wpdb->prefix . 'extrch_link_page_daily_views';
}

/**
 * Get the daily-link-clicks table name.
 *
 * @return string Table name with the site prefix.
 */
function extrachill_analytics_link_page_clicks_table() {
	global $wpdb;
	return $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
}

/**
 * Create or update the link-page analytics tables when the DB version changes.
 *
 * Schema is a verbatim copy of AP's prior definition so dbDelta treats an
 * already-existing AP table as up-to-date (no-op). We use a DISTINCT option key
 * from AP (`extrachill_analytics_link_page_db_version`) so ECA's gate is
 * independent of AP's `extrch_analytics_db_version` — both can run dbDelta
 * harmlessly against the same idempotent schema during the coexistence window.
 */
function extrachill_analytics_link_page_create_table() {
	$current_db_version = get_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );

	if ( $current_db_version === EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_views = extrachill_analytics_link_page_views_table();
	$sql_views   = "CREATE TABLE {$table_views} (
		view_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		link_page_id bigint(20) unsigned NOT NULL,
		stat_date date NOT NULL,
		view_count bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (view_id),
		UNIQUE KEY unique_daily_view (link_page_id, stat_date)
	) {$charset_collate};";

	$table_clicks = extrachill_analytics_link_page_clicks_table();
	$sql_clicks   = "CREATE TABLE {$table_clicks} (
		click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		link_page_id bigint(20) unsigned NOT NULL,
		stat_date date NOT NULL,
		link_url varchar(2083) NOT NULL,
		link_text varchar(255) NOT NULL DEFAULT '',
		click_count bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (click_id),
		UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191), link_text(100)),
		KEY link_page_date (link_page_id, stat_date)
	) {$charset_collate};";

	dbDelta( $sql_views );
	dbDelta( $sql_clicks );

	update_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION, EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION );
}

add_action( 'admin_init', 'extrachill_analytics_link_page_create_table' );
