<?php
/**
 * Shared bounded experiment-reporting primitives.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/** Maximum dimensions accepted by the generic experiment reader. */
const EC_ANALYTICS_EXPERIMENT_MAX_VARIANTS = 8;
const EC_ANALYTICS_EXPERIMENT_MAX_OUTCOMES = 10;

/** Derived outcome: a pageview on a different network site than the anchor's site. */
const EC_ANALYTICS_EXPERIMENT_OUTCOME_CROSS_SITE_PAGEVIEW = 'cross_site_pageview';

/**
 * Validate a bounded experiment identifier.
 *
 * @param mixed $value Candidate value.
 * @return bool Whether the value is canonical and bounded.
 */
function extrachill_analytics_experiment_identifier_is_valid( $value ) {
	return is_string( $value ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $value );
}

/**
 * Return canonical event types eligible as generic experiment outcomes.
 *
 * Assignment, exposure, pageviews, security events, and delivery-only signals
 * are intentionally excluded. Adding an outcome requires a code review here.
 *
 * @return string[] Canonical outcome event names.
 */
function extrachill_analytics_experiment_outcome_types() {
	return array_values(
		array_unique(
			array_merge(
				array(
					EC_ANALYTICS_EVENT_USER_REGISTRATION,
					EC_ANALYTICS_EVENT_NEWSLETTER_SIGNUP,
					EC_ANALYTICS_EVENT_SHARE_CLICK,
					EC_ANALYTICS_EVENT_BRIDGE_CLICK,
					EC_ANALYTICS_EVENT_BRIDGE_IMPRESSION,
					EC_ANALYTICS_EVENT_OUTBOUND_CLICK,
					EC_ANALYTICS_EVENT_NETWORK_CLICK,
					EC_ANALYTICS_EVENT_REDIRECT_FIRE,
				),
				EC_ANALYTICS_TEAM_EXPERIENCE_EVENTS,
				EC_ANALYTICS_ONBOARDING_FUNNEL_EVENTS,
				EC_ANALYTICS_LOCAL_SCENE_PROMPT_EVENTS,
				EC_ANALYTICS_LOCAL_SCENE_DIGEST_EVENTS,
				EC_ANALYTICS_ATTENDANCE_FUNNEL_EVENTS,
				EC_ANALYTICS_ARTIST_FUNNEL_EVENTS,
				EC_ANALYTICS_ARTIST_ACTIVATION_FRICTION_EVENTS,
				EC_ANALYTICS_ARTIST_DISPATCH_EVENTS
			)
		)
	);
}

/**
 * Return derived outcomes computed from already-read pageviews.
 *
 * A derived outcome is not a stored event type. It needs no extra rows: the
 * report already reads pageviews for session staging.
 *
 * @return string[] Derived outcome names.
 */
function extrachill_analytics_experiment_derived_outcomes() {
	return array( EC_ANALYTICS_EXPERIMENT_OUTCOME_CROSS_SITE_PAGEVIEW );
}

/**
 * Stream relevant rows page by page with stable ascending time/ID keyset pagination.
 *
 * Memory stays at one page: each page is handed to $on_page and released.
 * Reads at most $max_events rows; one extra sentinel row reports truncation
 * and is never handed to $on_page.
 *
 * @param string[] $event_types Canonical event names.
 * @param string   $since       Inclusive UTC lower bound.
 * @param string   $as_of       Inclusive UTC upper bound.
 * @param int      $max_events  Hard row ceiling.
 * @param callable $on_page     Receives each page as an array of row objects.
 * @param int      $page_size   Rows per query.
 * @return array{rows_read:int,truncated:bool}
 */
function extrachill_analytics_experiment_each_event_page( $event_types, $since, $as_of, $max_events, $on_page, $page_size = 2000 ) {
	global $wpdb;

	$event_types = array_values( array_unique( array_map( 'sanitize_key', $event_types ) ) );
	$read        = 0;
	$truncated   = false;
	if ( empty( $event_types ) ) {
		return array(
			'rows_read' => 0,
			'truncated' => false,
		);
	}

	$table        = extrachill_analytics_events_table();
	$page_size    = max( 1, (int) $page_size );
	$cursor_time  = null;
	$cursor_id    = 0;
	$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );

	while ( true ) {
		$remaining = $max_events + 1 - $read;
		$limit     = min( $page_size, $remaining );
		$where     = array(
			"event_type IN ({$placeholders})",
			'created_at >= %s',
			'created_at <= %s',
		);
		$values    = array_merge( $event_types, array( $since, $as_of ) );
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
		$page_count = count( $page );
		if ( $read + $page_count > $max_events ) {
			$truncated = true;
			$page      = array_slice( $page, 0, $max_events - $read );
		}
		if ( ! empty( $page ) ) {
			$read       += count( $page );
			$last        = end( $page );
			$cursor_time = (string) $last->created_at;
			$cursor_id   = (int) $last->id;
			call_user_func( $on_page, $page );
		}
		if ( $truncated || $page_count < $limit ) {
			break;
		}
	}

	return array(
		'rows_read' => $read,
		'truncated' => $truncated,
	);
}

/**
 * Read relevant rows into memory with stable ascending time/ID keyset pagination.
 *
 * Retains every row, so memory grows with $max_events. Reports that must
 * cover long windows stream through
 * extrachill_analytics_experiment_each_event_page() instead.
 *
 * @param string[] $event_types Canonical event names.
 * @param string   $since       Inclusive UTC lower bound.
 * @param string   $as_of       Inclusive UTC upper bound.
 * @param int      $max_events  Hard row ceiling.
 * @return array{rows:array,truncated:bool}
 */
function extrachill_analytics_experiment_read_events( $event_types, $since, $as_of, $max_events ) {
	$rows   = array();
	$result = extrachill_analytics_experiment_each_event_page(
		$event_types,
		$since,
		$as_of,
		$max_events,
		static function ( $page ) use ( &$rows ) {
			foreach ( $page as $row ) {
				$rows[] = $row;
			}
		},
		500
	);

	return array(
		'rows'      => $rows,
		'truncated' => $result['truncated'],
	);
}

/**
 * Find the first stored assignment for one experiment key and optional version.
 *
 * The experiment report window must not start before the experiment's first
 * assignment: pre-experiment pageviews and outcomes can never attribute, yet
 * they would otherwise spend the bounded row budget before any assignment row
 * is read. A portable LIKE prefilter narrows candidates to rows mentioning the
 * key, and each candidate is validated in PHP so other keys, versions, bots,
 * and invalid contracts never move the window.
 *
 * @param string   $experiment_key     Canonical experiment key.
 * @param int|null $definition_version Requested version, or null for any.
 * @param string   $since              Inclusive UTC lower bound.
 * @param string   $as_of              Inclusive UTC upper bound.
 * @param int      $max_scan           Hard ceiling on candidate rows scanned.
 * @return string|null UTC created_at of the first matching assignment, or null.
 */
function extrachill_analytics_experiment_first_assignment_at( $experiment_key, $definition_version, $since, $as_of, $max_scan ) {
	global $wpdb;

	if ( ! extrachill_analytics_experiment_identifier_is_valid( $experiment_key ) ) {
		return null;
	}

	$table       = extrachill_analytics_events_table();
	$page_size   = 500;
	$scanned     = 0;
	$cursor_time = null;
	$cursor_id   = 0;
	$like        = '%' . $wpdb->esc_like( '"' . $experiment_key . '"' ) . '%';

	while ( $scanned < $max_scan ) {
		$limit  = min( $page_size, $max_scan - $scanned );
		$where  = array( 'event_type = %s', 'created_at >= %s', 'created_at <= %s', 'event_data LIKE %s' );
		$values = array( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, $since, $as_of, $like );
		if ( null !== $cursor_time ) {
			$where[]  = '(created_at > %s OR (created_at = %s AND id > %d))';
			$values[] = $cursor_time;
			$values[] = $cursor_time;
			$values[] = $cursor_id;
		}
		$values[]     = $limit;
		$where_clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- Bounded report; identifiers are code-defined and values are prepared.
		$sql  = "SELECT id, event_data, created_at
			FROM {$table}
			WHERE {$where_clause}
			ORDER BY created_at ASC, id ASC
			LIMIT %d";
		$page = (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		foreach ( $page as $row ) {
			$data    = json_decode( (string) $row->event_data, true );
			$data    = is_array( $data ) ? $data : array();
			$version = $data['definition_version'] ?? null;
			if (
				empty( $data['is_bot'] )
				&& (string) ( $data['experiment_key'] ?? '' ) === $experiment_key
				&& is_int( $version )
				&& $version > 0
				&& $version <= 1000000
				&& ( null === $definition_version || $version === $definition_version )
			) {
				return (string) $row->created_at;
			}
		}
		if ( count( $page ) < $limit ) {
			break;
		}
		$scanned    += count( $page );
		$last        = end( $page );
		$cursor_time = (string) $last->created_at;
		$cursor_id   = (int) $last->id;
	}

	return null;
}

/**
 * Return only requested event types that have ever existed in storage.
 *
 * @param string[] $event_types Bounded requested event names.
 * @return string[] Instrumented event names.
 */
function extrachill_analytics_experiment_instrumented_types( $event_types ) {
	global $wpdb;

	$event_types = array_values( array_unique( array_map( 'sanitize_key', $event_types ) ) );
	if ( empty( $event_types ) ) {
		return array();
	}
	$table        = extrachill_analytics_events_table();
	$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- Bounded code-defined projection with prepared values.
	$sql = "SELECT DISTINCT event_type FROM {$table} WHERE event_type IN ({$placeholders}) ORDER BY event_type ASC";
	return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $event_types ) ) );
	// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
}

/**
 * Normalize a stored event row.
 *
 * @param object|array $row Stored row.
 * @return array Normalized event.
 */
function extrachill_analytics_experiment_normalize_event( $row ) {
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
 * Resolve an event's strongest explicit user identity.
 *
 * @param array $event Normalized event.
 * @return int Positive user ID or zero.
 */
function extrachill_analytics_experiment_event_user_id( $event ) {
	return (int) ( $event['event_data']['user_id'] ?? $event['user_id'] ?? 0 );
}

/**
 * Build ambiguity-safe visitor-to-user bridges after bot filtering.
 *
 * @param array $events Normalized events.
 * @return array{map:array,ambiguous:array}
 */
function extrachill_analytics_experiment_identity_map( $events ) {
	$visitor_users = array();
	foreach ( $events as $event ) {
		if ( ! empty( $event['event_data']['is_bot'] ) ) {
			continue;
		}
		$user_id = extrachill_analytics_experiment_event_user_id( $event );
		if ( $user_id > 0 && '' !== $event['visitor_id'] ) {
			$visitor_users[ $event['visitor_id'] ][ $user_id ] = true;
		}
	}

	return extrachill_analytics_experiment_identity_resolve( $visitor_users );
}

/**
 * Resolve observed visitor-to-user pairs into an unambiguous bridge map.
 *
 * @param array<string, array<int, true>> $visitor_users Users observed per visitor.
 * @return array{map:array<string,int>,ambiguous:array<string,bool>}
 */
function extrachill_analytics_experiment_identity_resolve( $visitor_users ) {
	$map       = array();
	$ambiguous = array();
	foreach ( $visitor_users as $visitor_id => $users ) {
		if ( 1 === count( $users ) ) {
			$map[ (string) $visitor_id ] = (int) array_key_first( $users );
		} else {
			$ambiguous[ (string) $visitor_id ] = true;
		}
	}

	return array(
		'map'       => $map,
		'ambiguous' => $ambiguous,
	);
}

/**
 * Resolve an ambiguity-safe person key.
 *
 * @param array $event           Normalized event.
 * @param array $visitor_to_user One-to-one visitor/user bridges.
 * @return string Person key, or empty when unidentified.
 */
function extrachill_analytics_experiment_person_key( $event, $visitor_to_user ) {
	$user_id = extrachill_analytics_experiment_event_user_id( $event );
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
function extrachill_analytics_experiment_is_after( $event, $anchor ) {
	return (int) $event['ts'] > (int) $anchor['ts']
		|| ( (int) $event['ts'] === (int) $anchor['ts'] && (int) $event['id'] > (int) $anchor['id'] );
}
