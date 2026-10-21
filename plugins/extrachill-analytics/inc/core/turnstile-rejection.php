<?php
/**
 * Server-side captcha evidence overrides browser-shaped bot traffic.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * How long server-side bot evidence follows a visitor, in seconds.
 *
 * Analytics beacons are separate requests from the rejected form request
 * (and usually arrive after it), so evidence must outlive the request that
 * produced it. The same window bounds the retroactive UPDATE.
 */
const EXTRACHILL_ANALYTICS_BOT_EVIDENCE_TTL = 30 * MINUTE_IN_SECONDS;

/**
 * Literal status text a captcha-solver browser extension injects into the
 * form's submit button when it has no API key. Our code never emits it, so a
 * CTA label containing it is first-party evidence of an automated visitor.
 */
const EXTRACHILL_ANALYTICS_SOLVER_SIGNATURE = 'ERROR_EMPTY_KEY';

/**
 * Transient key for a visitor's bot evidence on a blog.
 *
 * @param int    $blog_id    Blog ID.
 * @param string $visitor_id Visitor UUID.
 * @return string
 */
function extrachill_analytics_bot_evidence_key( $blog_id, $visitor_id ) {
	return 'ec_an_bot_' . md5( (int) $blog_id . '|' . (string) $visitor_id );
}

/**
 * Bot reason recorded for a visitor on the current blog, or '' when none.
 *
 * @param string|null $visitor_id Visitor UUID; null reads the request cookie.
 * @return string
 */
function extrachill_analytics_visitor_bot_reason( $visitor_id = null ) {
	$visitor_id = null === $visitor_id ? extrachill_analytics_read_visitor_id() : (string) $visitor_id;
	if ( '' === $visitor_id ) {
		return '';
	}

	$blog_id = get_current_blog_id();
	if ( ! empty( $GLOBALS['extrachill_analytics_bot_evidence'][ $blog_id ][ $visitor_id ] ) ) {
		return (string) $GLOBALS['extrachill_analytics_bot_evidence'][ $blog_id ][ $visitor_id ];
	}

	$reason = get_site_transient( extrachill_analytics_bot_evidence_key( $blog_id, $visitor_id ) );
	return is_string( $reason ) ? $reason : '';
}

/**
 * Whether the current request's visitor carries server-side bot evidence.
 *
 * @return bool
 */
function extrachill_analytics_request_turnstile_rejected() {
	return '' !== extrachill_analytics_visitor_bot_reason();
}

/**
 * Record bot evidence for a visitor and reclassify their recent events.
 *
 * Evidence persists for EXTRACHILL_ANALYTICS_BOT_EVIDENCE_TTL so later beacons
 * are stamped at write time. Rows that already carry a bot_reason keep it.
 *
 * @param string $visitor_id Visitor UUID.
 * @param string $reason     Bot reason (turnstile_rejected|solver_signature).
 * @return void
 */
function extrachill_analytics_flag_visitor_bot( $visitor_id, $reason ) {
	global $wpdb;

	$visitor_id = (string) $visitor_id;
	if ( ! extrachill_analytics_is_valid_visitor_id( $visitor_id ) ) {
		return;
	}

	$blog_id = get_current_blog_id();
	if ( '' !== extrachill_analytics_visitor_bot_reason( $visitor_id ) ) {
		return;
	}

	$GLOBALS['extrachill_analytics_bot_evidence'][ $blog_id ][ $visitor_id ] = $reason;
	set_site_transient( extrachill_analytics_bot_evidence_key( $blog_id, $visitor_id ), $reason, EXTRACHILL_ANALYTICS_BOT_EVIDENCE_TTL );

	if ( ! extrachill_analytics_events_ensure_ready() ) {
		return;
	}

	$table = extrachill_analytics_events_table();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET event_data = JSON_SET(event_data, '$.is_bot', JSON_EXTRACT('true', '$'), '$.bot_reason', %s)
			WHERE blog_id = %d AND visitor_id = %s AND created_at >= %s AND JSON_VALID(event_data)
			AND JSON_EXTRACT(event_data, '$.bot_reason') IS NULL",
			$reason,
			$blog_id,
			$visitor_id,
			gmdate( 'Y-m-d H:i:s', time() - EXTRACHILL_ANALYTICS_BOT_EVIDENCE_TTL )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Whether an event payload carries the captcha-solver signature.
 *
 * @param string $event_type Event type.
 * @param array  $event_data Event payload.
 * @return bool
 */
function extrachill_analytics_event_has_solver_signature( $event_type, $event_data ) {
	return 'cta_click' === $event_type
		&& isset( $event_data['label'] )
		&& is_string( $event_data['label'] )
		&& false !== strpos( $event_data['label'], EXTRACHILL_ANALYTICS_SOLVER_SIGNATURE );
}

/**
 * Reclassify the current visitor when Turnstile rejects their request.
 *
 * @param string $error_code Rejection code (not persisted).
 * @param mixed  $request_or_token Rejected request or token (not persisted).
 * @return void
 */
// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- extrachill_turnstile_rejected action signature; captcha data is deliberately not persisted.
function extrachill_analytics_on_turnstile_rejected( $error_code, $request_or_token ) {
	$visitor_id = extrachill_analytics_read_visitor_id();
	if ( '' === $visitor_id ) {
		return;
	}
	extrachill_analytics_flag_visitor_bot( $visitor_id, 'turnstile_rejected' );
}
add_action( 'extrachill_turnstile_rejected', 'extrachill_analytics_on_turnstile_rejected', 10, 2 );

/**
 * Stamp historical events belonging to solver-signature visitors on the same blog.
 *
 * WP-CLI-independent, idempotent and dry-run by default. Only a CTA label
 * containing the literal ERROR_EMPTY_KEY signature identifies a visitor; other
 * payload fields and generic captcha failures are not evidence.
 *
 * @param array $args Options: blog_id (0 = network), batch_size (2000), live (false).
 * @return array Counts and execution mode, or an error entry on database failure.
 */
function extrachill_analytics_backfill_solver_bots( $args = array() ) {
	global $wpdb;

	$args       = wp_parse_args( $args, array(
		'blog_id'    => 0,
		'batch_size' => 2000,
		'live'       => false,
	) );
	$table      = extrachill_analytics_events_table();
	$totals     = array(
		'scanned'     => 0,
		'stamped_bot' => 0,
		'batches'     => 0,
		'live'        => (bool) $args['live'],
	);
	$last_id    = 0;
	$batch_size = max( 1, (int) $args['batch_size'] );

	// A SELECT followed by an id-bounded UPDATE avoids MySQL's self-update
	// subquery restriction. Cursor pagination also advances during dry runs.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	do {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id FROM {$table} e
				WHERE e.id > %d AND (%d = 0 OR e.blog_id = %d)
				AND JSON_VALID(e.event_data)
				AND JSON_EXTRACT(e.event_data, '$.bot_reason') IS NULL
				AND EXISTS (SELECT 1 FROM {$table} s
					WHERE s.blog_id = e.blog_id AND s.visitor_id = e.visitor_id
					AND s.visitor_id IS NOT NULL AND s.visitor_id != ''
					AND s.event_type = 'cta_click' AND JSON_VALID(s.event_data)
					AND INSTR(JSON_EXTRACT(s.event_data, '$.label'), 'ERROR_EMPTY_KEY') > 0)
				ORDER BY e.id ASC LIMIT %d",
				$last_id, (int) $args['blog_id'], (int) $args['blog_id'], $batch_size
			)
		);
		if ( $wpdb->last_error ) {
			$totals['error'] = $wpdb->last_error;
			break;
		}
		if ( empty( $rows ) ) {
			break;
		}
		$row_count          = count( $rows );
		$ids                = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$last_id            = (int) end( $ids );
		$totals['scanned'] += count( $ids );
		++$totals['batches'];
		if ( $args['live'] ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$updated      = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- %d placeholders are built dynamically from $ids.
					"UPDATE {$table} SET event_data = JSON_SET(event_data, '$.is_bot', JSON_EXTRACT('true', '$'), '$.bot_reason', 'solver_signature') WHERE id IN ({$placeholders})",
					$ids
				)
			);
			if ( false === $updated ) {
				$totals['error'] = $wpdb->last_error;
				break;
			}
			$totals['stamped_bot'] += $updated;
		}
	} while ( $row_count === $batch_size );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $totals;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Backfill solver-signature bots. Dry run unless --live is supplied.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Options: --live, --blog=<id>, --batch-size=<n>.
	 */
	function extrachill_analytics_cli_backfill_solver_bots( $pos_args, $assoc_args ) {
		$result = extrachill_analytics_backfill_solver_bots(
			array(
				'live'       => isset( $assoc_args['live'] ),
				'blog_id'    => (int) ( $assoc_args['blog'] ?? 0 ),
				'batch_size' => (int) ( $assoc_args['batch-size'] ?? 2000 ),
			)
		);
		if ( isset( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}
		WP_CLI::log( (string) wp_json_encode( $result ) );
	}
	WP_CLI::add_command( 'extrachill-analytics backfill-solver-bots', 'extrachill_analytics_cli_backfill_solver_bots' );
}
