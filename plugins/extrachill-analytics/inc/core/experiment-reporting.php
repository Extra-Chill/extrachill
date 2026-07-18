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
					EC_ANALYTICS_EVENT_REDIRECT_FIRE,
				),
				EC_ANALYTICS_TEAM_EXPERIENCE_EVENTS,
				EC_ANALYTICS_ONBOARDING_FUNNEL_EVENTS,
				EC_ANALYTICS_LOCAL_SCENE_PROMPT_EVENTS,
				EC_ANALYTICS_ARTIST_FUNNEL_EVENTS,
				EC_ANALYTICS_ARTIST_ACTIVATION_FRICTION_EVENTS,
				EC_ANALYTICS_ARTIST_DISPATCH_EVENTS
			)
		)
	);
}

/**
 * Read relevant rows with stable ascending time/ID keyset pagination.
 *
 * @param string[] $event_types Canonical event names.
 * @param string   $since       Inclusive UTC lower bound.
 * @param string   $as_of       Inclusive UTC upper bound.
 * @param int      $max_events  Hard row ceiling.
 * @return array{rows:array,truncated:bool}
 */
function extrachill_analytics_experiment_read_events( $event_types, $since, $as_of, $max_events ) {
	global $wpdb;

	$event_types = array_values( array_unique( array_map( 'sanitize_key', $event_types ) ) );
	if ( empty( $event_types ) ) {
		return array(
			'rows'      => array(),
			'truncated' => false,
		);
	}

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
		$values = array_merge( $event_types, array( $since, $as_of ) );
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
		$rows         = array_merge( $rows, $page );
		$page_count   = count( $page );
		$last         = end( $page );
		$cursor_time  = (string) $last->created_at;
		$cursor_id    = (int) $last->id;
		$current_rows = count( $rows );
	} while ( $page_count === $limit && $current_rows <= $max_events );

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

	$map       = array();
	$ambiguous = array();
	foreach ( $visitor_users as $visitor_id => $users ) {
		if ( 1 === count( $users ) ) {
			$map[ $visitor_id ] = (int) array_key_first( $users );
		} else {
			$ambiguous[ $visitor_id ] = true;
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
