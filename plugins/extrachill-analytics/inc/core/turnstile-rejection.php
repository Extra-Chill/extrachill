<?php
/**
 * Server-side captcha evidence overrides browser-shaped bot traffic.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current visitor was rejected on this blog during this request.
 *
 * @return bool
 */
function extrachill_analytics_request_turnstile_rejected() {
	$visitor_id = extrachill_analytics_read_visitor_id();
	return '' !== $visitor_id && ! empty( $GLOBALS['extrachill_analytics_turnstile_rejected'][ get_current_blog_id() ][ $visitor_id ] );
}

/**
 * Reclassify the current visitor's last 30 minutes without storing captcha data.
 *
 * @param string $error_code Rejection code (not persisted).
 * @param mixed  $request_or_token Rejected request or token (not persisted).
 * @return void
 */
// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- extrachill_turnstile_rejected action signature; captcha data is deliberately not persisted.
function extrachill_analytics_on_turnstile_rejected( $error_code, $request_or_token ) {
	global $wpdb;

	$visitor_id = extrachill_analytics_read_visitor_id();
	if ( '' === $visitor_id ) {
		return;
	}

	$blog_id = get_current_blog_id();
	$GLOBALS['extrachill_analytics_turnstile_rejected'][ $blog_id ][ $visitor_id ] = true;
	if ( ! extrachill_analytics_events_ensure_ready() ) {
		return;
	}

	$table = extrachill_analytics_events_table();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET event_data = JSON_SET(event_data, '$.is_bot', JSON_EXTRACT('true', '$'), '$.bot_reason', 'turnstile_rejected')
			WHERE blog_id = %d AND visitor_id = %s AND created_at >= %s AND JSON_VALID(event_data)",
			$blog_id,
			$visitor_id,
			gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				AND COALESCE(JSON_EXTRACT(e.event_data, '$.is_bot'), false) != true
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
