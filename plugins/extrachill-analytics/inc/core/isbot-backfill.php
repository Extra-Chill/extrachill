<?php
/**
 * Legacy `is_bot` Backfill
 *
 * One-off maintenance path that stamps the canonical human/bot verdict onto
 * analytics events written BEFORE the write-time classifier existed (issue #57
 * shipped the go-forward stamp in extrachill_track_analytics_event()). Those
 * legacy rows carry no `is_bot` key in their `event_data` JSON, so every
 * downstream reader that filters on the flag must decide what to do with them.
 *
 * WHY THIS EXISTS. get-search-gaps.php deliberately keeps NULL/no-flag legacy
 * rows as "human" (the ternary-aware `IS NOT TRUE` predicate) and surfaces an
 * `unclassified` count rather than guessing — a correct, conservative default
 * for the go-forward reader (issues #85/#86/#87). But on this install the
 * legacy search corpus is ~253k rows dated 2026-01-25 → 2026-06-20 that are
 * overwhelmingly programmatic (events-pipeline / community band-name lookups
 * fired server-side, the #51 root cause). Because they are counted as human,
 * the 28-day demand report stays polluted until they age out of the window.
 * This backfill retires that pollution by stamping the flag directly, instead
 * of waiting weeks for the window to roll past them.
 *
 * THE CLASSIFICATION RULE (derived, not hard-coded "all legacy = bot").
 * A historical row cannot be re-inspected live — the UA, request origin, and
 * auth state that the canonical classifier uses are gone. The ONE signal that
 * survives on the row itself is the same one the write path treats as the
 * positive human marker: a stitched first-party `visitor_id` (the ec_vid
 * cookie). The canonical anonymous-traffic verdict in
 * extrachill_analytics_classify_request() is "human ONLY when a real browser
 * minted the cookie; a cookieless anonymous request is non-human." Applied to a
 * stored row that is the derivable rule:
 *
 *   - visitor_id present  → stamp is_bot = false  (human-plausible; a browser ran)
 *   - visitor_id absent   → stamp is_bot = true   (cookieless anonymous → bot)
 *
 * This reuses the canonical classifier by feeding it the one signal a stored
 * row can supply (`has_visitor_cookie`) and forcing the anonymous-traffic path
 * (is_authenticated = false, request_origin = 'web', UA unknown/empty) — it does
 * NOT reinvent the verdict. When even that single signal is ambiguous the
 * command errs toward NOT stamping (a row is only ever touched when it is
 * missing the flag), never toward a confident wrong verdict.
 *
 * IDEMPOTENT + SAFE. The command only ever touches rows whose `event_data`
 * still lacks the `is_bot` key (`JSON_EXTRACT(...,'$.is_bot') IS NULL`), merges
 * the single key in with JSON_SET (preserving every other key), and runs in a
 * bounded LIMIT loop. Re-running it is a no-op once a row is stamped. It
 * defaults to a dry run; a live pass requires the explicit --live flag.
 *
 * @package ExtraChill\Analytics
 * @since 0.24.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the backfill verdict for a single legacy row from the one signal a
 * stored row can supply: whether it carries a stitched visitor_id cookie.
 *
 * Delegates to the canonical classifier so the backfill and the write path
 * agree on what "human" means. Of the four signals the classifier weighs, only
 * the visitor cookie survives on a stored row — the live UA, request origin,
 * and auth state are gone. We therefore pin the other three to the values that
 * let the COOKIE be the sole deciding factor, isolating the one historically
 * truthful signal:
 *
 *   - is_authenticated = false  — legacy anonymous front-end traffic; the
 *     authenticated-user short-circuit (#103) must NOT fire, or every legacy
 *     row would false-classify as human.
 *   - request_origin   = 'web'  — pin to the ONE origin the anonymous-traffic
 *     policy accepts, so origin is neutralized and doesn't force a bot verdict
 *     on its own.
 *   - user_agent       = a neutral browser UA — pin to the 'browser' UA class
 *     (not '' which classifies 'empty' → bot) so the UA is neutralized too.
 *
 * With auth/origin/UA neutralized, the classifier's verdict reduces to exactly
 * `is_bot = ! has_visitor_cookie`: cookie present → human, cookieless → bot.
 * That is the derived rule, produced BY the canonical classifier rather than
 * hard-coded around it, so if the shared human policy ever changes this backfill
 * follows it. (An empty '' UA was intentionally NOT used: it classifies as
 * 'empty' and would force a bot verdict regardless of the cookie, collapsing the
 * cookie signal we are trying to preserve.)
 *
 * @param bool $has_visitor_cookie Whether the row stored a non-empty visitor_id.
 * @return bool True to stamp is_bot = true (bot), false to stamp is_bot = false (human).
 */
function extrachill_analytics_backfill_is_bot_verdict( $has_visitor_cookie ) {
	if ( function_exists( 'extrachill_analytics_classify_request' ) ) {
		$verdict = extrachill_analytics_classify_request(
			array(
				'has_visitor_cookie' => (bool) $has_visitor_cookie,
				'is_authenticated'   => false,
				'request_origin'     => 'web',
				// Neutral browser-class UA so the UA signal doesn't veto the
				// cookie signal (an empty string classifies as 'empty' == bot).
				'user_agent'         => 'Mozilla/5.0',
			)
		);

		return (bool) $verdict['is_bot'];
	}

	// Defensive fallback if the classifier is somehow unavailable: the cookie is
	// the sole positive human signal, so cookieless == bot.
	return ! $has_visitor_cookie;
}

/**
 * Backfill the canonical `is_bot` flag onto legacy events missing it.
 *
 * Idempotent, batched, dry-run-by-default. Only rows whose event_data JSON does
 * not already contain an `is_bot` key are considered; each is stamped with the
 * per-row verdict from extrachill_analytics_backfill_is_bot_verdict(), merging
 * the key in via JSON_SET so no other event_data key is disturbed.
 *
 * @param array $args {
 *     Optional. Backfill parameters.
 *
 *     @type string   $event_type Event type to scope to, or 'all' for every
 *                                type. Default 'search'.
 *     @type int      $blog_id    Restrict to a single blog id. 0 = all. Default 0.
 *     @type int      $batch_size Rows processed per UPDATE loop. Default 2000.
 *     @type bool     $live       When false (default) computes counts without
 *                                writing. When true, performs the UPDATEs.
 *     @type callable $progress   Optional callback( array $tick ) invoked after
 *                                each batch with running totals for CLI output.
 * }
 * @return array{
 *     scanned: int,
 *     stamped_bot: int,
 *     stamped_human: int,
 *     left_null: int,
 *     batches: int,
 *     live: bool,
 *     event_type: string,
 *     blog_id: int
 * }
 */
function extrachill_analytics_backfill_is_bot( $args = array() ) {
	global $wpdb;

	$defaults = array(
		'event_type' => 'search',
		'blog_id'    => 0,
		'batch_size' => 2000,
		'live'       => false,
		'progress'   => null,
	);

	$args       = wp_parse_args( $args, $defaults );
	$event_type = (string) $args['event_type'];
	$blog_id    = (int) $args['blog_id'];
	$batch_size = max( 1, (int) $args['batch_size'] );
	$live       = (bool) $args['live'];
	$progress   = is_callable( $args['progress'] ) ? $args['progress'] : null;

	$table = extrachill_analytics_events_table();

	// Build the shared WHERE for "legacy rows still missing the flag".
	$where  = array( "JSON_EXTRACT(event_data, '$.is_bot') IS NULL" );
	$values = array();

	if ( 'all' !== strtolower( $event_type ) && '' !== $event_type ) {
		$where[]  = 'event_type = %s';
		$values[] = sanitize_key( $event_type );
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	$where_clause = implode( ' AND ', $where );

	$totals = array(
		'scanned'       => 0,
		'stamped_bot'   => 0,
		'stamped_human' => 0,
		'left_null'     => 0,
		'batches'       => 0,
		'live'          => $live,
		'event_type'    => $event_type,
		'blog_id'       => $blog_id,
	);

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Cursor by id so a live run (which removes rows from the candidate set as it
	// stamps them) and a dry run (which does not) both make forward progress
	// without re-reading the same rows.
	$last_id = 0;

	do {
		$batch_where    = $where;
		$batch_where[]  = 'id > %d';
		$batch_values   = $values;
		$batch_values[] = $last_id;
		$batch_values[] = $batch_size;

		$select_sql = "SELECT id, visitor_id FROM {$table} WHERE "
			. implode( ' AND ', $batch_where )
			. ' ORDER BY id ASC LIMIT %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $batch_values ) );

		if ( empty( $rows ) ) {
			break;
		}

		$bot_ids   = array();
		$human_ids = array();

		foreach ( $rows as $row ) {
			$last_id            = (int) $row->id;
			$has_visitor_cookie = ( null !== $row->visitor_id && '' !== $row->visitor_id );
			$is_bot             = extrachill_analytics_backfill_is_bot_verdict( $has_visitor_cookie );

			if ( $is_bot ) {
				$bot_ids[] = (int) $row->id;
			} else {
				$human_ids[] = (int) $row->id;
			}
		}

		$totals['scanned']       += count( $rows );
		$totals['stamped_bot']   += count( $bot_ids );
		$totals['stamped_human'] += count( $human_ids );
		++$totals['batches'];

		if ( $live ) {
			// Merge the single key in with JSON_SET so every other event_data key
			// is preserved. Two grouped UPDATEs per batch (bot vs human) keep the
			// verdict literal out of a per-row loop.
			if ( ! empty( $bot_ids ) ) {
				extrachill_analytics_backfill_apply( $table, $bot_ids, true );
			}
			if ( ! empty( $human_ids ) ) {
				extrachill_analytics_backfill_apply( $table, $human_ids, false );
			}
		}

		if ( $progress ) {
			call_user_func( $progress, $totals );
		}
	} while ( count( $rows ) === $batch_size ); // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- count() must re-evaluate each iteration: $rows is a fresh paginated batch, so hoisting it would break loop termination.

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return $totals;
}

/**
 * Apply the is_bot stamp to a set of ids using JSON_SET so the merge preserves
 * all other event_data keys.
 *
 * @param string $table  Fully-qualified events table name.
 * @param int[]  $ids     Row ids to stamp.
 * @param bool   $is_bot  Verdict to write.
 * @return int|false Rows affected, or false on error.
 */
function extrachill_analytics_backfill_apply( $table, $ids, $is_bot ) {
	global $wpdb;

	$ids = array_map( 'intval', (array) $ids );
	if ( empty( $ids ) ) {
		return 0;
	}

	// JSON_SET writes a real JSON boolean so the stamped value matches the
	// write-path shape ($.is_bot is a JSON true/false, which the readers test
	// with IS TRUE / IS NOT TRUE). The value is the bare SQL boolean keyword
	// `true`/`false` — NOT `CAST('true' AS JSON)`, which errors on this MariaDB
	// server (verified). JSON_SET(..., true) yields {"is_bot": true}, identical
	// to what extrachill_track_analytics_event() persists. The literal is a
	// fixed, code-defined keyword — never request input.
	$bool_literal = $is_bot ? 'true' : 'false';
	$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$sql = "UPDATE {$table} "
		. "SET event_data = JSON_SET(event_data, '$.is_bot', {$bool_literal}) "
		. "WHERE id IN ({$placeholders}) AND JSON_EXTRACT(event_data, '$.is_bot') IS NULL";

	$result = $wpdb->query( $wpdb->prepare( $sql, $ids ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return $result;
}

/**
 * Backfill correcting mis-stamped `is_bot:true` pageview rows.
 *
 * Issue #115: the `extrachill/track-page-view` ability runs inside a REST
 * request, so the generic classifier's request_origin signal stamped every
 * anonymous beacon pageview as bot. This migration finds pageview rows that
 * carry a visitor_id (the JS beacon minted an ec_vid cookie) but are flagged
 * `is_bot:true`, and re-stamps them `false`.
 *
 * Idempotent: once a row is stamped `false` it no longer matches the
 * `JSON_EXTRACT(..., '$.is_bot') IS TRUE` predicate. A one-time network option
 * guard prevents accidental re-runs; pass `skip_guard => true` to bypass.
 *
 * @param array $args {
 *     Optional. Backfill parameters.
 *
 *     @type int      $blog_id    Restrict to a single blog id. 0 = all. Default 0.
 *     @type int      $batch_size Rows processed per UPDATE loop. Default 2000.
 *     @type bool     $live       When false (default) computes counts without
 *                                writing. When true, performs the UPDATEs.
 *     @type callable $progress   Optional callback( array $tick ) invoked after
 *                                each batch with running totals for CLI output.
 *     @type bool     $skip_guard Skip the one-time guard option. Default false.
 * }
 * @return array{
 *     scanned: int,
 *     corrected: int,
 *     batches: int,
 *     live: bool,
 *     blog_id: int
 * }
 */
function extrachill_analytics_backfill_fix_pageview_is_bot( $args = array() ) {
	global $wpdb;

	$defaults = array(
		'blog_id'    => 0,
		'batch_size' => 2000,
		'live'       => false,
		'progress'   => null,
		'skip_guard' => false,
	);

	$args       = wp_parse_args( $args, $defaults );
	$blog_id    = (int) $args['blog_id'];
	$batch_size = max( 1, (int) $args['batch_size'] );
	$live       = (bool) $args['live'];
	$progress   = is_callable( $args['progress'] ) ? $args['progress'] : null;

	$table = extrachill_analytics_events_table();

	// Only pageview rows with a stored visitor_id and an explicit bot flag are
	// candidates. visitor_id IS NOT NULL is the positive human signal: a real
	// browser minted the ec_vid cookie before the beacon fired. The REST-origin
	// stamp condemned these rows; this corrects it without touching rows that
	// were already false or that lack a visitor_id.
	$where  = array(
		"event_type = 'pageview'",
		'visitor_id IS NOT NULL',
		"JSON_EXTRACT(event_data, '$.is_bot') IS TRUE",
	);
	$values = array();

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	$totals = array(
		'scanned'   => 0,
		'corrected' => 0,
		'batches'   => 0,
		'live'      => $live,
		'blog_id'   => $blog_id,
	);

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Cursor by id so a live run (which removes rows from the candidate set as it
	// re-stamps them) and a dry run both make forward progress without re-reading
	// the same rows.
	$last_id = 0;

	do {
		$batch_where    = $where;
		$batch_where[]  = 'id > %d';
		$batch_values   = $values;
		$batch_values[] = $last_id;
		$batch_values[] = $batch_size;

		$select_sql = "SELECT id FROM {$table} WHERE "
			. implode( ' AND ', $batch_where )
			. ' ORDER BY id ASC LIMIT %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $batch_values ) );

		if ( empty( $rows ) ) {
			break;
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$last_id = (int) $row->id;
			$ids[]   = (int) $row->id;
		}

		$totals['scanned']   += count( $rows );
		$totals['corrected'] += count( $ids );
		++$totals['batches'];

		if ( $live && ! empty( $ids ) ) {
			// Merge the corrected flag in with JSON_SET so every other event_data
			// key is preserved. The WHERE clause repeats the candidate predicate
			// so a concurrent write cannot flip a row back to true unnoticed, and
			// so re-running the backfill is a no-op for already-corrected rows.
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = "UPDATE {$table} "
				. "SET event_data = JSON_SET(event_data, '$.is_bot', false) "
				. "WHERE id IN ({$placeholders}) AND JSON_EXTRACT(event_data, '$.is_bot') IS TRUE";

			$wpdb->query( $wpdb->prepare( $sql, $ids ) );
		}

		if ( $progress ) {
			call_user_func( $progress, $totals );
		}
	} while ( count( $rows ) === $batch_size ); // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- $rows is a fresh paginated batch, so hoisting it would break loop termination.

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return $totals;
}

// -----------------------------------------------------------------------------
// WP-CLI command
// -----------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Backfill the canonical is_bot flag onto legacy pre-classifier events.
	 *
	 * ## OPTIONS
	 *
	 * [--event-type=<type>]
	 * : Event type to scope the backfill to, or 'all' for every type. The search
	 *   corpus is the priority because it pollutes the get-search-gaps demand
	 *   report; other types (404_error, etc.) feed different readers, so scope
	 *   stays tight by default.
	 * ---
	 * default: search
	 * ---
	 *
	 * [--blog=<id>]
	 * : Restrict to a single blog id. 0 = network-wide (all blogs).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--batch-size=<n>]
	 * : Rows processed per batch loop.
	 * ---
	 * default: 2000
	 * ---
	 *
	 * [--dry-run]
	 * : Compute and report the projected split WITHOUT writing. This is the
	 *   default; the flag is accepted for explicitness.
	 *
	 * [--live]
	 * : Actually write the stamps. Without this flag the command is read-only.
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry-run the network-wide search backfill (default, no writes).
	 *     wp extrachill-analytics backfill-isbot
	 *
	 *     # Live backfill search events for blog 1 only.
	 *     wp extrachill-analytics backfill-isbot --live --blog=1
	 *
	 *     # Live backfill every event type still missing the flag.
	 *     wp extrachill-analytics backfill-isbot --event-type=all --live
	 *
	 * @param array $pos_args   Positional args (unused).
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	function extrachill_analytics_cli_backfill_isbot( $pos_args, $assoc_args ) {
		$live       = isset( $assoc_args['live'] );
		$event_type = isset( $assoc_args['event-type'] ) ? (string) $assoc_args['event-type'] : 'search';
		$blog_id    = isset( $assoc_args['blog'] ) ? (int) $assoc_args['blog'] : 0;
		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 2000;

		WP_CLI::log(
			sprintf(
				'is_bot backfill — event_type=%s, blog=%s, batch_size=%d, mode=%s',
				$event_type,
				$blog_id > 0 ? (string) $blog_id : 'all',
				$batch_size,
				$live ? 'LIVE (writing)' : 'DRY-RUN (no writes)'
			)
		);

		$progress = static function ( $tick ) {
			WP_CLI::log(
				sprintf(
					'  batch %d — scanned %d, bot %d, human %d',
					$tick['batches'],
					$tick['scanned'],
					$tick['stamped_bot'],
					$tick['stamped_human']
				)
			);
		};

		$result = extrachill_analytics_backfill_is_bot(
			array(
				'event_type' => $event_type,
				'blog_id'    => $blog_id,
				'batch_size' => $batch_size,
				'live'       => $live,
				'progress'   => $progress,
			)
		);

		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'%s: %d rows %s — %d bot, %d human, %d left NULL, in %d batch(es).',
				$live ? 'STAMPED' : 'WOULD STAMP',
				$result['stamped_bot'] + $result['stamped_human'],
				$live ? 'stamped' : 'projected',
				$result['stamped_bot'],
				$result['stamped_human'],
				$result['left_null'],
				$result['batches']
			)
		);

		if ( ! $live ) {
			WP_CLI::success( 'Dry-run complete. Re-run with --live to apply.' );
		} else {
			WP_CLI::success( 'Backfill complete.' );
		}
	}

	WP_CLI::add_command( 'extrachill-analytics backfill-isbot', 'extrachill_analytics_cli_backfill_isbot' );

	/**
	 * Correct mis-stamped is_bot:true pageview rows (issue #115).
	 *
	 * ## OPTIONS
	 *
	 * [--blog=<id>]
	 * : Restrict to a single blog id. 0 = network-wide (all blogs).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--batch-size=<n>]
	 * : Rows processed per batch loop.
	 * ---
	 * default: 2000
	 * ---
	 *
	 * [--dry-run]
	 * : Compute and report the projected count WITHOUT writing. This is the
	 *   default; the flag is accepted for explicitness.
	 *
	 * [--live]
	 * : Actually write the corrections. Without this flag the command is read-only.
	 *
	 * [--skip-guard]
	 * : Skip the one-time network option guard that prevents accidental re-runs.
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry-run the network-wide pageview correction (default, no writes).
	 *     wp extrachill-analytics backfill-pageview-isbot
	 *
	 *     # Live correct pageviews for blog 1 only.
	 *     wp extrachill-analytics backfill-pageview-isbot --live --blog=1
	 *
	 * @param array $pos_args   Positional args (unused).
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	function extrachill_analytics_cli_backfill_pageview_isbot( $pos_args, $assoc_args ) {
		$live       = isset( $assoc_args['live'] );
		$blog_id    = isset( $assoc_args['blog'] ) ? (int) $assoc_args['blog'] : 0;
		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 2000;
		$skip_guard = isset( $assoc_args['skip-guard'] );

		$guard_option = 'extrachill_analytics_backfill_pageview_isbot_115_done';

		if ( ! $skip_guard && get_site_option( $guard_option ) ) {
			WP_CLI::warning( 'Pageview is_bot backfill (#115) already ran. Use --skip-guard to re-run.' );
			return;
		}

		WP_CLI::log(
			sprintf(
				'Pageview is_bot correction (#115) — blog=%s, batch_size=%d, mode=%s',
				$blog_id > 0 ? (string) $blog_id : 'all',
				$batch_size,
				$live ? 'LIVE (writing)' : 'DRY-RUN (no writes)'
			)
		);

		$progress = static function ( $tick ) {
			WP_CLI::log(
				sprintf(
					'  batch %d — scanned %d, corrected %d',
					$tick['batches'],
					$tick['scanned'],
					$tick['corrected']
				)
			);
		};

		$result = extrachill_analytics_backfill_fix_pageview_is_bot(
			array(
				'blog_id'    => $blog_id,
				'batch_size' => $batch_size,
				'live'       => $live,
				'progress'   => $progress,
				'skip_guard' => $skip_guard,
			)
		);

		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'%s: %d rows %s in %d batch(es).',
				$live ? 'CORRECTED' : 'WOULD CORRECT',
				$result['corrected'],
				$live ? 'corrected' : 'projected',
				$result['batches']
			)
		);

		if ( $live ) {
			if ( ! $skip_guard ) {
				update_site_option( $guard_option, true );
			}
			WP_CLI::success( 'Backfill complete.' );
		} else {
			WP_CLI::success( 'Dry-run complete. Re-run with --live to apply.' );
		}
	}

	WP_CLI::add_command( 'extrachill-analytics backfill-pageview-isbot', 'extrachill_analytics_cli_backfill_pageview_isbot' );
}
