<?php
/**
 * Redirect Rules Database Table
 *
 * Creates and manages the network-wide redirect rules table.
 * Stores individual URL redirect rules managed via CLI or admin.
 *
 * @package ExtraChill\SEO
 * @since 0.9.0
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_SEO_REDIRECTS_DB_VERSION', '1.0' );
define( 'EXTRACHILL_SEO_REDIRECTS_DB_VERSION_OPTION', 'extrachill_seo_redirects_db_version' );

/**
 * Create or update the redirects table when DB version changes.
 *
 * Uses base_prefix for network-wide table shared across all sites.
 */
function extrachill_seo_redirects_create_table() {
	$current = get_site_option( EXTRACHILL_SEO_REDIRECTS_DB_VERSION_OPTION );

	if ( EXTRACHILL_SEO_REDIRECTS_DB_VERSION === $current ) {
		return;
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = extrachill_seo_redirects_table();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		from_url varchar(2083) NOT NULL,
		to_url varchar(2083) NOT NULL,
		status_code smallint(3) NOT NULL DEFAULT 301,
		hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
		last_hit datetime DEFAULT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		created_by varchar(100) DEFAULT '',
		note varchar(500) DEFAULT '',
		active tinyint(1) NOT NULL DEFAULT 1,
		PRIMARY KEY  (id),
		UNIQUE KEY from_url_idx (from_url(191)),
		KEY active_idx (active),
		KEY hit_count_idx (hit_count)
	) {$charset_collate};";

	dbDelta( $sql );

	update_site_option( EXTRACHILL_SEO_REDIRECTS_DB_VERSION_OPTION, EXTRACHILL_SEO_REDIRECTS_DB_VERSION );
}

add_action( 'admin_init', __NAMESPACE__ . '\\extrachill_seo_redirects_create_table' );

// Also run on CLI context since admin_init doesn't fire in WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'wp_loaded', __NAMESPACE__ . '\\extrachill_seo_redirects_create_table' );
}

/**
 * Get the redirects table name.
 *
 * @return string Full table name with prefix.
 */
function extrachill_seo_redirects_table() {
	global $wpdb;
	return $wpdb->base_prefix . 'extrachill_seo_redirects';
}

/**
 * Add a redirect rule.
 *
 * @param string $from_url    Source URL path (e.g., /old-page).
 * @param string $to_url      Destination URL or path (e.g., /new-page/ or https://...).
 * @param int    $status_code HTTP status code (301 or 302).
 * @param string $note        Optional note about the redirect.
 * @param string $created_by  Who created the rule (e.g., 'cli', 'admin', 'auto').
 * @return int|false Redirect ID on success, false on failure.
 */
function extrachill_seo_add_redirect( $from_url, $to_url, $status_code = 301, $note = '', $created_by = '' ) {
	global $wpdb;

	$from_url = '/' . ltrim( $from_url, '/' );
	$from_url = untrailingslashit( $from_url );

	// Check for existing rule.
	$existing = extrachill_seo_get_redirect_by_url( $from_url );
	if ( $existing ) {
		return false;
	}

	$result = $wpdb->insert(
		extrachill_seo_redirects_table(),
		array(
			'from_url'    => $from_url,
			'to_url'      => $to_url,
			'status_code' => absint( $status_code ),
			'note'        => sanitize_text_field( $note ),
			'created_by'  => sanitize_text_field( $created_by ),
			'active'      => 1,
			'created_at'  => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
	);

	return false !== $result ? $wpdb->insert_id : false;
}

/**
 * Delete a redirect rule by ID.
 *
 * @param int $id Redirect rule ID.
 * @return bool Whether the delete succeeded.
 */
function extrachill_seo_delete_redirect( $id ) {
	global $wpdb;
	return (bool) $wpdb->delete( extrachill_seo_redirects_table(), array( 'id' => absint( $id ) ), array( '%d' ) );
}

/**
 * Get a redirect rule by source URL.
 *
 * @param string $from_url Source URL path.
 * @return object|null Redirect row or null.
 */
function extrachill_seo_get_redirect_by_url( $from_url ) {
	global $wpdb;

	$from_url = '/' . ltrim( $from_url, '/' );
	$from_url = untrailingslashit( $from_url );

	// The table name is an internal, prefix-derived identifier (not user input)
	// and cannot be bound as a prepare() placeholder; the value is bound via %s.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		'SELECT * FROM ' . extrachill_seo_redirects_table() . ' WHERE from_url = %s AND active = 1',
		$from_url
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->get_row( $sql );
}

/**
 * Query redirect rules.
 *
 * @param array $args Query arguments.
 *                    - search (string): Search from_url or to_url.
 *                    - active (int): Filter by active status (1 or 0). -1 for all.
 *                    - status_code (int): Filter by HTTP status code.
 *                    - orderby (string): Column to order by.
 *                    - order (string): ASC or DESC.
 *                    - limit (int): Number of results.
 *                    - offset (int): Offset for pagination.
 * @return array Array of redirect rule objects.
 */
function extrachill_seo_get_redirects( $args = array() ) {
	global $wpdb;

	$defaults = array(
		'search'      => '',
		'active'      => -1,
		'status_code' => 0,
		'orderby'     => 'created_at',
		'order'       => 'DESC',
		'limit'       => 100,
		'offset'      => 0,
	);

	$args  = wp_parse_args( $args, $defaults );
	$table = extrachill_seo_redirects_table();
	$where = array( '1=1' );
	$vals  = array();

	if ( $args['active'] >= 0 ) {
		$where[] = 'active = %d';
		$vals[]  = absint( $args['active'] );
	}

	if ( ! empty( $args['search'] ) ) {
		$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$where[] = '(from_url LIKE %s OR to_url LIKE %s)';
		$vals[]  = $like;
		$vals[]  = $like;
	}

	if ( ! empty( $args['status_code'] ) ) {
		$where[] = 'status_code = %d';
		$vals[]  = absint( $args['status_code'] );
	}

	$allowed_orderby = array( 'id', 'from_url', 'to_url', 'status_code', 'hit_count', 'last_hit', 'created_at' );
	$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
	$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

	$where_clause = implode( ' AND ', $where );

	$vals[] = absint( $args['limit'] );
	$vals[] = absint( $args['offset'] );

	// $table is an internal, prefix-derived identifier; $where_clause is built
	// only from hard-coded `%d`/`%s` fragments (values passed via $vals); and
	// $orderby/$order are whitelisted above. Table/column identifiers cannot be
	// bound as prepare() placeholders, so this query is safe by construction.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$sql = $wpdb->prepare(
		"SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
		...$vals
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->get_results( $sql );
}

/**
 * Count redirect rules matching criteria.
 *
 * @param array $args Same as extrachill_seo_get_redirects (excluding limit/offset/order).
 * @return int Total count.
 */
function extrachill_seo_count_redirects( $args = array() ) {
	global $wpdb;

	$defaults = array(
		'search'      => '',
		'active'      => -1,
		'status_code' => 0,
	);

	$args  = wp_parse_args( $args, $defaults );
	$table = extrachill_seo_redirects_table();
	$where = array( '1=1' );
	$vals  = array();

	if ( $args['active'] >= 0 ) {
		$where[] = 'active = %d';
		$vals[]  = absint( $args['active'] );
	}

	if ( ! empty( $args['search'] ) ) {
		$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$where[] = '(from_url LIKE %s OR to_url LIKE %s)';
		$vals[]  = $like;
		$vals[]  = $like;
	}

	if ( ! empty( $args['status_code'] ) ) {
		$where[] = 'status_code = %d';
		$vals[]  = absint( $args['status_code'] );
	}

	$where_clause = implode( ' AND ', $where );

	// $table is an internal, prefix-derived identifier and $where_clause is
	// built only from hard-coded `%d`/`%s` fragments (values passed via $vals).
	if ( ! empty( $vals ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", ...$vals );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	// No dynamic values: $where_clause is the literal '1=1' and $table is the
	// internal prefix-derived identifier, so there is nothing to prepare.
	$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	return (int) $wpdb->get_var( $count_sql );
}

/**
 * Record a hit on a redirect rule.
 *
 * @param int $id Redirect rule ID.
 */
function extrachill_seo_record_redirect_hit( $id ) {
	global $wpdb;

	// The table name is an internal, prefix-derived identifier (not user input)
	// and cannot be bound as a prepare() placeholder; the values are bound via
	// %s/%d.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		'UPDATE ' . extrachill_seo_redirects_table() . ' SET hit_count = hit_count + 1, last_hit = %s WHERE id = %d',
		current_time( 'mysql', true ),
		absint( $id )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $sql );
}

/**
 * Capture a redirect-fire conversion event.
 *
 * Writes a `redirect_fire` row into the network-wide analytics events table
 * (`c8c_extrachill_analytics_events`) carrying the rule id, normalized
 * destination path and host, and — crucially — the visitor's existing
 * first-party `ec_vid` UUID (read-only, never minted here). That visitor_id is
 * what later stitches this fire to the visitor's subsequent `pageview` events
 * so the conversion-outcome reader (engaged / onward) can be computed entirely
 * from first-party, bot-filtered signal.
 *
 * Why reuse the analytics events table rather than a new store: the events
 * table is already the canonical first-party visitor history (it owns the
 * `ec_vid` resolver, the GPC/DNT opt-out, and the bot filter), and the reader
 * MUST join against `pageview` rows in that same table to know what the user
 * did after landing. A separate store could never answer "did they engage on
 * the destination?" without duplicating the pageview stream.
 *
 * Bot-resistant by construction: skipped for known bot user-agents (mirroring
 * the pageview/404 capture paths). Opted-out visitors (GPC/DNT) still record an
 * anonymous fire (NULL visitor_id) so hit volume stays honest, but they are
 * excluded from per-visitor conversion metrics by the reader.
 *
 * No-ops cleanly when extrachill-analytics is absent (function_exists guard) —
 * extrachill-seo never hard-depends on it.
 *
 * @param object $rule The matched redirect rule row (needs ->id, ->to_url).
 */
function extrachill_seo_record_redirect_fire( $rule ) {
	if ( ! is_object( $rule ) || empty( $rule->id ) ) {
		return;
	}

	// The analytics substrate owns the events table + visitor resolver. If it
	// is not present, there is nothing to capture against — silently no-op.
	if ( ! function_exists( 'extrachill_track_analytics_event' ) ) {
		return;
	}

	// Bot filter: mirror the pageview/404 capture paths so the conversion table
	// stays human-only by construction.
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
		: '';

	if ( function_exists( 'extrachill_analytics_is_bot' ) && extrachill_analytics_is_bot( $user_agent ) ) {
		return;
	}

	$to_url = isset( $rule->to_url ) ? (string) $rule->to_url : '';

	// Resolve the destination host (so the reader can tell an "onward to another
	// platform surface" hop — e.g. blog → events.extrachill.com — from a
	// same-site landing). Relative targets resolve against the current host.
	$dest_host = '';
	$dest_path = $to_url;
	if ( '' !== $to_url ) {
		$parsed = wp_parse_url( strpos( $to_url, 'http' ) === 0 ? $to_url : home_url( $to_url ) );
		if ( is_array( $parsed ) ) {
			$dest_host = isset( $parsed['host'] ) ? $parsed['host'] : '';
			$dest_path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		}
	}

	$source_path = isset( $rule->from_url ) ? (string) $rule->from_url : '';

	// visitor_id is left empty so extrachill_track_analytics_event() applies its
	// read-only `ec_vid` resolver (no minting here — the redirect fires deep in
	// the request, after the early mint hook). GPC/DNT opt-out is honored by the
	// resolver, which yields a NULL visitor_id.
	extrachill_track_analytics_event(
		'redirect_fire',
		array(
			'rule_id'   => (int) $rule->id,
			'from_url'  => $source_path,
			'to_url'    => $to_url,
			'dest_host' => $dest_host,
			'dest_path' => $dest_path,
		),
		$source_path
	);
}
