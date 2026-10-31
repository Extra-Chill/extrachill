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

	$tests['direct']['extrachill_search_post_type_map'] = array(
		'label' => __( 'Is the ExtraChill Search post-type map up to date?', 'extrachill-search' ),
		'test'  => 'extrachill_search_post_type_map_site_health_test',
	);

	return $tests;
}

/**
 * Report post-type map drift against the canonical domain map through Site Health.
 *
 * extrachill_get_site_post_types() is a hand-maintained map of blog ID =>
 * searchable post types, required because switch_to_blog() does not load
 * the target site's plugins, so search cannot introspect what CPTs a
 * remote blog actually registers. That map silently drifts out of sync
 * whenever a network site is added, removed, or (like Studio) deliberately
 * excluded from search without a corresponding map update.
 *
 * This check compares the post-type map's keys against every blog ID in
 * ec_get_domain_map(), excluding blog IDs deliberately excluded from
 * search (extrachill_get_network_search_excluded_blog_ids()), and flags:
 * - Domain-map blogs with no post-type map entry (a real site search
 *   cannot yet route correctly, silently falling back to array('post','page')).
 * - Post-type map entries with no corresponding domain-map blog (a stale
 *   entry for a decommissioned or renamed site).
 *
 * @return array Site Health test result.
 */
function extrachill_search_post_type_map_site_health_test() {
	$result = array(
		'label'       => __( 'ExtraChill Search post-type map matches the network', 'extrachill-search' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Performance', 'extrachill-search' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Every network site is either mapped to explicit searchable post types or deliberately excluded from search.', 'extrachill-search' ) . '</p>',
		'test'        => 'extrachill_search_post_type_map',
	);

	if ( ! function_exists( 'ec_get_domain_map' ) || ! function_exists( 'extrachill_get_site_post_types' ) ) {
		return $result;
	}

	$domain_map  = ec_get_domain_map();
	$domain_ids  = array();
	foreach ( $domain_map as $domain => $blog_id ) {
		$domain_ids[ (int) $blog_id ] = $domain;
	}

	$excluded_ids   = function_exists( 'extrachill_get_network_search_excluded_blog_ids' )
		? array_map( 'intval', extrachill_get_network_search_excluded_blog_ids() )
		: array();
	$post_type_map  = extrachill_get_site_post_types();
	$post_type_ids  = array_map( 'intval', array_keys( $post_type_map ) );

	$missing_from_map = array();
	foreach ( $domain_ids as $blog_id => $domain ) {
		if ( in_array( $blog_id, $excluded_ids, true ) ) {
			continue;
		}
		if ( ! in_array( $blog_id, $post_type_ids, true ) ) {
			$missing_from_map[] = sprintf( '%s (blog %d)', $domain, $blog_id );
		}
	}

	$stale_in_map = array();
	foreach ( $post_type_ids as $blog_id ) {
		if ( ! isset( $domain_ids[ $blog_id ] ) ) {
			$stale_in_map[] = (string) $blog_id;
		}
	}

	if ( empty( $missing_from_map ) && empty( $stale_in_map ) ) {
		return $result;
	}

	$result['label']  = __( 'ExtraChill Search post-type map is out of date', 'extrachill-search' );
	$result['status'] = 'recommended';

	$description = '';
	if ( ! empty( $missing_from_map ) ) {
		$description .= '<p>' . esc_html__( 'These network sites have no entry in extrachill_get_site_post_types() and silently fall back to array(\'post\',\'page\') when included in search:', 'extrachill-search' ) . ' ' . esc_html( implode( ', ', $missing_from_map ) ) . '</p>';
	}
	if ( ! empty( $stale_in_map ) ) {
		$description .= '<p>' . esc_html__( 'These blog IDs appear in extrachill_get_site_post_types() but no longer exist in ec_get_domain_map() (decommissioned or renamed):', 'extrachill-search' ) . ' ' . esc_html( implode( ', ', $stale_in_map ) ) . '</p>';
	}

	$result['description'] = $description;
	$result['actions']     = '<p>' . esc_html__( 'Add the missing blog to extrachill_get_site_post_types() with its actual searchable post types, add it to extrachill_get_network_search_excluded_blog_ids() if it should be excluded from search instead, or remove the stale entry.', 'extrachill-search' ) . '</p>';

	return $result;
}
