<?php
/**
 * FULLTEXT index readiness and installation.
 *
 * @package ExtraChill\Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspect the current site's posts-table FULLTEXT indexes.
 *
 * Readiness requires one FULLTEXT index whose ordered columns exactly match
 * every column used by MATCH(). The index name is intentionally irrelevant.
 *
 * @param bool $refresh Whether to bypass the request cache.
 * @return array Index readiness details.
 */
function extrachill_get_fulltext_index_status( $refresh = false ) {
	global $wpdb;

	static $cache = array();

	$table = $wpdb->posts;
	if ( ! $refresh && isset( $cache[ $table ] ) ) {
		return $cache[ $table ];
	}

	$expected_columns = array( 'post_title', 'post_excerpt', 'post_content' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SHOW INDEX FROM %i WHERE Index_type = %s',
			$table,
			'FULLTEXT'
		)
	);

	$indexes = array();
	foreach ( (array) $rows as $row ) {
		$name                    = (string) $row->Key_name;
		$seq                     = (int) $row->Seq_in_index;
		$indexes[ $name ][ $seq ] = (string) $row->Column_name;
	}

	$ready_index = '';
	foreach ( $indexes as $name => $columns ) {
		ksort( $columns );
		if ( array_values( $columns ) === $expected_columns ) {
			$ready_index = $name;
			break;
		}
	}

	$status = array(
		'blog_id'          => get_current_blog_id(),
		'table'            => $table,
		'ready'            => '' !== $ready_index,
		'index_name'       => $ready_index,
		'expected_columns' => $expected_columns,
		'indexes'          => $indexes,
		'reason'           => '' !== $ready_index ? 'ready' : ( empty( $indexes ) ? 'missing' : 'column_mismatch' ),
	);

	$cache[ $table ] = $status;
	return $status;
}

/**
 * Check whether the current site's exact search index is ready.
 *
 * @param bool $refresh Whether to bypass the request cache.
 * @return bool Whether the expected index is ready.
 */
function extrachill_has_fulltext_index( $refresh = false ) {
	$status = extrachill_get_fulltext_index_status( $refresh );
	return $status['ready'];
}

/**
 * Emit one request-level diagnostic for an unavailable search index.
 *
 * @param array $status Index readiness details.
 * @return void
 */
function extrachill_report_fulltext_index_failure( $status ) {
	static $reported = array();

	$key = $status['table'] . ':' . $status['reason'];
	if ( isset( $reported[ $key ] ) ) {
		return;
	}

	$reported[ $key ] = true;
	do_action( 'extrachill_search_index_not_ready', $status );
	// This is an operational readiness failure, not request-level debug output.
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log(
		sprintf(
			'ExtraChill Search index is not ready for blog %d (%s): %s',
			$status['blog_id'],
			$status['table'],
			$status['reason']
		)
	);
}

/**
 * Preview or install the expected FULLTEXT index for one site.
 *
 * @param int|null $blog_id Site ID, or null for the current site.
 * @param bool     $apply   Whether to execute the schema change.
 * @return array Readiness and repair details.
 */
function extrachill_ensure_fulltext_index( $blog_id = null, $apply = false ) {
	global $wpdb;

	$switched = null !== $blog_id && get_current_blog_id() !== (int) $blog_id;
	if ( $switched ) {
		switch_to_blog( (int) $blog_id );
	}

	try {
		$status = extrachill_get_fulltext_index_status( true );
		if ( $status['ready'] ) {
			$status['action']  = 'none';
			$status['applied'] = false;
			return $status;
		}

		$index_name = 'extrachill_search_fulltext';
		$sql        = $wpdb->prepare(
			'ALTER TABLE %i ADD FULLTEXT INDEX %i (post_title, post_excerpt, post_content)',
			$wpdb->posts,
			$index_name
		);

		$status['action']  = 'add_index';
		$status['applied'] = false;
		$status['sql']     = $sql;

		if ( ! $apply ) {
			return $status;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			$status['action'] = 'error';
			$status['error']  = $wpdb->last_error;
			return $status;
		}

		$status            = extrachill_get_fulltext_index_status( true );
		$status['action']  = $status['ready'] ? 'installed' : 'error';
		$status['applied'] = true;
		return $status;
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Report network search-index readiness through WordPress Site Health.
 *
 * @return array Site Health test result.
 */
function extrachill_search_index_site_health_test() {
	$failures = array();

	foreach ( extrachill_get_network_sites() as $site ) {
		$blog_id = (int) $site['id'];
		switch_to_blog( $blog_id );
		try {
			$status = extrachill_get_fulltext_index_status( true );
			if ( ! $status['ready'] ) {
				$failures[] = sprintf( '%s (blog %d: %s)', $site['url'], $blog_id, $status['reason'] );
			}
		} finally {
			restore_current_blog();
		}
	}

	$result = array(
		'label'       => __( 'ExtraChill Search indexes are ready', 'extrachill-search' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Performance', 'extrachill-search' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Every searchable network site has the exact FULLTEXT index required by public search.', 'extrachill-search' ) . '</p>',
		'test'        => 'extrachill_search_fulltext_indexes',
	);

	if ( ! empty( $failures ) ) {
		$result['label']       = __( 'ExtraChill Search indexes need repair', 'extrachill-search' );
		$result['status']      = 'critical';
		$result['description'] = '<p>' . esc_html__( 'Search fails closed on these sites to prevent expensive table scans:', 'extrachill-search' ) . ' ' . esc_html( implode( ', ', $failures ) ) . '</p>';
		$result['actions']     = '<p>' . esc_html__( 'Preview the site-scoped repair with extrachill_ensure_fulltext_index( $blog_id ), then explicitly apply it with the second argument set to true.', 'extrachill-search' ) . '</p>';
	}

	return $result;
}

/**
 * Add the search readiness test to the existing Site Health surface.
 *
 * @param array $tests Registered Site Health tests.
 * @return array Filtered tests.
 */
function extrachill_register_search_site_health_test( $tests ) {
	$tests['direct']['extrachill_search_fulltext_indexes'] = array(
		'label' => __( 'Are ExtraChill Search indexes ready?', 'extrachill-search' ),
		'test'  => 'extrachill_search_index_site_health_test',
	);

	return $tests;
}
