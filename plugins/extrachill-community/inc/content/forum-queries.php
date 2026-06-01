<?php
/**
 * Forum Query Builders
 *
 * Query-building logic extracted out of bbPress template parts so the
 * templates stay presentational. Templates call these helpers and consume a
 * prepared loop / result set instead of assembling WP_Query args (and raw
 * SQL) inline.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the WP_Query argument array for the context-aware topics loop.
 *
 * Resolves base args from the active bbPress topic query (falling back to
 * sane forum defaults), then layers the current sort (default / upvotes /
 * popular) and search selections from the request.
 *
 * @return array Arguments suitable for bbp_has_topics().
 */
function ec_get_topics_loop_args() {
	global $bbp;

	$current_sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'default'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public sort selection.
	$current_search = isset( $_GET['bbp_search'] ) ? sanitize_text_field( wp_unslash( $_GET['bbp_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public search selection.

	// Determine base args from the active bbPress topic query, or defaults.
	if ( ! empty( $bbp->topic_query->query_vars ) ) {
		$loop_args = $bbp->topic_query->query_vars;
	} else {
		$loop_args = array();
	}

	// Ensure essential defaults regardless of context.
	$loop_args['post_type']      = $loop_args['post_type'] ?? bbp_get_topic_post_type();
	$loop_args['posts_per_page'] = $loop_args['posts_per_page'] ?? get_option( '_bbp_topics_per_page', 15 );
	$loop_args['paged']          = $loop_args['paged'] ?? bbp_get_paged();
	$loop_args['post_status']    = $loop_args['post_status'] ?? 'publish';

	// Apply sorting.
	if ( 'upvotes' === $current_sort ) {
		$loop_args['meta_key'] = 'upvote_count'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$loop_args['orderby']  = 'meta_value_num';
		$loop_args['order']    = 'DESC';
	} elseif ( 'popular' === $current_sort ) {
		$popular_ids           = ec_get_popular_topic_ids();
		$loop_args['post__in'] = ! empty( $popular_ids ) ? $popular_ids : array( 0 );
		$loop_args['orderby']  = 'post__in';
	} else {
		$loop_args = array_merge(
			array(
				'orderby'   => 'meta_value',
				'meta_key'  => '_bbp_last_active_time', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_type' => 'DATETIME',
				'order'     => 'DESC',
			),
			$loop_args
		);
	}

	// Apply search (bbPress default 's' parameter).
	if ( ! empty( $current_search ) ) {
		$loop_args['s'] = $current_search;
	}

	return $loop_args;
}

/**
 * Get topic IDs ordered by reply activity over the last 45 days.
 *
 * Backs the "popular" sort. Returns parent topic IDs ordered by the number of
 * replies they received in the window, most active first.
 *
 * @param int $days  Lookback window in days. Default 45.
 * @param int $limit Maximum number of topic IDs to return. Default 100.
 * @return int[] Topic IDs ordered by recent reply count.
 */
function ec_get_popular_topic_ids( $days = 45, $limit = 100 ) {
	global $wpdb;

	$since = gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate ordering not expressible via WP_Query; uncached by design (live ranking).
	$popular_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.post_parent FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->posts} t ON p.post_parent = t.ID
			WHERE p.post_type = %s AND t.post_type = %s AND p.post_date >= %s
			GROUP BY p.post_parent ORDER BY COUNT(p.ID) DESC LIMIT %d",
			bbp_get_reply_post_type(),
			bbp_get_topic_post_type(),
			$since,
			absint( $limit )
		)
	);

	return array_map( 'intval', (array) $popular_ids );
}

/**
 * Query the most recently active topics, optionally excluding a topic.
 *
 * Backs the topic sidebar's "Recently Active" list.
 *
 * @param int $number          Number of topics to return. Default 6.
 * @param int $exclude_topic_id Topic ID to exclude (e.g. the current topic). Default 0.
 * @return WP_Query
 */
function ec_get_recently_active_topics( $number = 6, $exclude_topic_id = 0 ) {
	$args = array(
		'post_type'      => bbp_get_topic_post_type(),
		'posts_per_page' => absint( $number ),
		'orderby'        => 'meta_value',
		'meta_key'       => '_bbp_last_active_time', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_type'      => 'DATETIME',
		'order'          => 'DESC',
	);

	if ( $exclude_topic_id ) {
		$args['post__not_in'] = array( (int) $exclude_topic_id );
	}

	return new WP_Query( $args );
}

/**
 * Query the displayed user's most recent topic or reply.
 *
 * Backs the "Welcome back, your last post was..." message on the user profile.
 *
 * @param int $user_id User ID.
 * @return WP_Query
 */
function ec_get_user_last_post( $user_id ) {
	return new WP_Query(
		array(
			'author'         => (int) $user_id,
			'post_type'      => array( bbp_get_reply_post_type(), bbp_get_topic_post_type() ),
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}
