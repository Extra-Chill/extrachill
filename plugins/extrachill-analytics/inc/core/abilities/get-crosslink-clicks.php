<?php
/**
 * Crosslink click report (#1436).
 *
 * Per source post: how many readers clicked a network link inside the post
 * body, against the post's human pageviews in the same window. Destinations
 * that match the post's Data Machine internal-link record are marked
 * `inserted`; everything else is `manual`. Posts with inserted links but no
 * clicks are still listed, so a crosslinking pass that earns nothing shows up
 * as zero rather than disappearing.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/** Register the crosslink click reader. */
function extrachill_analytics_register_crosslink_clicks_ability() {
	wp_register_ability(
		'extrachill/get-crosslink-clicks',
		array(
			'label'               => __( 'Get Crosslink Clicks', 'extrachill-analytics' ),
			'description'         => __( 'Per source post: in-article network link clicks, unique visitors, and click rate over human pageviews, with each destination marked inserted (Data Machine) or manual.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'         => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Site to report on. 0 for the current site.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'post_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Limit the report to one source post. 0 for all.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'limit'        => array(
						'type'        => 'integer',
						'description' => __( 'Number of source posts to return.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'include_bots' => array(
						'type'        => 'boolean',
						'description' => __( 'Include bot-stamped events for diagnostics. Human-only by default.', 'extrachill-analytics' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with totals, posts (per source post rows with destinations), the window, and the site.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_crosslink_clicks',
			'permission_callback' => 'extrachill_analytics_can_read_reports',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Normalize a host + path pair into the destination key used for matching.
 *
 * @param string $host Host.
 * @param string $path Path.
 * @return string
 */
function extrachill_analytics_crosslink_dest_key( $host, $path ) {
	$path = '/' . trim( (string) $path, '/' );
	return strtolower( rtrim( (string) $host, '.' ) ) . ( '/' === $path ? '/' : $path . '/' );
}

/**
 * Destinations Data Machine inserted into a post, as destination keys.
 *
 * @param int $post_id Post ID on the current blog.
 * @return string[]
 */
function extrachill_analytics_crosslink_inserted_keys( $post_id ) {
	$meta = get_post_meta( $post_id, '_datamachine_internal_links', true );
	$keys = array();
	foreach ( (array) ( is_array( $meta ) ? ( $meta['links'] ?? array() ) : array() ) as $link ) {
		$url   = is_array( $link ) ? (string) ( $link['url'] ?? '' ) : '';
		$parts = '' !== $url ? wp_parse_url( $url ) : false;
		if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
			$keys[] = extrachill_analytics_crosslink_dest_key( $parts['host'], $parts['path'] ?? '/' );
		}
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Build the crosslink click report.
 *
 * @param array $input Ability input.
 * @return array
 */
function extrachill_analytics_ability_get_crosslink_clicks( $input ) {
	global $wpdb;

	$days         = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 28;
	$blog_id      = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : get_current_blog_id();
	$only_post    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$limit        = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 25;
	$include_bots = ! empty( $input['include_bots'] );
	$date_from    = $days > 0 ? gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) : '';

	$query_args = array(
		'event_type' => EC_ANALYTICS_EVENT_CONTENT_LINK,
		'blog_id'    => $blog_id,
		'limit'      => 5000,
		'offset'     => 0,
	);
	if ( '' !== $date_from ) {
		$query_args['date_from'] = $date_from;
	}

	$posts = array();
	do {
		$page       = (array) extrachill_get_analytics_events( $query_args );
		$page_count = count( $page );
		foreach ( $page as $row ) {
			$data = is_array( $row->event_data ) ? $row->event_data : array();
			if ( ! $include_bots && ( ! array_key_exists( 'is_bot', $data ) || false !== $data['is_bot'] ) ) {
				continue;
			}
			$post_id = (int) ( $data['post_id'] ?? 0 );
			if ( $post_id <= 0 || ( $only_post > 0 && $post_id !== $only_post ) ) {
				continue;
			}
			if ( ! isset( $posts[ $post_id ] ) ) {
				$posts[ $post_id ] = array(
					'clicks'       => 0,
					'visitors'     => array(),
					'destinations' => array(),
				);
			}
			++$posts[ $post_id ]['clicks'];
			if ( ! empty( $row->visitor_id ) ) {
				$posts[ $post_id ]['visitors'][ (string) $row->visitor_id ] = true;
			}
			$key = extrachill_analytics_crosslink_dest_key( (string) ( $data['dest_host'] ?? '' ), (string) ( $data['dest_path'] ?? '/' ) );
			if ( ! isset( $posts[ $post_id ]['destinations'][ $key ] ) ) {
				$posts[ $post_id ]['destinations'][ $key ] = array(
					'clicks'    => 0,
					'link_kind' => (string) ( $data['link_kind'] ?? '' ),
				);
			}
			++$posts[ $post_id ]['destinations'][ $key ]['clicks'];
		}
		$query_args['offset'] += $query_args['limit'];
	} while ( $page_count === $query_args['limit'] );

	$switched = get_current_blog_id() !== $blog_id && is_multisite();
	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	// Posts Data Machine crosslinked stay in the report even with zero clicks.
	$linked_ids = $only_post > 0
		? ( metadata_exists( 'post', $only_post, '_datamachine_internal_links' ) ? array( $only_post ) : array() )
		: get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'publish',
				'meta_key'         => '_datamachine_internal_links', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded admin report.
				'fields'           => 'ids',
				'posts_per_page'   => 100,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
	foreach ( $linked_ids as $linked_id ) {
		if ( ! isset( $posts[ (int) $linked_id ] ) ) {
			$posts[ (int) $linked_id ] = array(
				'clicks'       => 0,
				'visitors'     => array(),
				'destinations' => array(),
			);
		}
	}

	$rows = array();
	foreach ( $posts as $post_id => $agg ) {
		$inserted  = extrachill_analytics_crosslink_inserted_keys( $post_id );
		$permalink = get_permalink( $post_id );

		$destinations = array();
		$by_origin    = array(
			'inserted' => 0,
			'manual'   => 0,
		);
		foreach ( $agg['destinations'] as $key => $dest ) {
			$origin                = in_array( $key, $inserted, true ) ? 'inserted' : 'manual';
			$by_origin[ $origin ] += $dest['clicks'];
			$destinations[]        = array(
				'destination' => $key,
				'clicks'      => $dest['clicks'],
				'link_kind'   => $dest['link_kind'],
				'origin'      => $origin,
			);
		}
		usort(
			$destinations,
			static function ( $a, $b ) {
				return $b['clicks'] <=> $a['clicks'];
			}
		);

		$rows[ $post_id ] = array(
			'post_id'          => $post_id,
			'title'            => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES ),
			'url'              => is_string( $permalink ) ? $permalink : '',
			'pageviews'        => 0,
			'pageview_viewers' => 0,
			'clicks'           => $agg['clicks'],
			'click_visitors'   => count( $agg['visitors'] ),
			'click_rate'       => 0.0,
			'inserted_links'   => count( $inserted ),
			'inserted_clicks'  => $by_origin['inserted'],
			'manual_clicks'    => $by_origin['manual'],
			'destinations'     => $destinations,
		);
	}

	if ( $switched ) {
		restore_current_blog();
	}

	// Human pageviews per post. The pageview writer stores get_permalink() as
	// source_url, so matching on it is exact and needs no JSON parsing.
	$by_url = array();
	foreach ( $rows as $post_id => $row ) {
		if ( '' !== $row['url'] ) {
			$by_url[ $row['url'] ] = $post_id;
		}
	}
	foreach ( array_chunk( array_keys( $by_url ), 200 ) as $urls ) {
		$table        = extrachill_analytics_events_table();
		$placeholders = implode( ', ', array_fill( 0, count( $urls ), '%s' ) );
		$params       = array_merge( array( 'pageview', $blog_id ), $urls );
		$date_sql     = '';
		if ( '' !== $date_from ) {
			$date_sql = ' AND created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		$bot_sql = $include_bots ? '' : ' AND event_data NOT LIKE %s';
		if ( ! $include_bots ) {
			$params[] = '%' . $wpdb->esc_like( '"is_bot":true' ) . '%';
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery -- table name and placeholder list are built locally; all values are prepared.
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_url, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS viewers FROM {$table} WHERE event_type = %s AND blog_id = %d AND source_url IN ({$placeholders}){$date_sql}{$bot_sql} GROUP BY source_url",
				$params
			)
		);
		// phpcs:enable
		foreach ( (array) $counts as $count ) {
			$post_id = $by_url[ (string) $count->source_url ] ?? 0;
			if ( isset( $rows[ $post_id ] ) ) {
				$rows[ $post_id ]['pageviews']        = (int) $count->views;
				$rows[ $post_id ]['pageview_viewers'] = (int) $count->viewers;
			}
		}
	}

	$totals = array(
		'posts'           => count( $rows ),
		'pageviews'       => 0,
		'clicks'          => 0,
		'inserted_clicks' => 0,
		'manual_clicks'   => 0,
		'click_rate'      => 0.0,
	);
	foreach ( $rows as $post_id => $row ) {
		$rows[ $post_id ]['click_rate'] = $row['pageviews'] > 0 ? round( $row['clicks'] / $row['pageviews'], 4 ) : 0.0;
		$totals['pageviews']           += $row['pageviews'];
		$totals['clicks']              += $row['clicks'];
		$totals['inserted_clicks']     += $row['inserted_clicks'];
		$totals['manual_clicks']       += $row['manual_clicks'];
	}
	$totals['click_rate'] = $totals['pageviews'] > 0 ? round( $totals['clicks'] / $totals['pageviews'], 4 ) : 0.0;

	$rows = array_values( $rows );
	usort(
		$rows,
		static function ( $a, $b ) {
			return array( $b['clicks'], $b['pageviews'] ) <=> array( $a['clicks'], $a['pageviews'] );
		}
	);

	return array(
		'blog_id' => $blog_id,
		'period'  => '' !== $date_from ? $date_from . ' → ' . gmdate( 'Y-m-d' ) : 'all time',
		'totals'  => $totals,
		'posts'   => array_slice( $rows, 0, $limit ),
		'note'    => 'content_link_click is new: it counts clicks going forward only, so low numbers are the young-data state.',
	);
}
