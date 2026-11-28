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
 * an already-correct table is a no-op. AP's own create-table routine was removed
 * in AP#89 (shipped v1.13.1, 2026-06-27), so ECA is the sole table owner.
 *
 * @package ExtraChill\Analytics
 * @since 0.23.0
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION', '1.3' );
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
 * Table prefix of the blog that stores Link Pages.
 *
 * Runtime reads and writes follow the Link Page storage blog, not whatever
 * blog served the request: the artist dashboard runs on the artist site
 * while pages (and their view/click beacons) are served by the storage
 * site. Falls back to the current blog when the Link Pages runtime is not
 * loaded. Schema code keeps the per-blog functions above and runs them while
 * switched to the storage blog.
 *
 * @return string
 */
function extrachill_analytics_link_page_storage_prefix() {
	global $wpdb;
	$storage_blog_id = extrachill_analytics_link_page_storage_blog_id();
	return $storage_blog_id > 0 ? $wpdb->get_blog_prefix( $storage_blog_id ) : $wpdb->prefix;
}

/**
 * Blog ID that owns the Link Page analytics tables.
 *
 * The single source of truth for both runtime reads/writes (via
 * `extrachill_analytics_link_page_storage_prefix()`) and schema
 * create/reconcile, so the tables only ever exist where they are used.
 *
 * @return int Storage blog ID, or 0 meaning "the current blog".
 */
function extrachill_analytics_link_page_storage_blog_id() {
	$storage_blog_id = function_exists( 'ec_get_link_page_storage_blog_id' ) ? (int) ec_get_link_page_storage_blog_id() : 0;
	/**
	 * Blog whose tables hold Link Page analytics. Default: the Link Page
	 * storage blog, or 0 (current blog) when the runtime is not loaded.
	 *
	 * @param int $storage_blog_id Blog ID.
	 */
	return max( 0, (int) apply_filters( 'extrachill_analytics_link_page_storage_blog_id', $storage_blog_id ) );
}

/**
 * Whether the current blog is the Link Page analytics storage blog.
 *
 * @return bool
 */
function extrachill_analytics_link_page_is_storage_blog() {
	$storage_blog_id = extrachill_analytics_link_page_storage_blog_id();
	return 0 === $storage_blog_id || ! is_multisite() || get_current_blog_id() === $storage_blog_id;
}

/** Daily-views table on the Link Page storage blog. */
function extrachill_analytics_link_page_storage_views_table() {
	return extrachill_analytics_link_page_storage_prefix() . 'extrch_link_page_daily_views';
}

/** Daily-link-clicks table on the Link Page storage blog. */
function extrachill_analytics_link_page_storage_clicks_table() {
	return extrachill_analytics_link_page_storage_prefix() . 'extrch_link_page_daily_link_clicks';
}

/**
 * Create or update the link-page analytics tables when the DB version changes.
 *
 * Schema is a verbatim copy of AP's prior definition so dbDelta treats an
 * already-existing AP table as up-to-date (no-op). ECA uses its own option key
 * (`extrachill_analytics_link_page_db_version`), independent of AP's now-removed
 * `extrch_analytics_db_version`, to gate dbDelta. ECA has been the sole table
 * owner since AP#89 shipped (v1.13.1).
 *
 * `dbDelta()` only creates a missing table or appends a missing column — it
 * cannot reorder an existing column or rebuild a changed index — so every
 * call also runs the versioned schema-reconciliation pass from
 * link-page-schema.php against the owned column and index contract. That pass short-circuits to a no-op once a
 * site's tables already match, so this whole function stays cheap once the
 * version option catches up (#287).
 *
 * Always targets the Link Page storage blog (#1775): runtime reads and writes
 * resolve there, so creating the tables on any other blog only leaves empty
 * orphan copies behind. Called from another blog, this switches to the
 * storage blog for the duration of the call.
 */
function extrachill_analytics_link_page_create_table() {
	$storage_blog_id = extrachill_analytics_link_page_storage_blog_id();
	$switched        = ! extrachill_analytics_link_page_is_storage_blog();

	if ( $switched ) {
		switch_to_blog( $storage_blog_id );
	}

	try {
		extrachill_analytics_link_page_create_table_on_current_blog();
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Create/reconcile the Link Page tables on the current blog. Callers must
 * already be on the storage blog; use `extrachill_analytics_link_page_create_table()`.
 *
 * @return void
 */
function extrachill_analytics_link_page_create_table_on_current_blog() {
	$current_db_version = get_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );

	if ( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION === $current_db_version ) {
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

	extrachill_analytics_link_page_schema_reconcile_table( $table_views, extrachill_analytics_link_page_schema_view_columns(), extrachill_analytics_link_page_schema_view_indexes() );
	extrachill_analytics_link_page_schema_reconcile_table( $table_clicks, extrachill_analytics_link_page_schema_click_columns(), extrachill_analytics_link_page_schema_click_indexes() );

	update_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION, EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION );
}

/**
 * `admin_init` entry point: reconcile only while serving the storage blog's
 * admin, so wp-admin traffic on other sites never creates orphan copies or
 * pays a blog switch.
 *
 * @return void
 */
function extrachill_analytics_link_page_create_table_on_admin_init() {
	if ( extrachill_analytics_link_page_is_storage_blog() ) {
		extrachill_analytics_link_page_create_table();
	}
}
add_action( 'admin_init', 'extrachill_analytics_link_page_create_table_on_admin_init' );

/**
 * Ensure this site's Link Page schema is current before the first write in
 * this request.
 *
 * Mirrors `extrachill_analytics_events_ensure_ready()`: these tables are
 * per-site (unlike the network-wide events table) and were previously only
 * reconciled via the `admin_init` hook above, which never fires for a site
 * whose only traffic is front-end click/view writes through the API route —
 * exactly the passive-artist-site profile that let blog 4 drift for years
 * without anyone visiting its wp-admin (#287).
 *
 * @return void
 */
function extrachill_analytics_link_page_schema_ensure_ready() {
	static $ready = false;

	if ( $ready ) {
		return;
	}

	// create_table() is version-gated on the storage blog's own option.
	extrachill_analytics_link_page_create_table();

	$ready = true;
}

/**
 * Reconcile the storage blog's Link Page tables immediately after this plugin
 * upgrades, instead of waiting for its next `admin_init` or write (#287).
 *
 * Only the Link Page storage blog is touched (#1775). This previously swept
 * every network site via get_sites(), which created empty table copies on
 * sites that never read or write them. Leftover copies are removed with the
 * one-time `extrachill/cleanup-link-page-tables` ability.
 *
 * @param WP_Upgrader $upgrader_object Unused; required by the hook signature.
 * @param array       $hook_extra      Upgrade action/type/plugin metadata.
 * @return void
 */
function extrachill_analytics_link_page_reconcile_network_on_upgrade( $upgrader_object, $hook_extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $upgrader_object is part of the upgrader_process_complete hook signature.
	if ( ! is_multisite() || 'plugin' !== ( $hook_extra['type'] ?? '' ) || 'update' !== ( $hook_extra['action'] ?? '' ) ) {
		return;
	}

	$plugins = $hook_extra['plugins'] ?? ( isset( $hook_extra['plugin'] ) ? array( $hook_extra['plugin'] ) : array() );
	$updated = false;
	foreach ( (array) $plugins as $plugin ) {
		if ( false !== strpos( (string) $plugin, 'extrachill-analytics' ) ) {
			$updated = true;
			break;
		}
	}
	if ( ! $updated ) {
		return;
	}

	extrachill_analytics_link_page_create_table();
}
add_action( 'upgrader_process_complete', 'extrachill_analytics_link_page_reconcile_network_on_upgrade', 10, 2 );

/**
 * One-time cleanup of Link Page analytics tables on non-storage blogs (#1775).
 *
 * Before #1775 the upgrade sweep created both tables on every network site,
 * while reads/writes only ever touched the storage blog. This walks every
 * non-storage blog and, per table:
 *   - absent      → skipped (not reported),
 *   - 0 rows      → `would_drop` on dry run, `dropped` otherwise,
 *   - rows > 0    → `refused`; never dropped, reported for operator review
 *                   (e.g. legacy pre-storage-move rows that may need a merge).
 * The storage blog's tables are reported as `storage` and never touched.
 * A blog whose copies are all gone after a live run also loses its stale
 * schema-version option.
 *
 * @param bool $dry_run When true (default), report only.
 * @return array|WP_Error Report, or WP_Error when the storage blog is unknown.
 */
function extrachill_analytics_link_page_cleanup_orphan_tables( $dry_run = true ) {
	global $wpdb;

	$dry_run = (bool) $dry_run;
	$report  = array(
		'storage_blog_id' => 0,
		'dry_run'         => $dry_run,
		'tables'          => array(),
		'would_drop'      => 0,
		'dropped'         => 0,
		'refused'         => 0,
	);

	if ( ! is_multisite() ) {
		return $report;
	}

	$storage_blog_id = extrachill_analytics_link_page_storage_blog_id();
	if ( $storage_blog_id <= 0 ) {
		return new WP_Error(
			'extrachill_analytics_link_page_storage_unresolved',
			__( 'Link Page storage blog could not be resolved; refusing to clean up Link Page analytics tables.', 'extrachill-analytics' )
		);
	}
	$report['storage_blog_id'] = $storage_blog_id;

	$suffixes = array( 'extrch_link_page_daily_views', 'extrch_link_page_daily_link_clicks' );

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $blog_id ) {
		$blog_id   = (int) $blog_id;
		$prefix    = $wpdb->get_blog_prefix( $blog_id );
		$remaining = 0;

		foreach ( $suffixes as $suffix ) {
			$table  = $prefix . $suffix;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table existence probe.
			if ( $table !== $exists ) {
				continue;
			}

			$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Code-defined plugin-owned table name.

			if ( $blog_id === $storage_blog_id ) {
				$action = 'storage';
				++$remaining;
			} elseif ( $rows > 0 ) {
				$action = 'refused';
				++$report['refused'];
				++$remaining;
			} elseif ( $dry_run ) {
				$action = 'would_drop';
				++$report['would_drop'];
				++$remaining;
			} else {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time operator cleanup of an empty plugin-owned orphan table.
				$action = 'dropped';
				++$report['dropped'];
			}

			$report['tables'][] = array(
				'blog_id' => $blog_id,
				'table'   => $table,
				'rows'    => $rows,
				'action'  => $action,
			);
		}

		if ( ! $dry_run && 0 === $remaining && $blog_id !== $storage_blog_id ) {
			delete_blog_option( $blog_id, EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );
		}
	}

	return $report;
}
