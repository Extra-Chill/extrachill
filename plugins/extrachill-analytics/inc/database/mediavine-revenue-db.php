<?php
/**
 * Mediavine Revenue Store
 *
 * Per-URL ad-revenue snapshots imported from the Mediavine Dashboard "Pages"
 * CSV export. Mediavine exposes NO per-page revenue API — the Control Panel
 * plugin only fetches ad-script settings from scripts.mediavine.com/tags/ — so
 * a manual CSV import is the only path to per-URL revenue. This table makes the
 * one-off "join a Mediavine pages CSV to content categories" stitch repeatable.
 *
 * Each row is one (slug, import batch) snapshot: the metrics Mediavine reports
 * for that page over the date range the operator selected in the dashboard. The
 * `period_start`/`period_end` columns record that range so a "recent vs
 * lifetime" lens is possible — an all-time export and a last-30-days export can
 * coexist and be queried separately by window.
 *
 * Network-wide table (base_prefix) to mirror the events table, but every row
 * carries the blog_id it was imported for so multisite revenue never collides.
 *
 * @package ExtraChill\Analytics
 * @since 0.16.0
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION', '1.2' );
define( 'EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION', 'extrachill_analytics_revenue_db_version' );

/**
 * Create or update the Mediavine revenue table when the DB version changes.
 *
 * Uses base_prefix for a network-wide table shared across all sites.
 */
function extrachill_analytics_revenue_create_table() {
	$current_db_version = get_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION );

	if ( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION === $current_db_version ) {
		return;
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = extrachill_analytics_revenue_table();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// One row per (blog_id, slug, period_label, import_batch). period_label is the
	// canonical time bucket ("2026-05" for a monthly export, "all-time" for the
	// flat lifetime file) and is what the revenue ARC groups by — the flat
	// Mediavine export has NO date column, so the operator supplies the period at
	// import time (--period=YYYY-MM) and it is recorded here. The unique key makes
	// a re-import of the same period idempotent (REPLACE INTO updates in place).
	//
	// post_id, period_start and period_end are genuinely NULLABLE: an unresolved
	// (legacy .html) row stores post_id = NULL, and the flat lifetime file (no
	// dates) stores period_start/end = NULL. The upsert writer emits a literal SQL
	// NULL for these rather than letting $wpdb->prepare() coerce a PHP null into 0
	// / "" / "0000-00-00", so the schema's DEFAULT NULL is honored and the table
	// behaves identically on strict-mode MySQL.
	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		blog_id int(11) NOT NULL DEFAULT 1,
		slug varchar(400) NOT NULL DEFAULT '',
		url varchar(2083) NOT NULL DEFAULT '',
		post_id bigint(20) unsigned DEFAULT NULL,
		content_blog_id int(11) unsigned DEFAULT NULL,
		canonical_url varchar(2083) NOT NULL DEFAULT '',
		views bigint(20) unsigned NOT NULL DEFAULT 0,
		revenue decimal(12,4) NOT NULL DEFAULT 0,
		rpm decimal(10,4) NOT NULL DEFAULT 0,
		cpm decimal(10,4) NOT NULL DEFAULT 0,
		viewability decimal(7,4) NOT NULL DEFAULT 0,
		fill_rate decimal(7,4) NOT NULL DEFAULT 0,
		impressions_per_pageview decimal(10,4) NOT NULL DEFAULT 0,
		period_label varchar(32) NOT NULL DEFAULT 'all-time',
		period_start date DEFAULT NULL,
		period_end date DEFAULT NULL,
		import_batch varchar(64) NOT NULL DEFAULT '',
		imported_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY snapshot (blog_id, slug(191), period_label, import_batch),
		KEY slug_idx (slug(191)),
		KEY post_id_idx (post_id),
		KEY content_identity_idx (content_blog_id, post_id),
		KEY blog_id_idx (blog_id),
		KEY period_label_idx (period_label),
		KEY period_idx (period_start, period_end),
		KEY import_batch_idx (import_batch)
	) {$charset_collate};";

	dbDelta( $sql );

	update_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION, EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION );
}

add_action( 'admin_init', 'extrachill_analytics_revenue_create_table' );

/**
 * Get the Mediavine revenue table name.
 *
 * @return string Table name with base prefix.
 */
function extrachill_analytics_revenue_table() {
	global $wpdb;
	return $wpdb->base_prefix . 'extrachill_analytics_mediavine_revenue';
}

/**
 * Insert (or replace) a single revenue snapshot row.
 *
 * REPLACE-on-conflict against the unique (blog_id, slug, period, batch) key, so
 * re-importing the same export is idempotent rather than additive.
 *
 * @param array $row {
 *     Snapshot data.
 *
 *     @type int    $blog_id                  Blog the page belongs to.
 *     @type string $slug                     Normalized slug (path), e.g. "some-post".
 *     @type string $url                      Original URL/path from the CSV.
 *     @type int    $post_id                  Resolved post ID, or 0/null.
 *     @type int    $views                    Pageviews.
 *     @type float  $revenue                  Ad revenue in dollars.
 *     @type float  $rpm                      Revenue per mille (per 1k views).
 *     @type float  $cpm                      Cost per mille.
 *     @type float  $viewability              Viewability ratio/percent.
 *     @type float  $fill_rate                Fill rate ratio/percent.
 *     @type float  $impressions_per_pageview Impressions per pageview.
 *     @type string $period_label             Time bucket ("2026-05" or "all-time"). Default "all-time".
 *     @type string $period_start             Window start (Y-m-d) or ''.
 *     @type string $period_end               Window end (Y-m-d) or ''.
 *     @type string $import_batch             Import batch identifier.
 * }
 * @return int|false Inserted row ID, or false on failure.
 */
function extrachill_analytics_revenue_upsert( array $row ) {
	global $wpdb;

	$table = extrachill_analytics_revenue_table();

	// Nullable columns (post_id, period_start, period_end) MUST store a real SQL
	// NULL when absent, never a coerced 0 / "" / "0000-00-00". $wpdb->prepare()
	// turns a PHP null into 0 for %d and "" for %s (which a DATE column then
	// stores as the 0000-00-00 zero-date on non-strict MySQL, and ERRORS on a
	// strict-mode install). That coercion is what corrupts the unresolved
	// (legacy .html) cohort: COUNT(DISTINCT IFNULL(post_id, slug)) needs post_id
	// to be NULL to fall back to the slug, so a stored 0 collapses every
	// unresolved page in a bucket into one. So we emit those columns as a literal
	// NULL in the SQL and bind only the non-null values through prepare().
	$post_id      = ! empty( $row['post_id'] ) ? (int) $row['post_id'] : null;
	$period_start = ! empty( $row['period_start'] ) ? (string) $row['period_start'] : null;
	$period_end   = ! empty( $row['period_end'] ) ? (string) $row['period_end'] : null;

	// Ordered (column, placeholder-or-NULL, bind-value?) tuples. A null third
	// element means the column binds nothing — its placeholder is the literal
	// NULL — so prepare() never sees the value and can't coerce it.
	$fields = array(
		array( 'blog_id', '%d', isset( $row['blog_id'] ) ? (int) $row['blog_id'] : get_current_blog_id() ),
		array( 'slug', '%s', isset( $row['slug'] ) ? (string) $row['slug'] : '' ),
		array( 'url', '%s', isset( $row['url'] ) ? (string) $row['url'] : '' ),
		array( 'post_id', null === $post_id ? 'NULL' : '%d', $post_id ),
		array( 'content_blog_id', ! empty( $row['content_blog_id'] ) ? '%d' : 'NULL', ! empty( $row['content_blog_id'] ) ? (int) $row['content_blog_id'] : null ),
		array( 'canonical_url', '%s', isset( $row['canonical_url'] ) ? (string) $row['canonical_url'] : '' ),
		array( 'views', '%d', isset( $row['views'] ) ? max( 0, (int) $row['views'] ) : 0 ),
		array( 'revenue', '%f', isset( $row['revenue'] ) ? (float) $row['revenue'] : 0.0 ),
		array( 'rpm', '%f', isset( $row['rpm'] ) ? (float) $row['rpm'] : 0.0 ),
		array( 'cpm', '%f', isset( $row['cpm'] ) ? (float) $row['cpm'] : 0.0 ),
		array( 'viewability', '%f', isset( $row['viewability'] ) ? (float) $row['viewability'] : 0.0 ),
		array( 'fill_rate', '%f', isset( $row['fill_rate'] ) ? (float) $row['fill_rate'] : 0.0 ),
		array( 'impressions_per_pageview', '%f', isset( $row['impressions_per_pageview'] ) ? (float) $row['impressions_per_pageview'] : 0.0 ),
		array( 'period_label', '%s', ! empty( $row['period_label'] ) ? (string) $row['period_label'] : 'all-time' ),
		array( 'period_start', null === $period_start ? 'NULL' : '%s', $period_start ),
		array( 'period_end', null === $period_end ? 'NULL' : '%s', $period_end ),
		array( 'import_batch', '%s', isset( $row['import_batch'] ) ? (string) $row['import_batch'] : '' ),
		array( 'imported_at', '%s', current_time( 'mysql', true ) ),
	);

	$columns      = array();
	$placeholders = array();
	$values       = array();
	foreach ( $fields as $field ) {
		list( $column, $placeholder, $value ) = $field;
		$columns[]                            = $column;
		$placeholders[]                       = $placeholder;
		if ( 'NULL' !== $placeholder ) {
			$values[] = $value;
		}
	}

	// REPLACE INTO honors the UNIQUE KEY: same snapshot overwrites in place.
	$sql = 'REPLACE INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- columns/placeholders are static; values prepared below.
	$prepared = $wpdb->prepare( $sql, $values );
	$result   = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return false === $result ? false : (int) $wpdb->insert_id;
}

/**
 * Fetch revenue snapshot rows for a blog, optionally scoped to a window.
 *
 * When both $period_start and $period_end are supplied, only snapshots whose
 * recorded window matches (or falls within) the requested window are returned —
 * this is what separates a "recent" lens from the all-time lens. When the window
 * is omitted, every snapshot for the blog is returned (lifetime view): callers
 * that mix multiple imports should pass an import_batch to avoid double-counting.
 *
 * @param array $args {
 *     Query arguments.
 *
 *     Query args.
 *
 *     @type int    $blog_id      Blog ID (default: current blog).
 *     @type string $import_batch Restrict to one import batch (default: '' = any).
 *     @type bool   $restrict_import_batch Match import_batch exactly, including an empty legacy batch.
 *     @type string $period_label Restrict to one time bucket, e.g. "2026-05" or "all-time" (default: '' = any).
 *     @type string $period_start Inclusive window start (Y-m-d), or '' for any.
 *     @type string $period_end   Inclusive window end (Y-m-d), or '' for any.
 * }
 * @return array<int, object> Snapshot rows.
 */
function extrachill_analytics_revenue_get_rows( array $args = array() ) {
	global $wpdb;

	$table = extrachill_analytics_revenue_table();

	list( $where_clause, $values ) = extrachill_analytics_revenue_build_scope_clause( $args );
	$sql                           = "SELECT * FROM {$table} WHERE {$where_clause}";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
}

/**
 * Build the canonical scope WHERE clause + bound values for a revenue query.
 *
 * Centralized so every read path (get_rows, get_scope_totals) constructs the
 * identical scope from the same args — no drift. Pure: no DB access.
 *
 * @param array $args {
 *     Query arguments.
 *
 *     @type int    $blog_id      Blog ID (default: current blog).
 *     @type string $import_batch Restrict to one import batch (default: '' = any).
 *     @type string $period_label Restrict to one time bucket (default: '' = any).
 *     @type string $period_start Inclusive window start (Y-m-d), or '' for any.
 *     @type string $period_end   Inclusive window end (Y-m-d), or '' for any.
 * }
 * @return array{0:string,1:array<int,mixed>} Tuple of (WHERE clause, values).
 */
function extrachill_analytics_revenue_build_scope_clause( array $args = array() ) {
	$blog_id        = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
	$import_batch   = isset( $args['import_batch'] ) ? (string) $args['import_batch'] : '';
	$period_label   = isset( $args['period_label'] ) ? (string) $args['period_label'] : '';
	$period_start   = isset( $args['period_start'] ) ? (string) $args['period_start'] : '';
	$period_end     = isset( $args['period_end'] ) ? (string) $args['period_end'] : '';
	$restrict_batch = ! empty( $args['restrict_import_batch'] );

	$where  = array( 'blog_id = %d' );
	$values = array( $blog_id );

	if ( '' !== $import_batch || $restrict_batch ) {
		$where[]  = 'import_batch = %s';
		$values[] = $import_batch;
	}

	if ( '' !== $period_label ) {
		// Exact time-bucket match — the precise, drift-free way to scope to one
		// monthly export (vs the date-range overlap below).
		$where[]  = 'period_label = %s';
		$values[] = $period_label;
	}

	if ( '' !== $period_start && '' !== $period_end ) {
		// Snapshot window must sit inside the requested window.
		$where[]  = 'period_start >= %s';
		$values[] = $period_start;
		$where[]  = 'period_end <= %s';
		$values[] = $period_end;
	}

	return array( implode( ' AND ', $where ), $values );
}

/**
 * Independent SQL aggregate of rows, views, and revenue for a scope.
 *
 * Used by the diagnostics totals_reconciliation check as the INDEPENDENT
 * read-model side: MySQL's SUM() aggregator over the canonical scope, compared
 * against the PHP row-sum of get_rows() for the same scope. A divergence between
 * the two is a meaningful read-path regression signal (row hydration, type
 * coercion, a silent row cap) — the opposite of comparing a precomputed sum to
 * itself.
 *
 * @param array $args See extrachill_analytics_revenue_build_scope_clause().
 * @return object|null { rows_count, views, revenue } or null on error.
 */
function extrachill_analytics_revenue_get_scope_totals( array $args = array() ) {
	global $wpdb;

	$table = extrachill_analytics_revenue_table();

	list( $where_clause, $values ) = extrachill_analytics_revenue_build_scope_clause( $args );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_clause is built only from hardcoded fragments whose values are bound via prepare().
	$sql = "SELECT COUNT(*) AS rows_count, COALESCE( SUM( views ), 0 ) AS views, COALESCE( SUM( revenue ), 0 ) AS revenue FROM {$table} WHERE {$where_clause}";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_row( $wpdb->prepare( $sql, $values ) );
}

/**
 * List distinct import batches for a blog, newest first.
 *
 * @param int $blog_id Blog ID (default: current blog).
 * @return array<int, object> Rows with import_batch, period_label, period_start, period_end, rows, imported_at.
 */
function extrachill_analytics_revenue_list_batches( $blog_id = 0 ) {
	global $wpdb;

	$table   = extrachill_analytics_revenue_table();
	$blog_id = $blog_id > 0 ? (int) $blog_id : get_current_blog_id();

	$sql = "SELECT import_batch, period_label, period_start, period_end, COUNT(*) AS rows_count, MAX(imported_at) AS imported_at,
		SUM(revenue) AS revenue, SUM(views) AS views
		FROM {$table} WHERE blog_id = %d
		GROUP BY import_batch, period_label, period_start, period_end
		ORDER BY imported_at DESC";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $blog_id ) );
}

/**
 * The revenue ARC: revenue/views totals per time bucket, chronologically.
 *
 * This is the first-class time-series read. It SUMs each period_label's rows so
 * a sequence of monthly exports renders the real revenue arc — month-over-month,
 * the HCU cliff in dollars, earning-now vs old-accumulated. The flat lifetime
 * file lands in a single "all-time" bucket and is excluded here by default
 * (it is a cumulative total, not a point on the arc, so charting it alongside
 * monthly points would be a category error).
 *
 * @param array $args {
 *     Query args.
 *
 *     @type int  $blog_id        Blog ID (default: current blog).
 *     @type bool $include_alltime Include the cumulative "all-time" bucket (default: false).
 * }
 * @return array<int, object> Rows: period_label, period_start, period_end, pages, views, revenue.
 */
function extrachill_analytics_revenue_get_timeseries( array $args = array() ) {
	global $wpdb;

	$table           = extrachill_analytics_revenue_table();
	$blog_id         = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
	$include_alltime = ! empty( $args['include_alltime'] );

	$where  = array( 'blog_id = %d' );
	$values = array( $blog_id );

	if ( ! $include_alltime ) {
		$where[]  = 'period_label <> %s';
		$values[] = 'all-time';
	}

	$where_clause = implode( ' AND ', $where );

	// Distinct-page count must be storage-agnostic: a resolved row is keyed by
	// its post_id, an unresolved (legacy .html) row by its slug. We mirror the
	// PHP join in get-content-revenue.php ($page_key = post_id>0 ? "p".post_id :
	// "u".slug) in SQL with a CASE — NOT IFNULL(post_id, slug), which silently
	// collapses every unresolved page to one bucket if any row ever stored
	// post_id as 0 instead of NULL (the exact bug that under-counted the 963
	// legacy .html ghost pages). post_id is now stored as a true NULL, but the
	// CASE is correct regardless of how it was stored.
	//
	// Order chronologically left-to-right. The cumulative "all-time" flat-file
	// bucket has no period_start (stored as a true NULL — the flat lifetime
	// export carries no dates), so it must sort LAST: it is a lifetime total, not
	// a point on the arc. The explicit all-time label and any NULL/zero-date are
	// pushed to the end (zero-date guard kept for any legacy pre-fix rows).
	$sql = "SELECT period_label,
			MIN(period_start) AS period_start,
			MAX(period_end) AS period_end,
			COUNT(DISTINCT CASE WHEN post_id > 0 THEN CONCAT('p', post_id) ELSE CONCAT('u', slug) END) AS pages,
			SUM(views) AS views,
			SUM(revenue) AS revenue
		FROM {$table}
		WHERE {$where_clause}
		GROUP BY period_label
		ORDER BY ( period_label = 'all-time' OR MIN(period_start) IS NULL OR MIN(period_start) = '0000-00-00' ) ASC,
			MIN(period_start) ASC, period_label ASC";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
}

/**
 * Delete revenue snapshot rows by their primary-key IDs.
 *
 * Scoped deletion used by the ingestion ability's deterministic-replace path to
 * remove rows that belonged to a snapshot but disappeared from a refreshed
 * source. Callers MUST pass IDs they obtained from a snapshot query scoped to
 * exactly one (blog_id, period_label, import_batch) so the delete can never
 * escape that snapshot. There is intentionally no "delete by slug" helper here:
 * the caller always holds the concrete row IDs it intends to remove.
 *
 * @param array<int> $ids Row IDs to delete.
 * @return int|false Number of rows deleted, or false on error.
 */
function extrachill_analytics_revenue_delete_ids( array $ids ) {
	$ids = array_values(
		array_filter(
			array_map( 'intval', $ids ),
			static function ( $id ) {
				return $id > 0;
			}
		)
	);

	if ( empty( $ids ) ) {
		return 0;
	}

	global $wpdb;

	$table        = extrachill_analytics_revenue_table();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql          = "DELETE FROM {$table} WHERE id IN ({$placeholders})";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query( $wpdb->prepare( $sql, $ids ) );

	return false === $result ? false : (int) $result;
}

/**
 * Begin a transaction around a revenue snapshot mutation.
 *
 * A no-op safety net on storage engines without transactions (e.g. MyISAM); on
 * InnoDB it makes the ingestion ability's replace path (upserts + stale-row
 * deletes) atomic so a mid-snapshot failure rolls back to the prior snapshot
 * instead of leaving a half-replaced one.
 *
 * @return bool True on success (or when unsupported), false on error.
 */
function extrachill_analytics_revenue_transaction_begin() {
	global $wpdb;
	$result = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	return false !== $result;
}

/**
 * Commit the active revenue mutation transaction.
 *
 * @return bool True on success, false on error.
 */
function extrachill_analytics_revenue_transaction_commit() {
	global $wpdb;
	$result = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	return false !== $result;
}

/**
 * Roll back the active revenue mutation transaction.
 *
 * @return bool True on success, false on error.
 */
function extrachill_analytics_revenue_transaction_rollback() {
	global $wpdb;
	$result = $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	return false !== $result;
}

/**
 * Acquire a named advisory lock to serialize ingestion for one period.
 *
 * Concurrent refreshes of the same (blog, period) can otherwise union snapshots:
 * each reads the prior snapshot, then both upsert/delete, re-adding rows the
 * other removed. MySQL/MariaDB session-scoped advisory locks (GET_LOCK) are
 * independent of transactions but bound to the connection, so acquiring one
 * here — held until release after commit — serializes the whole critical
 * section per period. Returns false if the lock could not be obtained within
 * the timeout so the caller fails closed instead of racing.
 *
 * @param string $name    Lock name.
 * @param int    $timeout Seconds to wait (0 = non-blocking).
 * @return bool True if the lock was acquired.
 */
function extrachill_analytics_revenue_lock_acquire( $name, $timeout = 10 ) {
	global $wpdb;
	$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	return '1' === $result;
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
	return '1' === $result;
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
		$derived_end   = gmdate( 'Y-m-t', strtotime( $derived_start ) );
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
