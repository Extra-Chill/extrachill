<?php
/**
 * Get Route Transitions Ability
 *
 * Bounded, read-only route journey reporting over the existing pageview stream.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-route-transitions ability.
 */
function extrachill_analytics_register_route_transitions_ability() {
	wp_register_ability(
		'extrachill/get-route-transitions',
		array(
			'label'               => __( 'Get Route Transitions', 'extrachill-analytics' ),
			'description'         => __( 'Returns bounded same-session route transitions, sequences, entries, and terminals from first-party pageviews across the multisite network.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'             => array(
						'type'        => 'integer',
						'description' => __( 'UTC lookback days, clamped to 1-90. Default 28.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id'          => array(
						'type'        => 'integer',
						'description' => __( 'Optional blog ID filter. Zero reports the network. Default 0.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'session_gap_mins' => array(
						'type'        => 'integer',
						'description' => __( 'Inactivity gap in minutes that ends a session, clamped to 1-120. Default 30.', 'extrachill-analytics' ),
						'default'     => 30,
					),
					'sequence_length'  => array(
						'type'        => 'integer',
						'description' => __( 'Exact number of route observations per sequence, clamped to 2-5. Default 3.', 'extrachill-analytics' ),
						'default'     => 3,
					),
					'cohort'           => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'first_time', 'returning' ),
						'description' => __( 'Session-entry acquisition cohort. Default all.', 'extrachill-analytics' ),
						'default'     => 'all',
					),
					'limit'            => array(
						'type'        => 'integer',
						'description' => __( 'Maximum rows in each ranking, clamped to 1-100. Default 25.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'max_pageviews'    => array(
						'type'        => 'integer',
						'description' => __( 'Maximum identified pageviews loaded, clamped to 100-25000. Default 10000.', 'extrachill-analytics' ),
						'default'     => 10000,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Ranked transitions, exact-length sequences, entries, terminals, cohort counts, coverage, bounds, and UTC period.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_route_transitions',
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
 * Execute the route-transition report.
 *
 * The event query uses the existing event_type_created index, selects the most
 * recent bounded pageview set, then orders that set by visitor and time for
 * sessionization. One inactivity gap of lower-boundary context is requested,
 * matching get-conversion-map. Acquisition lookups use visitor_created and are
 * restricted to identities already admitted by the bounded stream.
 *
 * @param array $input Ability input.
 * @return array Route transition report.
 */
function extrachill_analytics_ability_get_route_transitions( $input ) {
	global $wpdb;

	$days            = min( 90, max( 1, (int) ( $input['days'] ?? 28 ) ) );
	$blog_id         = max( 0, (int) ( $input['blog_id'] ?? 0 ) );
	$gap_mins        = min( 120, max( 1, (int) ( $input['session_gap_mins'] ?? 30 ) ) );
	$sequence_length = min( 5, max( 2, (int) ( $input['sequence_length'] ?? 3 ) ) );
	$limit           = min( 100, max( 1, (int) ( $input['limit'] ?? 25 ) ) );
	$max_pageviews   = min( 25000, max( 100, (int) ( $input['max_pageviews'] ?? 10000 ) ) );
	$cohort          = in_array( $input['cohort'] ?? 'all', array( 'all', 'first_time', 'returning' ), true ) ? $input['cohort'] : 'all';
	$gap_secs        = $gap_mins * MINUTE_IN_SECONDS;

	$table        = extrachill_analytics_events_table();
	$event_type   = defined( 'EC_ANALYTICS_EVENT_PAGEVIEW' ) ? EC_ANALYTICS_EVENT_PAGEVIEW : 'pageview';
	$until        = gmdate( 'Y-m-d H:i:s' );
	$since        = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	$stream_since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days -{$gap_mins} minutes" ) );

	$where  = array( 'event_type = %s', "visitor_id IS NOT NULL AND visitor_id != ''", 'created_at >= %s', 'created_at <= %s' );
	$values = array( $event_type, $stream_since, $until );
	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}
	$values[] = $max_pageviews + 1;

	// The table name and WHERE fragments are code-defined. Values, including the
	// hard row ceiling, are placeholders. This direct analytical read is not
	// cacheable and intentionally uses the existing event_type_created index.
	$sql = "SELECT bounded.id, bounded.visitor_id, bounded.blog_id, bounded.event_data, bounded.ts
		FROM (
			SELECT id, visitor_id, blog_id, event_data, UNIX_TIMESTAMP(created_at) AS ts
			FROM {$table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY created_at DESC, id DESC
			LIMIT %d
		) AS bounded
		ORDER BY bounded.visitor_id ASC, bounded.ts ASC, bounded.id ASC';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$truncated = count( $rows ) > $max_pageviews;
	$cutoff_ts = 0;
	if ( $truncated ) {
		$oldest_key = null;
		$oldest_ts  = PHP_INT_MAX;
		$oldest_id  = PHP_INT_MAX;
		foreach ( $rows as $key => $row ) {
			if ( (int) $row->ts < $oldest_ts || ( (int) $row->ts === $oldest_ts && (int) $row->id < $oldest_id ) ) {
				$oldest_key = $key;
				$oldest_ts  = (int) $row->ts;
				$oldest_id  = (int) $row->id;
			}
		}
		unset( $rows[ $oldest_key ] );
		$cutoff_ts = $oldest_ts;
	}

	$events      = array();
	$visitor_ids = array();
	foreach ( $rows as $row ) {
		$data         = json_decode( (string) $row->event_data, true );
		$data         = is_array( $data ) ? $data : array();
		$route_family = sanitize_key( (string) ( $data['route_family'] ?? '' ) );
		if ( '' === $route_family ) {
			$route_family = ! empty( $data['post_id'] ) ? 'singular' : 'unclassified';
		}
		$visitor_id                 = (string) $row->visitor_id;
		$visitor_ids[ $visitor_id ] = true;
		$events[]                   = array(
			'id'           => (int) $row->id,
			'visitor_id'   => $visitor_id,
			'blog_id'      => (int) $row->blog_id,
			'route_family' => $route_family,
			'ts'           => (int) $row->ts,
		);
	}

	$acquisitions = extrachill_analytics_route_transition_acquisitions( array_keys( $visitor_ids ), $table, $event_type );
	foreach ( $events as &$event ) {
		$event['acquisition_ts'] = isset( $acquisitions[ $event['visitor_id'] ] ) ? $acquisitions[ $event['visitor_id'] ] : 0;
	}
	unset( $event );

	$coverage_where  = array( 'event_type = %s', 'created_at >= %s', 'created_at <= %s' );
	$coverage_values = array( $event_type, $since, $until );
	if ( $blog_id > 0 ) {
		$coverage_where[]  = 'blog_id = %d';
		$coverage_values[] = $blog_id;
	}
	$coverage_sql = "SELECT
		COUNT(*) AS total_pageviews,
		SUM(CASE WHEN visitor_id IS NOT NULL AND visitor_id != '' THEN 1 ELSE 0 END) AS identified_pageviews,
		SUM(CASE WHEN visitor_id IS NULL OR visitor_id = '' THEN 1 ELSE 0 END) AS anonymous_pageviews,
		SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.route_family')) IS NOT NULL THEN 1 ELSE 0 END) AS explicit_route_family_pageviews,
		SUM(CASE WHEN JSON_EXTRACT(event_data, '$.route_family') IS NULL AND CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.post_id')) AS UNSIGNED) > 0 THEN 1 ELSE 0 END) AS inferred_singular_pageviews,
		SUM(CASE WHEN JSON_EXTRACT(event_data, '$.route_family') IS NULL AND (JSON_EXTRACT(event_data, '$.post_id') IS NULL OR CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.post_id')) AS UNSIGNED) = 0) THEN 1 ELSE 0 END) AS historical_unclassified_pageviews
		FROM {$table}
		WHERE " . implode( ' AND ', $coverage_where );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$coverage_row = $wpdb->get_row( $wpdb->prepare( $coverage_sql, $coverage_values ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// A truncated recent-row sample can begin in the middle of sessions for any
	// visitor active at its cutoff. Exclude one complete inactivity gap after
	// that cutoff so every admitted session still has exact identity semantics.
	$ranking_since_ts = max( strtotime( $since ), $truncated ? $cutoff_ts + $gap_secs : 0 );
	$report           = extrachill_analytics_build_route_transition_report(
		$events,
		array(
			'since_ts'        => $ranking_since_ts,
			'until_ts'        => strtotime( $until ),
			'gap_secs'        => $gap_secs,
			'sequence_length' => $sequence_length,
			'cohort'          => $cohort,
			'limit'           => $limit,
		)
	);

	$total_pageviews      = $coverage_row ? (int) $coverage_row->total_pageviews : 0;
	$identified_pageviews = $coverage_row ? (int) $coverage_row->identified_pageviews : 0;
	$report['coverage']   = array(
		'total_pageviews'                   => $total_pageviews,
		'identified_pageviews'              => $identified_pageviews,
		'anonymous_pageviews'               => $coverage_row ? (int) $coverage_row->anonymous_pageviews : 0,
		'identity_coverage_rate'            => $total_pageviews > 0 ? round( $identified_pageviews / $total_pageviews, 4 ) : 0.0,
		'explicit_route_family_pageviews'   => $coverage_row ? (int) $coverage_row->explicit_route_family_pageviews : 0,
		'inferred_singular_pageviews'       => $coverage_row ? (int) $coverage_row->inferred_singular_pageviews : 0,
		'historical_unclassified_pageviews' => $coverage_row ? (int) $coverage_row->historical_unclassified_pageviews : 0,
		'loaded_identified_pageviews'       => count( $events ),
		'truncated'                         => $truncated,
		'definition'                        => 'Transitions require first-party visitor identity, so NULL/empty visitor rows contribute only to coverage. Explicit route families are write-time classifications; older post-backed rows are inferred as singular; older rows with neither signal remain unclassified. When truncated=true, rankings cover only the most recent max_pageviews identified rows and are not complete-window totals.',
	);
	$report['bounds']     = array(
		'days'             => $days,
		'blog_id'          => $blog_id,
		'session_gap_mins' => $gap_mins,
		'sequence_length'  => $sequence_length,
		'cohort'           => $cohort,
		'limit'            => $limit,
		'max_pageviews'    => $max_pageviews,
	);
	$report['period']     = array(
		'since'         => $since,
		'ranking_since' => gmdate( 'Y-m-d H:i:s', $ranking_since_ts ),
		'until'         => $until,
		'as_of'         => $until,
	);
	$report['note']       = 'Deterministic and bot-filtered by the existing pageview writer. Sessions split only when the gap is greater than the configured timeout, exactly matching get-conversion-map. One gap of pre-window context prevents boundary sessions from being misclassified; sessions beginning before the window are excluded. First-time means the session contains the visitor\'s first observed pageview in available history. Loops and one-page direct terminals are retained.';

	return $report;
}

/**
 * Read first-observed pageview timestamps for a bounded visitor set.
 *
 * @param array  $visitor_ids Visitor IDs.
 * @param string $table       Trusted event table name.
 * @param string $event_type  Pageview event type.
 * @return array<string,int> Acquisition timestamps by visitor ID.
 */
function extrachill_analytics_route_transition_acquisitions( $visitor_ids, $table, $event_type ) {
	global $wpdb;

	$acquisitions = array();
	foreach ( array_chunk( $visitor_ids, 500 ) as $chunk ) {
		$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
		$values       = array_merge( array( $event_type ), $chunk );
		$sql          = "SELECT visitor_id, UNIX_TIMESTAMP(MIN(created_at)) AS acquisition_ts
			FROM {$table}
			WHERE event_type = %s AND visitor_id IN ({$placeholders})
			GROUP BY visitor_id";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $rows as $row ) {
			$acquisitions[ (string) $row->visitor_id ] = (int) $row->acquisition_ts;
		}
	}

	return $acquisitions;
}

/**
 * Build a transition report from normalized, visitor-ordered pageviews.
 *
 * @param array $events  Normalized events.
 * @param array $options Report options.
 * @return array Aggregated report.
 */
function extrachill_analytics_build_route_transition_report( $events, $options ) {
	$since_ts        = (int) $options['since_ts'];
	$until_ts        = (int) $options['until_ts'];
	$gap_secs        = (int) $options['gap_secs'];
	$sequence_length = (int) $options['sequence_length'];
	$cohort_filter   = (string) $options['cohort'];
	$limit           = (int) $options['limit'];

	$transitions = array();
	$sequences   = array();
	$entries     = array();
	$terminals   = array();
	$counts      = array(
		'sessions'                  => 0,
		'first_time_sessions'       => 0,
		'returning_sessions'        => 0,
		'direct_terminal_sessions'  => 0,
		'transitions'               => 0,
		'same_surface_transitions'  => 0,
		'cross_surface_transitions' => 0,
		'sequence_windows'          => 0,
	);

	$current_visitor = null;
	$visitor_events  = array();
	$flush_visitor   = static function ( $buffer ) use ( &$transitions, &$sequences, &$entries, &$terminals, &$counts, $since_ts, $until_ts, $gap_secs, $sequence_length, $cohort_filter ) {
		if ( empty( $buffer ) ) {
			return;
		}

		$sessions = array();
		$session  = array();
		$previous = null;
		foreach ( $buffer as $event ) {
			if ( null !== $previous && ( $event['ts'] - $previous ) > $gap_secs ) {
				$sessions[] = $session;
				$session    = array();
			}
			$session[] = $event;
			$previous  = $event['ts'];
		}
		if ( ! empty( $session ) ) {
			$sessions[] = $session;
		}

		foreach ( $sessions as $candidate ) {
			$first = $candidate[0];
			$last  = $candidate[ count( $candidate ) - 1 ];
			if ( $first['ts'] < $since_ts || $first['ts'] > $until_ts ) {
				continue;
			}

			$is_first_time = $first['acquisition_ts'] >= $first['ts'] && $first['acquisition_ts'] <= $last['ts'];
			$cohort        = $is_first_time ? 'first_time' : 'returning';
			if ( 'all' !== $cohort_filter && $cohort_filter !== $cohort ) {
				continue;
			}

			++$counts['sessions'];
			++$counts[ $is_first_time ? 'first_time_sessions' : 'returning_sessions' ];
			$nodes      = array_map( 'extrachill_analytics_route_transition_node', $candidate );
			$node_count = count( $nodes );
			extrachill_analytics_route_transition_increment_node( $entries, $nodes[0] );
			extrachill_analytics_route_transition_increment_node( $terminals, $nodes[ $node_count - 1 ] );

			if ( 1 === $node_count ) {
				++$counts['direct_terminal_sessions'];
			}

			for ( $i = 0; $i < $node_count - 1; $i++ ) {
				$from = $nodes[ $i ];
				$to   = $nodes[ $i + 1 ];
				$key  = extrachill_analytics_route_transition_node_key( $from ) . '>' . extrachill_analytics_route_transition_node_key( $to );
				if ( ! isset( $transitions[ $key ] ) ) {
					$transitions[ $key ] = array(
						'from'         => $from,
						'to'           => $to,
						'count'        => 0,
						'same_surface' => $from['blog_id'] === $to['blog_id'],
					);
				}
				++$transitions[ $key ]['count'];
				++$counts['transitions'];
				++$counts[ $from['blog_id'] === $to['blog_id'] ? 'same_surface_transitions' : 'cross_surface_transitions' ];
			}

			for ( $i = 0; $i <= $node_count - $sequence_length; $i++ ) {
				$path = array_slice( $nodes, $i, $sequence_length );
				$key  = implode( '>', array_map( 'extrachill_analytics_route_transition_node_key', $path ) );
				if ( ! isset( $sequences[ $key ] ) ) {
					$sequences[ $key ] = array(
						'path'  => $path,
						'count' => 0,
					);
				}
				++$sequences[ $key ]['count'];
				++$counts['sequence_windows'];
			}
		}
	};

	foreach ( $events as $event ) {
		if ( '' === (string) $event['visitor_id'] ) {
			continue;
		}
		if ( $event['visitor_id'] !== $current_visitor ) {
			if ( null !== $current_visitor ) {
				$flush_visitor( $visitor_events );
			}
			$current_visitor = $event['visitor_id'];
			$visitor_events  = array();
		}
		$visitor_events[] = $event;
	}
	if ( null !== $current_visitor ) {
		$flush_visitor( $visitor_events );
	}

	return array(
		'counts'      => $counts,
		'transitions' => extrachill_analytics_route_transition_rank( $transitions, $limit ),
		'sequences'   => extrachill_analytics_route_transition_rank( $sequences, $limit ),
		'entries'     => extrachill_analytics_route_transition_rank( $entries, $limit ),
		'terminals'   => extrachill_analytics_route_transition_rank( $terminals, $limit ),
		'definitions' => array(
			'transition' => 'Every adjacent A -> B pair in an included inactivity-gap session. Repeated routes and loops count.',
			'sequence'   => 'Every sliding window containing exactly sequence_length route observations in an included session.',
			'entry'      => 'First route of an included session.',
			'terminal'   => 'Last route of an included session. A one-page session is both an entry and a direct terminal.',
			'cohort'     => 'First-time means the session contains the visitor\'s first observed pageview in available history; later sessions are returning.',
		),
	);
}

/**
 * Normalize an event into its route node.
 *
 * @param array $event Event.
 * @return array{blog_id:int,route_family:string} Route node.
 */
function extrachill_analytics_route_transition_node( $event ) {
	return array(
		'blog_id'      => (int) $event['blog_id'],
		'route_family' => (string) $event['route_family'],
	);
}

/**
 * Return a deterministic node key.
 *
 * @param array $node Route node.
 * @return string Node key.
 */
function extrachill_analytics_route_transition_node_key( $node ) {
	return (int) $node['blog_id'] . ':' . (string) $node['route_family'];
}

/**
 * Increment a node count bucket.
 *
 * @param array $buckets Buckets.
 * @param array $node    Route node.
 */
function extrachill_analytics_route_transition_increment_node( &$buckets, $node ) {
	$key = extrachill_analytics_route_transition_node_key( $node );
	if ( ! isset( $buckets[ $key ] ) ) {
		$buckets[ $key ] = array(
			'route' => $node,
			'count' => 0,
		);
	}
	++$buckets[ $key ]['count'];
}

/**
 * Rank count buckets deterministically and enforce the output limit.
 *
 * @param array $buckets Buckets keyed by their deterministic identity.
 * @param int   $limit   Output limit.
 * @return array Ranked rows.
 */
function extrachill_analytics_route_transition_rank( $buckets, $limit ) {
	$rows = array_values( $buckets );
	usort(
		$rows,
		static function ( $a, $b ) {
			if ( $a['count'] !== $b['count'] ) {
				return $b['count'] <=> $a['count'];
			}
			return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
		}
	);
	return array_slice( $rows, 0, $limit );
}
