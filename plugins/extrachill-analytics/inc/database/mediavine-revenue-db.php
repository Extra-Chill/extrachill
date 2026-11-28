<?php
/**
 * Legacy Mediavine revenue table (retired storage).
 *
 * Extra Chill no longer owns revenue storage: every revenue row is written to
 * and read from the Data Machine Business revenue ledger (see
 * revenue-ledger.php). This file keeps only what the one-time migration needs
 * to read the retired `{base_prefix}extrachill_analytics_mediavine_revenue`
 * table, the explicit, parity-guarded drop of that table, the advisory lock
 * that serializes per-period ingestion, and the period-token resolver.
 *
 * The table is never created, written, or dropped automatically.
 *
 * @package ExtraChill\Analytics
 * @since 0.16.0
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION', '1.2' );
define( 'EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION', 'extrachill_analytics_revenue_db_version' );

/**
 * Get the legacy Mediavine revenue table name.
 *
 * @return string Table name with base prefix.
 */
function extrachill_analytics_revenue_table() {
	global $wpdb;
	return $wpdb->base_prefix . 'extrachill_analytics_mediavine_revenue';
}

/**
 * Whether the legacy revenue table still exists.
 *
 * @return bool
 */
function extrachill_analytics_revenue_legacy_table_exists() {
	global $wpdb;
	$table = extrachill_analytics_revenue_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe.
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

/**
 * Read every row of the legacy revenue table (migration source).
 *
 * @param int $blog_id Restrict to one blog; 0 = every blog.
 * @return array<int, array> Legacy rows (empty when the table is gone).
 */
function extrachill_analytics_revenue_legacy_rows( $blog_id = 0 ) {
	if ( ! extrachill_analytics_revenue_legacy_table_exists() ) {
		return array();
	}

	global $wpdb;
	$table = extrachill_analytics_revenue_table();
	if ( (int) $blog_id > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of a plugin-owned table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE blog_id = %d ORDER BY id ASC', $table, (int) $blog_id ), ARRAY_A );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of a plugin-owned table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table ), ARRAY_A );
	}

	return is_array( $rows ) ? $rows : array();
}

/**
 * Drop the legacy revenue table and forget its schema version.
 *
 * Only ever called by the explicit, parity-guarded drop
 * (extrachill_analytics_revenue_drop_legacy()).
 *
 * @return bool True when the table no longer exists.
 */
function extrachill_analytics_revenue_drop_legacy_table() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- explicit operator-confirmed drop of a retired plugin-owned table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', extrachill_analytics_revenue_table() ) );
	delete_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION );
	return ! extrachill_analytics_revenue_legacy_table_exists();
}

/**
 * Acquire a named advisory lock to serialize ingestion for one period.
 *
 * @param string $name    Lock name.
 * @param int    $timeout Seconds to wait (0 = non-blocking).
 * @return bool True if the lock was acquired.
 */
function extrachill_analytics_revenue_lock_acquire( $name, $timeout = 10 ) {
	global $wpdb;
	$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	return '1' === (string) $result;
}

/**
 * Release a previously acquired advisory lock.
 *
 * @param string $name Lock name.
 * @return bool True if released.
 */
function extrachill_analytics_revenue_lock_release( $name ) {
	global $wpdb;
	$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	return '1' === (string) $result;
}

/**
 * Resolve an operator-supplied period token into a canonical label + date range.
 *
 * The flat Mediavine pages export has NO date column, so the operator passes the
 * period at import time. Accepts:
 *   - "YYYY-MM"            -> label "YYYY-MM", range = that whole month.
 *   - "YYYY"              -> label "YYYY",    range = that whole year.
 *   - "" (empty)           -> label "all-time", null range (the time-blind flat
 *                             lifetime file — a cumulative total, not a period).
 * Explicit --start/--end always override the derived range; if a label was also
 * given it is kept, else a "start..end" label is synthesized.
 *
 * @param string $period Period token (YYYY-MM, YYYY, or '').
 * @param string $start  Explicit start (Y-m-d) override, or ''.
 * @param string $end    Explicit end (Y-m-d) override, or ''.
 * @return array{label:string,start:string,end:string} Resolved label + range ('' range = none).
 */
function extrachill_analytics_revenue_resolve_period( $period = '', $start = '', $end = '' ) {
	$period = trim( (string) $period );
	$start  = trim( (string) $start );
	$end    = trim( (string) $end );

	$label         = 'all-time';
	$derived_start = '';
	$derived_end   = '';

	if ( preg_match( '/^(\d{4})-(\d{2})$/', $period, $m ) ) {
		$label         = $period;
		$derived_start = sprintf( '%04d-%02d-01', (int) $m[1], (int) $m[2] );
		$month_start   = strtotime( $derived_start );
		$derived_end   = false === $month_start ? '' : gmdate( 'Y-m-t', $month_start );
	} elseif ( preg_match( '/^(\d{4})$/', $period, $m ) ) {
		$label         = $period;
		$derived_start = $m[1] . '-01-01';
		$derived_end   = $m[1] . '-12-31';
	} elseif ( '' !== $period ) {
		// A free-form label (e.g. "2022-peak"); keep it, range comes from start/end.
		$label = sanitize_text_field( $period );
	}

	// Explicit start/end override the derived range.
	$resolved_start = '' !== $start ? $start : $derived_start;
	$resolved_end   = '' !== $end ? $end : $derived_end;

	// If only explicit dates were given (no period token), synthesize a label.
	if ( 'all-time' === $label && ( '' !== $resolved_start || '' !== $resolved_end ) ) {
		$label = trim( $resolved_start . '..' . $resolved_end, '.' );
	}

	return array(
		'label' => $label,
		'start' => $resolved_start,
		'end'   => $resolved_end,
	);
}
