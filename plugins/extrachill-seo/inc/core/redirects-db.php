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

	if ( $current === EXTRACHILL_SEO_REDIRECTS_DB_VERSION ) {
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

	return $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM ' . extrachill_seo_redirects_table() . ' WHERE from_url = %s AND active = 1',
			$from_url
		)
	);
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

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
			$vals
		)
	);
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

	if ( ! empty( $vals ) ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", $vals ) );
	}

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}" );
}

/**
 * Record a hit on a redirect rule.
 *
 * @param int $id Redirect rule ID.
 */
function extrachill_seo_record_redirect_hit( $id ) {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . extrachill_seo_redirects_table() . ' SET hit_count = hit_count + 1, last_hit = %s WHERE id = %d',
			current_time( 'mysql', true ),
			absint( $id )
		)
	);
}
