<?php
/**
 * Bounded geographic bridge holdout report.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the one approved contextual-bridge experiment report.
 */
function extrachill_analytics_register_geo_bridge_experiment_ability() {
	wp_register_ability(
		'extrachill/get-geo-bridge-experiment',
		array(
			'label'               => __( 'Get Geographic Bridge Experiment', 'extrachill-analytics' ),
			'description'         => __( 'Report the bounded geo-bridge-holdout assignment, viewport exposure, bridge click, route transition, and lifecycle outcome experiment.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'             => array(
						'type'        => 'integer',
						'description' => __( 'UTC assignment and attribution lookback, clamped to 1-90 days. Default 28.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'session_gap_mins' => array(
						'type'        => 'integer',
						'description' => __( 'Pageview inactivity gap separating same and later sessions, clamped to 1-120 minutes. Default 30.', 'extrachill-analytics' ),
						'default'     => 30,
					),
					'max_events'       => array(
						'type'        => 'integer',
						'description' => __( 'Maximum relevant event rows inspected, clamped to 100-100000. Default 50000.', 'extrachill-analytics' ),
						'default'     => 50000,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Control and treatment results with explicit attribution, coverage, privacy, and query bounds.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_geo_bridge_experiment',
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
 * Execute the bounded geographic bridge experiment report.
 *
 * @param array $input Ability input.
 * @return array Machine-readable report.
 */
function extrachill_analytics_ability_get_geo_bridge_experiment( $input ) {
	$days         = min( 90, max( 1, (int) ( $input['days'] ?? 28 ) ) );
	$gap_mins     = min( 120, max( 1, (int) ( $input['session_gap_mins'] ?? 30 ) ) );
	$max_events   = min( 100000, max( 100, (int) ( $input['max_events'] ?? 50000 ) ) );
	$as_of        = gmdate( 'Y-m-d H:i:s' );
	$since        = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	$event_types  = extrachill_analytics_geo_bridge_event_types();
	$query        = extrachill_analytics_geo_bridge_read_events( $event_types, $since, $as_of, $max_events );
	$instrumented = function_exists( 'extrachill_get_analytics_event_types' )
		? extrachill_get_analytics_event_types()
		: array_values( array_unique( array_map( static fn( $row ) => (string) $row->event_type, $query['rows'] ) ) );

	return extrachill_analytics_build_geo_bridge_experiment_report(
		$query['rows'],
		array(
			'since'              => $since,
			'as_of'              => $as_of,
			'days'               => $days,
			'session_gap_mins'   => $gap_mins,
			'max_events'         => $max_events,
			'truncated'          => $query['truncated'],
			'instrumented_types' => $instrumented,
		)
	);
}

/**
 * Return the exact event set admitted by this report.
 *
 * @return string[] Canonical event names.
 */
function extrachill_analytics_geo_bridge_event_types() {
	return array_values(
		array_unique(
			array_merge(
				array(
					EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT,
					EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE,
					EC_ANALYTICS_EVENT_BRIDGE_CLICK,
					EC_ANALYTICS_EVENT_PAGEVIEW,
				),
				extrachill_analytics_conversion_outcome_types()
			)
		)
	);
}

/**
 * Read relevant rows with a stable ascending time/ID keyset and hard ceiling.
 *
 * @param string[] $event_types Canonical event names.
 * @param string   $since       Inclusive UTC lower bound.
 * @param string   $as_of       Inclusive UTC upper bound.
 * @param int      $max_events  Hard row ceiling.
 * @return array{rows:array,truncated:bool}
 */
function extrachill_analytics_geo_bridge_read_events( $event_types, $since, $as_of, $max_events ) {
	global $wpdb;

	$table        = extrachill_analytics_events_table();
	$page_size    = 500;
	$rows         = array();
	$cursor_time  = null;
	$cursor_id    = 0;
	$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );

	do {
		$remaining = $max_events + 1 - count( $rows );
		if ( $remaining <= 0 ) {
			break;
		}
		$limit  = min( $page_size, $remaining );
		$where  = array(
			"event_type IN ({$placeholders})",
			'created_at >= %s',
			'created_at <= %s',
		);
		$values = array_merge( array_map( 'sanitize_key', $event_types ), array( $since, $as_of ) );
		if ( null !== $cursor_time ) {
			$where[]  = '(created_at > %s OR (created_at = %s AND id > %d))';
			$values[] = $cursor_time;
			$values[] = $cursor_time;
			$values[] = $cursor_id;
		}
		$values[]     = $limit;
		$where_clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- Bounded report; identifiers are code-defined and values are prepared.
		$sql  = "SELECT id, event_type, event_data, source_url, blog_id, user_id, visitor_id, created_at, UNIX_TIMESTAMP(created_at) AS ts
			FROM {$table}
			WHERE {$where_clause}
			ORDER BY created_at ASC, id ASC
			LIMIT %d";
		$page = (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		if ( empty( $page ) ) {
			break;
		}
		$rows        = array_merge( $rows, $page );
		$page_count  = count( $page );
		$last        = end( $page );
		$cursor_time = (string) $last->created_at;
		$cursor_id   = (int) $last->id;
		$row_count   = count( $rows );
	} while ( $page_count === $limit && $row_count <= $max_events );

	$truncated = count( $rows ) > $max_events;
	if ( $truncated ) {
		array_pop( $rows );
	}

	return array(
		'rows'      => $rows,
		'truncated' => $truncated,
	);
}

/**
 * Normalize a stored event row for deterministic aggregation.
 *
 * @param object|array $row Stored row.
 * @return array Normalized event.
 */
function extrachill_analytics_geo_bridge_normalize_event( $row ) {
	$row  = (array) $row;
	$data = $row['event_data'] ?? array();
	if ( is_string( $data ) ) {
		$data = json_decode( $data, true );
	}
	$data = is_array( $data ) ? $data : array();
	$ts   = isset( $row['ts'] ) ? (int) $row['ts'] : strtotime( (string) ( $row['created_at'] ?? '' ) . ' UTC' );

	return array(
		'id'         => (int) ( $row['id'] ?? 0 ),
		'event_type' => (string) ( $row['event_type'] ?? '' ),
		'event_data' => $data,
		'blog_id'    => (int) ( $row['blog_id'] ?? 0 ),
		'user_id'    => (int) ( $row['user_id'] ?? 0 ),
		'visitor_id' => trim( (string) ( $row['visitor_id'] ?? '' ) ),
		'ts'         => max( 0, (int) $ts ),
	);
}

/**
 * Resolve an event's strongest user identity, including lifecycle payloads.
 *
 * @param array $event Normalized event.
 * @return int Positive user ID or zero.
 */
function extrachill_analytics_geo_bridge_event_user_id( $event ) {
	return (int) ( $event['event_data']['user_id'] ?? $event['user_id'] ?? 0 );
}

/**
 * Resolve only ambiguity-safe identities.
 *
 * @param array $event           Normalized event.
 * @param array $visitor_to_user One-to-one visitor/user bridges.
 * @return string Person key, or empty when unidentified.
 */
function extrachill_analytics_geo_bridge_person_key( $event, $visitor_to_user ) {
	$user_id = extrachill_analytics_geo_bridge_event_user_id( $event );
	if ( $user_id > 0 ) {
		return 'user:' . $user_id;
	}
	$visitor_id = (string) $event['visitor_id'];
	if ( '' === $visitor_id ) {
		return '';
	}
	if ( isset( $visitor_to_user[ $visitor_id ] ) ) {
		return 'user:' . (int) $visitor_to_user[ $visitor_id ];
	}
	return 'visitor:' . $visitor_id;
}

/**
 * Check strict event ordering using created_at then ID.
 *
 * @param array $event  Candidate event.
 * @param array $anchor Earlier anchor.
 * @return bool Whether event is strictly later.
 */
function extrachill_analytics_geo_bridge_is_after( $event, $anchor ) {
	return (int) $event['ts'] > (int) $anchor['ts']
		|| ( (int) $event['ts'] === (int) $anchor['ts'] && (int) $event['id'] > (int) $anchor['id'] );
}

/**
 * Whether an experiment row matches the one approved contract.
 *
 * @param array $event Normalized event.
 * @return bool Whether dimensions are exact.
 */
function extrachill_analytics_geo_bridge_contract_matches( $event ) {
	$data = $event['event_data'];
	return EC_ANALYTICS_EXPERIMENT_GEO_BRIDGE_HOLDOUT === (string) ( $data['experiment_key'] ?? '' )
		&& EC_ANALYTICS_EXPERIMENT_SURFACE_SINGLE_POST_BRIDGE === (string) ( $data['surface'] ?? '' )
		&& in_array( (string) ( $data['variant'] ?? '' ), EC_ANALYTICS_EXPERIMENT_VARIANTS, true );
}

/**
 * Create an empty variant accumulator.
 *
 * @param string[] $outcome_types Lifecycle outcomes.
 * @return array Accumulator.
 */
function extrachill_analytics_geo_bridge_variant_bucket( $outcome_types ) {
	$outcomes = array();
	foreach ( $outcome_types as $type ) {
		$outcomes[ $type ] = array(
			'same_session'  => 0,
			'later_session' => 0,
		);
	}
	return array(
		'assigned_people'                => 0,
		'assignment_events'              => 0,
		'exposed_people'                 => 0,
		'exposure_events'                => 0,
		'bridge_click_events_assignment' => 0,
		'bridge_clickers_assignment'     => array(),
		'bridge_click_events_exposure'   => 0,
		'bridge_clickers_exposure'       => array(),
		'transitions_assignment'         => array(),
		'transitions_exposure'           => array(),
		'outcomes_assignment'            => $outcomes,
		'outcomes_exposure'              => $outcomes,
	);
}

/**
 * Increment a deterministic destination transition bucket.
 *
 * @param array  $buckets Transition buckets by reference.
 * @param array  $event   Destination pageview.
 * @param string $stage   same_session or later_session.
 */
function extrachill_analytics_geo_bridge_add_transition( &$buckets, $event, $stage ) {
	$route = sanitize_key( (string) ( $event['event_data']['route_family'] ?? '' ) );
	if ( '' === $route ) {
		$route = ! empty( $event['event_data']['post_id'] ) ? 'singular' : 'unclassified';
	}
	$key = (int) $event['blog_id'] . ':' . $route . ':' . $stage;
	if ( ! isset( $buckets[ $key ] ) ) {
		$buckets[ $key ] = array(
			'destination_blog_id' => (int) $event['blog_id'],
			'route_family'        => $route,
			'session_stage'       => $stage,
			'people'              => 0,
		);
	}
	++$buckets[ $key ]['people'];
}

/**
 * Sort transition rows deterministically.
 *
 * @param array $buckets Transition buckets.
 * @return array Ranked rows.
 */
function extrachill_analytics_geo_bridge_transition_rows( $buckets ) {
	$rows = array_values( $buckets );
	usort(
		$rows,
		static function ( $a, $b ) {
			if ( $a['people'] !== $b['people'] ) {
				return $b['people'] <=> $a['people'];
			}
			return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
		}
	);
	return $rows;
}

/**
 * Return whether a canonical signal has ever existed in storage.
 *
 * @param string $event_type   Canonical event name.
 * @param array  $instrumented Distinct stored event names.
 * @return bool Whether instrumentation is observable.
 */
function extrachill_analytics_geo_bridge_is_instrumented( $event_type, $instrumented ) {
	return in_array( $event_type, $instrumented, true );
}

/**
 * Project a variant bucket without turning absent signals into measured zero.
 *
 * @param string $variant      Variant name.
 * @param array  $bucket       Raw accumulator.
 * @param array  $coverage     Coverage state.
 * @param array  $instrumented Distinct stored event names.
 * @param bool   $has_cohort   Whether any matching assignment exists.
 * @return array Machine-readable variant row.
 */
function extrachill_analytics_geo_bridge_variant_row( $variant, $bucket, $coverage, $instrumented, $has_cohort ) {
	$assignment_ready = $has_cohort && extrachill_analytics_geo_bridge_is_instrumented( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, $instrumented );
	$exposure_ready   = $assignment_ready && extrachill_analytics_geo_bridge_is_instrumented( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $instrumented );
	$click_ready      = $assignment_ready && extrachill_analytics_geo_bridge_is_instrumented( EC_ANALYTICS_EVENT_BRIDGE_CLICK, $instrumented );
	$route_ready      = $assignment_ready && extrachill_analytics_geo_bridge_is_instrumented( EC_ANALYTICS_EVENT_PAGEVIEW, $instrumented );
	$assigned         = (int) $bucket['assigned_people'];
	$exposed          = (int) $bucket['exposed_people'];

	$outcomes = array();
	foreach ( extrachill_analytics_conversion_outcome_types() as $type ) {
		$ready             = $assignment_ready && extrachill_analytics_geo_bridge_is_instrumented( $type, $instrumented );
		$outcomes[ $type ] = array(
			'coverage_status'  => $ready ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : 'not_instrumented',
			'after_assignment' => $ready ? $bucket['outcomes_assignment'][ $type ] : null,
			'after_exposure'   => $ready && $exposure_ready ? $bucket['outcomes_exposure'][ $type ] : null,
		);
	}

	return array(
		'variant'            => $variant,
		'assignment'         => array(
			'people'          => $assignment_ready ? $assigned : null,
			'stored_events'   => $assignment_ready ? (int) $bucket['assignment_events'] : null,
			'coverage_status' => $assignment_ready ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : 'not_instrumented',
		),
		'exposure'           => array(
			'people'          => $exposure_ready ? $exposed : null,
			'stored_events'   => $exposure_ready ? (int) $bucket['exposure_events'] : null,
			'rate'            => $exposure_ready && $assigned > 0 ? round( $exposed / $assigned, 4 ) : null,
			'coverage_status' => $exposure_ready ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : 'not_instrumented',
		),
		'network_engagement' => array(
			'any_bridge_click_after_assignment' => array(
				'events'                => $click_ready ? (int) $bucket['bridge_click_events_assignment'] : null,
				'people'                => $click_ready ? count( $bucket['bridge_clickers_assignment'] ) : null,
				'events_per_assignment' => $click_ready && $assigned > 0 ? round( $bucket['bridge_click_events_assignment'] / $assigned, 4 ) : null,
				'analysis_lens'         => 'intent_to_treat',
			),
			'any_bridge_click_after_exposure'   => array(
				'events'              => $click_ready && $exposure_ready ? (int) $bucket['bridge_click_events_exposure'] : null,
				'people'              => $click_ready && $exposure_ready ? count( $bucket['bridge_clickers_exposure'] ) : null,
				'events_per_exposure' => $click_ready && $exposure_ready && $exposed > 0 ? round( $bucket['bridge_click_events_exposure'] / $exposed, 4 ) : null,
				'analysis_lens'       => 'descriptive_exposure_conditioned',
			),
			'card_specific_click_instrumented'  => false,
			'coverage_status'                   => $click_ready ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : 'not_instrumented',
			'caveat'                            => 'The assignment lens is intent-to-treat. The exposure lens is descriptive and exposure-conditioned, so it may be selection-biased. The bridge_click contract identifies no rendered card or taxonomy; both include unrelated artist or festival cards and are not geographic-card click attribution.',
		),
		'route_transitions'  => array(
			'after_assignment' => $route_ready ? extrachill_analytics_geo_bridge_transition_rows( $bucket['transitions_assignment'] ) : null,
			'after_exposure'   => $route_ready && $exposure_ready ? extrachill_analytics_geo_bridge_transition_rows( $bucket['transitions_exposure'] ) : null,
			'coverage_status'  => $route_ready ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : 'not_instrumented',
		),
		'outcomes'           => $outcomes,
	);
}

/**
 * Aggregate normalized rows into the one approved experiment report.
 *
 * @param array $rows    Stored rows.
 * @param array $options Window, bounds, and instrumentation state.
 * @return array Machine-readable report.
 */
function extrachill_analytics_build_geo_bridge_experiment_report( $rows, $options ) {
	$outcome_types = extrachill_analytics_conversion_outcome_types();
	$events        = array_map( 'extrachill_analytics_geo_bridge_normalize_event', $rows );
	usort(
		$events,
		static function ( $a, $b ) {
			return $a['ts'] === $b['ts'] ? $a['id'] <=> $b['id'] : $a['ts'] <=> $b['ts'];
		}
	);

	$visitor_users = array();
	foreach ( $events as $event ) {
		if ( ! empty( $event['event_data']['is_bot'] ) ) {
			continue;
		}
		$user_id = extrachill_analytics_geo_bridge_event_user_id( $event );
		if ( $user_id > 0 && '' !== $event['visitor_id'] ) {
			$visitor_users[ $event['visitor_id'] ][ $user_id ] = true;
		}
	}
	$visitor_to_user = array();
	$ambiguous       = array();
	foreach ( $visitor_users as $visitor_id => $users ) {
		if ( 1 === count( $users ) ) {
			$visitor_to_user[ $visitor_id ] = (int) array_key_first( $users );
		} else {
			$ambiguous[ $visitor_id ] = true;
		}
	}
	$cohort_people = array();
	foreach ( $events as $event ) {
		if (
			EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT !== $event['event_type']
			|| ! empty( $event['event_data']['is_bot'] )
			|| ! extrachill_analytics_geo_bridge_contract_matches( $event )
		) {
			continue;
		}
		$person = extrachill_analytics_geo_bridge_person_key( $event, $visitor_to_user );
		if ( '' !== $person ) {
			$cohort_people[ $person ] = true;
		}
	}

	$buckets = array();
	foreach ( EC_ANALYTICS_EXPERIMENT_VARIANTS as $variant ) {
		$buckets[ $variant ] = extrachill_analytics_geo_bridge_variant_bucket( $outcome_types );
	}
	$assignments  = array();
	$exposures    = array();
	$route_state  = array(
		'assignment' => array(),
		'exposure'   => array(),
	);
	$outcome_seen = array(
		'assignment' => array(),
		'exposure'   => array(),
	);
	$coverage     = array(
		'loaded_rows'                   => count( $events ),
		'truncated'                     => ! empty( $options['truncated'] ),
		'bot_rows_excluded'             => 0,
		'unidentified_rows'             => array(),
		'contract_rows_rejected'        => 0,
		'duplicate_assignment_events'   => 0,
		'conflicting_assignment_events' => 0,
		'duplicate_exposure_events'     => 0,
		'unattributed_exposure_events'  => 0,
		'pre_assignment_outcome_events' => 0,
		'unattributed_outcome_events'   => 0,
		'ambiguous_visitor_ids'         => count( $ambiguous ),
	);

	foreach ( $events as $event ) {
		$type = $event['event_type'];
		if ( ! empty( $event['event_data']['is_bot'] ) ) {
			++$coverage['bot_rows_excluded'];
			continue;
		}
		$person = extrachill_analytics_geo_bridge_person_key( $event, $visitor_to_user );
		if ( '' === $person ) {
			$coverage['unidentified_rows'][ $type ] = (int) ( $coverage['unidentified_rows'][ $type ] ?? 0 ) + 1;
			continue;
		}

		if ( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT === $type ) {
			if ( ! extrachill_analytics_geo_bridge_contract_matches( $event ) ) {
				++$coverage['contract_rows_rejected'];
				continue;
			}
			$variant = (string) $event['event_data']['variant'];
			++$buckets[ $variant ]['assignment_events'];
			if ( isset( $assignments[ $person ] ) ) {
				++$coverage['duplicate_assignment_events'];
				if ( $variant !== $assignments[ $person ]['variant'] ) {
					++$coverage['conflicting_assignment_events'];
				}
				continue;
			}
			$assignments[ $person ]               = array(
				'variant'         => $variant,
				'ts'              => $event['ts'],
				'id'              => $event['id'],
				'blog_id'         => $event['blog_id'],
				'session_last_ts' => $event['ts'],
				'session_stage'   => 'same_session',
			);
			$route_state['assignment'][ $person ] = false;
			++$buckets[ $variant ]['assigned_people'];
			continue;
		}

		if ( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE === $type ) {
			if ( ! extrachill_analytics_geo_bridge_contract_matches( $event ) ) {
				++$coverage['contract_rows_rejected'];
				continue;
			}
			$variant = (string) $event['event_data']['variant'];
			if ( ! isset( $assignments[ $person ] ) || $variant !== $assignments[ $person ]['variant'] || ! extrachill_analytics_geo_bridge_is_after( $event, $assignments[ $person ] ) ) {
				++$coverage['unattributed_exposure_events'];
				continue;
			}
			++$buckets[ $variant ]['exposure_events'];
			if ( isset( $exposures[ $person ] ) ) {
				++$coverage['duplicate_exposure_events'];
				continue;
			}
			$exposures[ $person ]               = array(
				'variant'         => $variant,
				'ts'              => $event['ts'],
				'id'              => $event['id'],
				'blog_id'         => $event['blog_id'],
				'session_last_ts' => $event['ts'],
				'session_stage'   => 'same_session',
			);
			$route_state['exposure'][ $person ] = false;
			++$buckets[ $variant ]['exposed_people'];
			continue;
		}

		if ( EC_ANALYTICS_EVENT_PAGEVIEW === $type ) {
			foreach ( array( 'assignment', 'exposure' ) as $lens ) {
				$anchors = 'assignment' === $lens ? $assignments : $exposures;
				if ( ! isset( $anchors[ $person ] ) || ! extrachill_analytics_geo_bridge_is_after( $event, $anchors[ $person ] ) ) {
					continue;
				}
				$anchor = &$anchors[ $person ];
				if ( $event['ts'] - $anchor['session_last_ts'] > (int) $options['session_gap_mins'] * MINUTE_IN_SECONDS ) {
					$anchor['session_stage'] = 'later_session';
				}
				$anchor['session_last_ts'] = $event['ts'];
				if ( false === $route_state[ $lens ][ $person ] && $event['blog_id'] !== $anchor['blog_id'] ) {
					$variant = $anchor['variant'];
					$key     = 'assignment' === $lens ? 'transitions_assignment' : 'transitions_exposure';
					extrachill_analytics_geo_bridge_add_transition( $buckets[ $variant ][ $key ], $event, $anchor['session_stage'] );
					$route_state[ $lens ][ $person ] = true;
				}
				if ( 'assignment' === $lens ) {
					$assignments[ $person ] = $anchor;
				} else {
					$exposures[ $person ] = $anchor;
				}
				unset( $anchor );
			}
			continue;
		}

		if ( EC_ANALYTICS_EVENT_BRIDGE_CLICK === $type ) {
			if ( isset( $assignments[ $person ] ) && extrachill_analytics_geo_bridge_is_after( $event, $assignments[ $person ] ) ) {
				$variant = $assignments[ $person ]['variant'];
				++$buckets[ $variant ]['bridge_click_events_assignment'];
				$buckets[ $variant ]['bridge_clickers_assignment'][ $person ] = true;
			}
			if ( isset( $exposures[ $person ] ) && extrachill_analytics_geo_bridge_is_after( $event, $exposures[ $person ] ) ) {
				$variant = $exposures[ $person ]['variant'];
				++$buckets[ $variant ]['bridge_click_events_exposure'];
				$buckets[ $variant ]['bridge_clickers_exposure'][ $person ] = true;
			}
			continue;
		}

		if ( in_array( $type, $outcome_types, true ) ) {
			if ( EC_ANALYTICS_EVENT_NEWSLETTER_SIGNUP === $type && 'registration' === (string) ( $event['event_data']['context'] ?? '' ) ) {
				continue;
			}
			if ( ! isset( $assignments[ $person ] ) || ! extrachill_analytics_geo_bridge_is_after( $event, $assignments[ $person ] ) ) {
				if ( isset( $cohort_people[ $person ] ) ) {
					++$coverage['pre_assignment_outcome_events'];
				} else {
					++$coverage['unattributed_outcome_events'];
				}
				continue;
			}
			foreach ( array( 'assignment', 'exposure' ) as $lens ) {
				$anchor = 'assignment' === $lens ? ( $assignments[ $person ] ?? null ) : ( $exposures[ $person ] ?? null );
				if ( null === $anchor || ! extrachill_analytics_geo_bridge_is_after( $event, $anchor ) || isset( $outcome_seen[ $lens ][ $type ][ $person ] ) ) {
					continue;
				}
				$stage   = $event['ts'] - $anchor['session_last_ts'] > (int) $options['session_gap_mins'] * MINUTE_IN_SECONDS ? 'later_session' : $anchor['session_stage'];
				$variant = $anchor['variant'];
				$key     = 'assignment' === $lens ? 'outcomes_assignment' : 'outcomes_exposure';
				++$buckets[ $variant ][ $key ][ $type ][ $stage ];
				$outcome_seen[ $lens ][ $type ][ $person ] = true;
			}
		}
	}

	ksort( $coverage['unidentified_rows'] );
	$has_cohort   = ! empty( $assignments );
	$instrumented = array_values( array_unique( array_map( 'strval', $options['instrumented_types'] ?? array() ) ) );
	$state        = $has_cohort
		? ( $coverage['truncated'] ? 'truncated' : 'measured' )
		: ( extrachill_analytics_geo_bridge_is_instrumented( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, $instrumented ) ? 'no_production_data' : 'not_instrumented' );
	$variants     = array();
	foreach ( EC_ANALYTICS_EXPERIMENT_VARIANTS as $variant ) {
		$variants[] = extrachill_analytics_geo_bridge_variant_row( $variant, $buckets[ $variant ], $coverage, $instrumented, $has_cohort );
	}

	return array(
		'experiment_key' => EC_ANALYTICS_EXPERIMENT_GEO_BRIDGE_HOLDOUT,
		'surface'        => EC_ANALYTICS_EXPERIMENT_SURFACE_SINGLE_POST_BRIDGE,
		'state'          => $state,
		'variants'       => $variants,
		'coverage'       => array_merge(
			$coverage,
			array(
				'identified_assignment_people' => count( $assignments ),
				'identified_exposure_people'   => count( $exposures ),
				'unambiguous_identity_bridges' => count( $visitor_to_user ),
				'gpc_dnt'                      => 'Privacy-excluded requests emit no assignment or exposure and may omit visitor identity from other rows; their number is intentionally not observable from stored analytics.',
				'unidentified'                 => 'Rows without a usable visitor_id or user_id are retained only in coverage and never attributed to a variant.',
				'no_data_semantics'            => 'Counts are null, not zero, when their event type has never been observed or no matching assignment cohort exists.',
			)
		),
		'contract'       => array(
			'assignment'        => 'One person enters the denominator at their first valid experiment_assignment ordered by created_at then ID. Duplicate rows do not add people; a conflicting later variant is disclosed and does not reassign the person.',
			'exposure'          => 'One person is exposed only after a matching-variant experiment_exposure strictly after assignment. Exposure is never inferred from assignment, bridge clicks, impressions, or pageviews.',
			'any_bridge_clicks' => 'Stored non-bot bridge_click rows are broad network-engagement outcomes. The assignment lens is intent-to-treat; the exposure lens is descriptive and exposure-conditioned. The contract cannot identify the geographic treatment card, so unrelated artist/festival card clicks remain included and are never labeled card-specific attribution. Event counts are not deduplicated, synthesized, or clamped; people counts are separate.',
			'route_transition'  => 'The first identified cross-blog pageview strictly after each anchor is a transition. Its destination is blog_id plus route_family; same/later session follows the configured pageview inactivity gap.',
			'outcomes'          => 'Only newsletter_signup, user_registration, onboarding_completed, and artist_profile_first_publish are eligible. Automatic registration newsletter rows are excluded. Each person/outcome counts once per anchor; pre-assignment rows never attribute.',
			'identity'          => 'Bot-stamped rows are excluded before identity observation. For remaining rows identity is payload user_id, then stored user_id, then visitor_id. A visitor stitches to a user only when the bounded window observes exactly one user for that visitor; ambiguous visitors remain unmerged.',
		),
		'window'         => array(
			'since'            => (string) $options['since'],
			'as_of'            => (string) $options['as_of'],
			'days'             => (int) $options['days'],
			'session_gap_mins' => (int) $options['session_gap_mins'],
		),
		'query'          => array(
			'event_types'   => extrachill_analytics_geo_bridge_event_types(),
			'order'         => 'created_at ASC, id ASC',
			'cursor'        => '(created_at, id) > (cursor_created_at, cursor_id)',
			'page_size'     => 500,
			'max_events'    => (int) $options['max_events'],
			'bounded_state' => 'At most max_events normalized rows plus one truncation sentinel are retained; all person and outcome maps derive only from that bounded set.',
		),
	);
}
