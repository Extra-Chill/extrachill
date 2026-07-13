<?php
/**
 * PHP Error Log Parser & Signature Normalizer
 *
 * Reads the WordPress PHP error log (wp-content/debug.log), normalizes each
 * entry to a stable error signature, and snapshots per-day signature counts
 * into a durable table so trend rates survive log rotation.
 *
 * This is intentionally NOT the Data Machine job logger — it reads the raw
 * PHP error log file written by WP_DEBUG_LOG, not any DB-backed pipeline log.
 *
 * @package ExtraChill\Analytics
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site-option key storing the byte high-water mark of the last snapshot pass.
 *
 * The snapshot writer persists bytes [0, offset) into the durable daily table
 * and records {inode, offset} here. Read-side consumers (the summary ability)
 * read the live tail starting at this offset so live entries are the
 * not-yet-snapshotted bytes only — disjoint from the persisted set.
 */
define( 'EXTRACHILL_ANALYTICS_PHP_ERROR_LOG_OFFSET_OPTION', 'extrachill_analytics_php_error_log_offset' );

/**
 * Resolve the path to the active PHP error log file.
 *
 * Honors the `error_log` ini directive when it points at a real file, then
 * falls back to the canonical WordPress debug.log location.
 *
 * @return string Absolute path to the debug.log file (may not exist yet).
 */
function extrachill_analytics_php_error_log_path() {
	$ini_path = ini_get( 'error_log' );

	if ( is_string( $ini_path ) && '' !== $ini_path && 'syslog' !== $ini_path ) {
		// Only trust the ini path if it resolves to a writable/readable file path,
		// not a stream wrapper or syslog target.
		if ( false === strpos( $ini_path, '://' ) ) {
			return $ini_path;
		}
	}

	return rtrim( WP_CONTENT_DIR, '/\\' ) . '/debug.log';
}

/**
 * Find rotated siblings of the debug.log within the requested window.
 *
 * Matches common rotation naming: debug.log.1, debug.log.2.gz, debug.log-20260101.
 * Gzipped siblings are skipped (we do not decompress here to keep the read cheap).
 *
 * @param string $log_path Primary log path.
 * @return string[] Sibling log paths, newest-first by mtime.
 */
function extrachill_analytics_php_error_log_siblings( $log_path ) {
	$dir  = dirname( $log_path );
	$base = basename( $log_path );

	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$candidates = glob( $dir . '/' . $base . '.*' );
	if ( false === $candidates ) {
		$candidates = array();
	}

	$dated = glob( $dir . '/' . $base . '-*' );
	if ( is_array( $dated ) ) {
		$candidates = array_merge( $candidates, $dated );
	}

	$siblings = array();
	foreach ( $candidates as $candidate ) {
		// Skip compressed rotations — reading them is out of scope for the live tail.
		if ( preg_match( '/\.(gz|bz2|zip)$/i', $candidate ) ) {
			continue;
		}
		if ( is_file( $candidate ) && is_readable( $candidate ) ) {
			$siblings[ $candidate ] = filemtime( $candidate );
		}
	}

	arsort( $siblings );

	return array_keys( $siblings );
}

/**
 * Parse a window of the PHP error log into normalized error entries.
 *
 * Multi-line stack traces are folded into the preceding timestamped entry and
 * counted once. Continuation lines (those without a leading [timestamp]) are
 * treated as part of the prior entry.
 *
 * @param string   $log_path Path to a log file.
 * @param int|null $since_ts Unix timestamp lower bound (inclusive). Null = no bound.
 * @param int      $max_bytes Maximum bytes to read from the tail of the file. 0 = whole file.
 * @param int      $start_offset Byte offset to begin reading at. Used to read only
 *                              the not-yet-snapshotted tail: persisted bytes live in
 *                              [0, start_offset), so the live read starts here to
 *                              stay disjoint and avoid double counting. The effective
 *                              start is max( start_offset, size - max_bytes ) so the
 *                              memory cap is still honored.
 * @return array{
 *     entries: array<int, array{ts:int|null, severity:string, message:string, file:string, line:int, signature:string, sample:string, origin_path:string, from_production:bool}>,
 *     min_ts: int|null,
 *     max_ts: int|null
 * }
 */
function extrachill_analytics_parse_php_error_log( $log_path, $since_ts = null, $max_bytes = 0, $start_offset = 0 ) {
	$result = array(
		'entries' => array(),
		'min_ts'  => null,
		'max_ts'  => null,
	);

	if ( ! is_file( $log_path ) || ! is_readable( $log_path ) ) {
		return $result;
	}

	$handle = fopen( $log_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $handle ) {
		return $result;
	}

	if ( $max_bytes > 0 ) {
		$size = filesize( $log_path );
		if ( is_int( $size ) && $size > $max_bytes ) {
			$tail_start = $size - $max_bytes;
			// Honor the memory cap: never read earlier than the tail window.
			if ( $tail_start > $start_offset ) {
				$start_offset = $tail_start;
			}
		}
	}

	if ( $start_offset > 0 ) {
		// Only discard the first line when the seek landed mid-line (a partial
		// fragment). The snapshot byte watermark always lands on a line boundary
		// (ftell after a complete fgets loop), so its first full entry must be
		// preserved — discarding it would silently lose the newest snapshot gap.
		// A tail-memory-cap seek lands mid-line and drops the fragment instead.
		fseek( $handle, $start_offset - 1 );
		$prev = fgetc( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgetc — advances pointer to start_offset.
		if ( "\n" !== $prev ) {
			fgets( $handle ); // Discard the remainder of the partial first line.
		}
	}

	$current = null;

	$flush = function () use ( &$current, &$result, $since_ts ) {
		if ( null === $current ) {
			return;
		}

		$entry   = $current;
		$current = null;

		if ( null !== $since_ts && null !== $entry['ts'] && $entry['ts'] < $since_ts ) {
			return;
		}

		$normalized = extrachill_analytics_normalize_php_error( $entry['raw'] );
		if ( null === $normalized ) {
			return;
		}

		$normalized['ts']    = $entry['ts'];
		$result['entries'][] = $normalized;

		if ( null !== $entry['ts'] ) {
			if ( null === $result['min_ts'] || $entry['ts'] < $result['min_ts'] ) {
				$result['min_ts'] = $entry['ts'];
			}
			if ( null === $result['max_ts'] || $entry['ts'] > $result['max_ts'] ) {
				$result['max_ts'] = $entry['ts'];
			}
		}
	};

	for ( $line = fgets( $handle ); false !== $line; $line = fgets( $handle ) ) {
		$ts = extrachill_analytics_parse_log_timestamp( $line );

		if ( null !== $ts || preg_match( '/^\[/', $line ) ) {
			// New entry begins at a bracketed line.
			$flush();
			$current = array(
				'ts'  => $ts,
				'raw' => rtrim( $line, "\r\n" ),
			);
			continue;
		}

		// Continuation line (stack frame, "thrown in", etc.) — fold into current.
		if ( null !== $current ) {
			$current['raw'] .= "\n" . rtrim( $line, "\r\n" );
		}
	}

	$flush();
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	return $result;
}

/**
 * Extract a Unix timestamp from a debug.log line prefix.
 *
 * Matches the WordPress/PHP default format: [DD-Mon-YYYY HH:MM:SS UTC].
 *
 * @param string $line Raw log line.
 * @return int|null Unix timestamp or null when the line has no recognizable prefix.
 */
function extrachill_analytics_parse_log_timestamp( $line ) {
	if ( ! preg_match( '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Za-z]+)?)\]/', $line, $m ) ) {
		return null;
	}

	$ts = strtotime( $m[1] );

	return false === $ts ? null : $ts;
}

/**
 * Normalize a raw PHP error log entry into a stable signature.
 *
 * Strips the timestamp prefix, request-specific numerics, memory addresses,
 * and collapses the file:line into the signature while keeping a human-readable
 * sample for display. Mirrors the manual triage method:
 *   grep -oE "PHP (Fatal error|Warning|Notice|Deprecated):..." | sed 's/[0-9]\+//g' | sort | uniq -c
 *
 * @param string $raw Raw (possibly multi-line) log entry.
 * @return array{severity:string, message:string, file:string, line:int, signature:string, sample:string, origin_path:string, from_production:bool}|null
 *     Normalized fields, or null when the entry is not a recognizable PHP error.
 */
function extrachill_analytics_normalize_php_error( $raw ) {
	// Take only the first physical line for the headline message; the rest is the
	// stack trace which we use only for the "thrown in file:line" of fatals.
	$first_line = strtok( $raw, "\n" );
	if ( false === $first_line ) {
		$first_line = $raw;
	}

	// Drop the leading [timestamp] prefix.
	$body = preg_replace( '/^\[[^\]]*\]\s*/', '', $first_line );

	// Identify severity.
	if ( ! preg_match( '/\bPHP (Parse error|Fatal error|Recoverable fatal error|Warning|Notice|Deprecated|Strict (?:Standards|notice))\b:?/i', $body, $sev_match ) ) {
		return null;
	}

	$severity = extrachill_analytics_canonical_severity( $sev_match[1] );

	// Message = everything after "PHP <severity>:".
	$message = trim( preg_replace( '/^.*?\bPHP [^:]+:\s*/i', '', $body ) );

	// Strip HTML tags WordPress injects into _doing_it_wrong-style notices.
	$message = wp_strip_all_tags( $message );

	// Collapse whitespace.
	$message = trim( preg_replace( '/\s+/', ' ', $message ) );

	// Determine file:line. Prefer an explicit "thrown in <file> on line N" from a
	// fatal stack trace, otherwise the trailing "in <file> on line N" of the line.
	$file = '';
	$line = 0;

	if ( preg_match( '/thrown in (.+?) on line (\d+)/', $raw, $tm ) ) {
		$file = $tm[1];
		$line = (int) $tm[2];
	} elseif ( preg_match( '/ in (.+?) on line (\d+)/', $first_line, $fm ) ) {
		$file = $fm[1];
		$line = (int) $fm[2];
	}

	$file_basename = '' !== $file ? basename( $file ) : '';
	$location      = '' !== $file_basename
		? $file_basename . ( $line > 0 ? ':' . $line : '' )
		: '(unknown)';

	// Classify the origin path as production vs. non-production BEFORE the full
	// path is discarded (the signature/display use only the basename). A fatal
	// whose origin lives outside the live install — a coding-agent worktree
	// under /var/lib/datamachine/workspace, a `wp eval` phar, /tmp, etc. — is a
	// dev/agent artifact, not a live-site fault. Carrying this flag lets the
	// near-real-time alarm ignore that noise so it never pages on, e.g., a
	// "Cannot redeclare" raised by a worktree copy of an already-loaded plugin.
	// An empty/unresolvable origin classifies as production (fail open).
	$from_production = extrachill_analytics_is_production_error_path( $file );

	// Build the normalized message used for the signature: strip the trailing
	// "in <path> on line N", drop request-specific numerics, addresses, and IDs.
	$norm = preg_replace( '/ in .+? on line \d+.*$/', '', $message );
	$norm = preg_replace( '/0x[0-9a-fA-F]+/', '0xADDR', $norm );          // Memory addresses.
	$norm = preg_replace( '/\bid[=: ]+\d+/i', 'id=N', $norm );            // Explicit IDs.
	$norm = preg_replace( '/\d+/', 'N', $norm );                          // Remaining numerics.
	$norm = trim( preg_replace( '/\s+/', ' ', $norm ) );

	// Signature groups by severity + file basename + normalized message text.
	$signature_input = $severity . '|' . $file_basename . '|' . $norm;
	$signature       = substr( md5( $signature_input ), 0, 12 );

	// Human-readable sample for display: severity, location, trimmed message.
	$sample_message = $message;
	if ( strlen( $sample_message ) > 160 ) {
		$sample_message = substr( $sample_message, 0, 157 ) . '...';
	}

	return array(
		'severity'        => $severity,
		'message'         => $norm,
		'file'            => $location,
		'line'            => $line,
		'signature'       => $signature,
		'sample'          => $sample_message,
		'origin_path'     => $file,
		'from_production' => $from_production,
	);
}

/**
 * Decide whether a fatal's origin file path belongs to the live install.
 *
 * Production = under the running WordPress install (WP_CONTENT_DIR or ABSPATH).
 * Everything else is non-production: coding-agent worktrees under the workspace
 * root, WP-CLI `eval`/`eval-file` phars, /tmp scratch, etc. Such errors are
 * dev/agent artifacts and must not page the near-real-time fatal alarm.
 *
 * Fails OPEN: an unresolvable or unexpected path is treated as production so a
 * genuine fatal is never silently suppressed by a path-matching gap.
 *
 * @param string $file Absolute origin file path from the log entry.
 * @return bool True when the path is part of the live install.
 */
function extrachill_analytics_is_production_error_path( $file ) {
	$file = (string) $file;

	if ( '' === $file ) {
		return true;
	}

	// Roots that constitute the live install. Normalize trailing slashes.
	$production_roots = array();
	if ( defined( 'WP_CONTENT_DIR' ) && WP_CONTENT_DIR ) {
		$production_roots[] = rtrim( WP_CONTENT_DIR, '/' );
	}
	if ( defined( 'ABSPATH' ) && ABSPATH ) {
		$production_roots[] = rtrim( ABSPATH, '/' );
	}

	/**
	 * Filter the absolute path roots that count as the live install for fatal
	 * classification. A fatal whose origin is under one of these roots is
	 * "production"; anything else is treated as dev/agent noise.
	 *
	 * @param array  $production_roots Absolute directory roots (no trailing slash).
	 * @param string $file             The origin path being classified.
	 */
	$production_roots = (array) apply_filters(
		'extrachill_analytics_production_error_roots',
		$production_roots,
		$file
	);

	// No roots resolved (unexpected): fail open.
	if ( empty( $production_roots ) ) {
		return true;
	}

	foreach ( $production_roots as $root ) {
		$root = rtrim( (string) $root, '/' );
		if ( '' === $root ) {
			continue;
		}
		if ( 0 === strpos( $file, $root . '/' ) || $file === $root ) {
			return true;
		}
	}

	return false;
}

/**
 * Map a raw severity token to a canonical lowercase severity.
 *
 * @param string $raw_severity Severity token from the log line.
 * @return string One of: fatal, warning, notice, deprecated, strict, parse.
 */
function extrachill_analytics_canonical_severity( $raw_severity ) {
	$raw = strtolower( $raw_severity );

	if ( false !== strpos( $raw, 'parse' ) ) {
		return 'parse';
	}
	if ( false !== strpos( $raw, 'fatal' ) ) {
		return 'fatal';
	}
	if ( false !== strpos( $raw, 'warning' ) ) {
		return 'warning';
	}
	if ( false !== strpos( $raw, 'deprecated' ) ) {
		return 'deprecated';
	}
	if ( false !== strpos( $raw, 'strict' ) ) {
		return 'strict';
	}

	return 'notice';
}

/**
 * Resolve the byte offset marking the boundary between already-snapshotted
 * (persisted) bytes and not-yet-snapshotted bytes of the current error log.
 *
 * The snapshot writer (extrachill_analytics_snapshot_php_errors) persists bytes
 * [0, offset) into the durable daily table and stores {inode, offset}. Reading
 * the live tail from this offset guarantees live entries are disjoint from the
 * persisted set, preventing double counting when the summary ability merges the
 * two sources.
 *
 * Rotation / truncation is detected the same way the snapshot writer does: a
 * changed inode (when one was previously recorded) or a file shorter than the
 * stored offset resets to 0 — the persisted data from the prior inode is
 * already in the table, and a truncated/rotated file starts fresh. This helper
 * is read-only: it never mutates the stored offset (the snapshot owns the write).
 *
 * @param string $log_path Absolute path to the error log file.
 * @return int Byte offset to start reading live (unsnapshotted) bytes from.
 */
function extrachill_analytics_php_error_log_snapshot_offset( $log_path ) {
	if ( ! is_file( $log_path ) || ! is_readable( $log_path ) ) {
		return 0;
	}

	clearstatcache( true, $log_path );
	$size  = (int) filesize( $log_path );
	$inode = (int) @fileinode( $log_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$stored = get_site_option(
		EXTRACHILL_ANALYTICS_PHP_ERROR_LOG_OFFSET_OPTION,
		array(
			'inode'  => 0,
			'offset' => 0,
		)
	);
	if ( ! is_array( $stored ) ) {
		$stored = array(
			'inode'  => 0,
			'offset' => 0,
		);
	}

	$stored_inode  = isset( $stored['inode'] ) ? (int) $stored['inode'] : 0;
	$stored_offset = isset( $stored['offset'] ) ? (int) $stored['offset'] : 0;

	// Rotation / truncation: new inode (when one was recorded), or the file
	// shrank below the stored offset. Either means the stored boundary no
	// longer describes the current file — start live reads from the top.
	if ( ( 0 !== $stored_inode && $stored_inode !== $inode ) || $size < $stored_offset ) {
		return 0;
	}

	return $stored_offset;
}

/**
 * Snapshot today's (and recent) signature counts from the live log into the
 * durable daily table, so per-day rates survive log rotation.
 *
 * Uses a stored byte high-water mark to read only new bytes since the last run.
 * Detects rotation (file shorter than stored offset) and resets the offset.
 * Idempotent within a day at the row level via INSERT ... ON DUPLICATE KEY
 * UPDATE keyed on (snapshot_day, signature); re-reading already-snapshotted
 * bytes is prevented by the byte offset, not by row math.
 *
 * @return array{read_bytes:int, entries:int, days_touched:int, rotated:bool}
 */
function extrachill_analytics_snapshot_php_errors() {
	global $wpdb;

	$log_path      = extrachill_analytics_php_error_log_path();
	$offset_option = EXTRACHILL_ANALYTICS_PHP_ERROR_LOG_OFFSET_OPTION;
	$stored        = get_site_option(
		$offset_option,
		array(
			'inode'  => 0,
			'offset' => 0,
		)
	);
	if ( ! is_array( $stored ) ) {
		$stored = array(
			'inode'  => 0,
			'offset' => 0,
		);
	}

	$summary = array(
		'read_bytes'   => 0,
		'entries'      => 0,
		'days_touched' => 0,
		'rotated'      => false,
	);

	if ( ! is_file( $log_path ) || ! is_readable( $log_path ) ) {
		return $summary;
	}

	clearstatcache( true, $log_path );
	$size  = (int) filesize( $log_path );
	$inode = (int) @fileinode( $log_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$start_offset = isset( $stored['offset'] ) ? (int) $stored['offset'] : 0;

	// Rotation / truncation detection: new inode, or file shorter than offset.
	if ( ( isset( $stored['inode'] ) && (int) $stored['inode'] !== $inode && 0 !== (int) $stored['inode'] ) || $size < $start_offset ) {
		$summary['rotated'] = true;
		$start_offset       = 0;
	}

	if ( $size <= $start_offset ) {
		// Nothing new to read; just persist current position.
		update_site_option(
			$offset_option,
			array(
				'inode'  => $inode,
				'offset' => $size,
			)
		);
		return $summary;
	}

	$handle = fopen( $log_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $handle ) {
		return $summary;
	}

	fseek( $handle, $start_offset );

	$current    = null;
	$daily      = array(); // [ day => [ signature => [count, severity, file, sample, first_ts, last_ts] ] ].
	$read_bytes = 0;

	$accumulate = function ( $entry ) use ( &$daily ) {
		if ( null === $entry || null === $entry['ts'] ) {
			return;
		}
		$normalized = extrachill_analytics_normalize_php_error( $entry['raw'] );
		if ( null === $normalized ) {
			return;
		}

		$day = gmdate( 'Y-m-d', $entry['ts'] );
		$sig = $normalized['signature'];

		if ( ! isset( $daily[ $day ][ $sig ] ) ) {
			$daily[ $day ][ $sig ] = array(
				'count'    => 0,
				'severity' => $normalized['severity'],
				'file'     => $normalized['file'],
				'sample'   => $normalized['sample'],
				'first_ts' => $entry['ts'],
				'last_ts'  => $entry['ts'],
			);
		}

		++$daily[ $day ][ $sig ]['count'];
		$daily[ $day ][ $sig ]['first_ts'] = min( $daily[ $day ][ $sig ]['first_ts'], $entry['ts'] );
		$daily[ $day ][ $sig ]['last_ts']  = max( $daily[ $day ][ $sig ]['last_ts'], $entry['ts'] );
	};

	for ( $line = fgets( $handle ); false !== $line; $line = fgets( $handle ) ) {
		$read_bytes += strlen( $line );
		$ts          = extrachill_analytics_parse_log_timestamp( $line );

		if ( null !== $ts || preg_match( '/^\[/', $line ) ) {
			$accumulate( $current );
			$current = array(
				'ts'  => $ts,
				'raw' => rtrim( $line, "\r\n" ),
			);
			continue;
		}

		if ( null !== $current ) {
			$current['raw'] .= "\n" . rtrim( $line, "\r\n" );
		}
	}

	$accumulate( $current );

	$end_offset = ftell( $handle );
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	$table = extrachill_analytics_php_error_table();

	foreach ( $daily as $day => $signatures ) {
		foreach ( $signatures as $sig => $data ) {
			$summary['entries'] += $data['count'];

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
						(snapshot_day, signature, severity, file_line, sample_message, count, first_seen, last_seen)
					VALUES (%s, %s, %s, %s, %s, %d, %s, %s)
					ON DUPLICATE KEY UPDATE
						count = count + VALUES(count),
						last_seen = GREATEST(last_seen, VALUES(last_seen)),
						first_seen = LEAST(first_seen, VALUES(first_seen)),
						severity = VALUES(severity),
						file_line = VALUES(file_line),
						sample_message = VALUES(sample_message)",
					$day,
					$sig,
					$data['severity'],
					$data['file'],
					$data['sample'],
					$data['count'],
					gmdate( 'Y-m-d H:i:s', $data['first_ts'] ),
					gmdate( 'Y-m-d H:i:s', $data['last_ts'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	$summary['read_bytes']   = $read_bytes;
	$summary['days_touched'] = count( $daily );

	update_site_option(
		$offset_option,
		array(
			'inode'  => $inode,
			'offset' => $end_offset,
		)
	);

	return $summary;
}

/**
 * Daily snapshot cron callback.
 */
function extrachill_analytics_php_error_snapshot_cron() {
	extrachill_analytics_snapshot_php_errors();
}
add_action( 'extrachill_analytics_php_error_snapshot', 'extrachill_analytics_php_error_snapshot_cron' );

/**
 * Ensure the daily snapshot cron is scheduled.
 */
function extrachill_analytics_schedule_php_error_snapshot() {
	if ( ! wp_next_scheduled( 'extrachill_analytics_php_error_snapshot' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'extrachill_analytics_php_error_snapshot' );
	}
}
add_action( 'init', 'extrachill_analytics_schedule_php_error_snapshot' );
