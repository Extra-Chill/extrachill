<?php
/**
 * HTTP 5xx counter (web server access log).
 *
 * When PHP-FPM is saturated or down, the web server answers 502/504 itself.
 * Those responses never reach WordPress, so neither debug.log (the fatal
 * alarm) nor the REST 5xx counter can see them. This counter tails the web
 * server's access log incrementally from the 5-minute alarm tick and counts
 * status >= 500 into bounded hourly buckets keyed by "path class|status".
 *
 * - Cursor: inode, byte offset and the file's first line (its identity) in a
 *   network option. Rotation is followed into the rotated sibling (".1") when
 *   that file is the one the cursor was reading.
 * - Bounded: at most EC_ANALYTICS_HTTP_5XX_MAX_BYTES read per tick; a backlog
 *   larger than that skips to the end rather than stalling the tick.
 * - First run starts at the end of the file: no backfill.
 * - The log path is filterable; an empty or unreadable path disables counting.
 *
 * Bucket math is shared with the REST 5xx counter (same shape and caps).
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

const EC_ANALYTICS_HTTP_5XX_OPTION        = 'extrachill_analytics_http_5xx';
const EC_ANALYTICS_HTTP_5XX_CURSOR_OPTION = 'extrachill_analytics_http_5xx_cursor';
const EC_ANALYTICS_HTTP_5XX_MAX_BYTES     = 64 * MB_IN_BYTES;

/**
 * Access log path to tail.
 *
 * @return string Absolute path, or '' when disabled.
 */
function extrachill_analytics_http_5xx_log_path() {
	/**
	 * Filter the web server access log the HTTP 5xx counter tails.
	 *
	 * The log must use the common/combined format (status follows the quoted
	 * request line). Return '' to disable.
	 *
	 * @param string $path Default '/var/log/nginx/access.log'.
	 */
	return (string) apply_filters( 'extrachill_analytics_http_5xx_log_path', '/var/log/nginx/access.log' );
}

/**
 * Classify a request path into a small, bounded set.
 *
 * @param string $path Request path (query string allowed).
 * @return string Path class.
 */
function extrachill_analytics_http_5xx_path_class( $path ) {
	$path = (string) strtok( (string) $path, '?' );
	$map  = array(
		'/wp-json/'       => 'wp-json',
		'/wp-admin/'      => 'wp-admin',
		'/wp-cron.php'    => 'wp-cron',
		'/wp-login.php'   => 'wp-login',
		'/xmlrpc.php'     => 'xmlrpc',
		'/wp-content/'    => 'static',
		'/wp-includes/'   => 'static',
		'/feed'           => 'feed',
		'/wp-sitemap'     => 'sitemap',
		'/sitemap'        => 'sitemap',
		'/?rest_route='   => 'wp-json',
		'/index.php/wp-j' => 'wp-json',
	);
	foreach ( $map as $prefix => $class ) {
		if ( 0 === strpos( $path, $prefix ) ) {
			return $class;
		}
	}
	return 'page';
}

/**
 * Parse one access log line.
 *
 * @param string $line Raw line.
 * @return array{status:int,time:int,class:string}|null Null when not a 5xx or unparseable.
 */
function extrachill_analytics_http_5xx_parse_line( $line ) {
	// Cheap reject before the regex: 5xx lines contain '" 5'.
	if ( false === strpos( $line, '" 5' ) ) {
		return null;
	}
	// Anchor on the protocol so a quote inside the path can't fake a status.
	if ( ! preg_match( '/\[([^\]]+)\] "[A-Z]+ (\S*) HTTP\/[0-9.]+" (5\d\d) /', $line, $m ) ) {
		return null;
	}
	$time = strtotime( str_replace( '/', ' ', preg_replace( '/:/', ' ', $m[1], 1 ) ) );
	if ( false === $time ) {
		return null;
	}
	return array(
		'status' => (int) $m[3],
		'time'   => (int) $time,
		'class'  => extrachill_analytics_http_5xx_path_class( $m[2] ),
	);
}

/**
 * Fold 5xx lines from a chunk of log text into buckets.
 *
 * @param array  $buckets Hour => ( "class|status" => count ).
 * @param string $text    Complete lines.
 * @return array Updated buckets.
 */
function extrachill_analytics_http_5xx_ingest_text( array $buckets, $text ) {
	foreach ( explode( "\n", (string) $text ) as $line ) {
		$hit = extrachill_analytics_http_5xx_parse_line( $line );
		if ( null !== $hit ) {
			$buckets = extrachill_analytics_rest_5xx_add( $buckets, $hit['class'], (string) $hit['status'], $hit['time'] );
		}
	}
	return $buckets;
}

/**
 * Read complete lines from $path starting at $offset, bounded.
 *
 * @param string $path   File.
 * @param int    $offset Start byte.
 * @param int    $max    Max bytes.
 * @return array{text:string,offset:int}|null New offset points after the last complete line.
 */
function extrachill_analytics_http_5xx_read_from( $path, $offset, $max ) {
	// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a local log file by offset.
	$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable logs disable counting.
	if ( false === $handle ) {
		return null;
	}
	fseek( $handle, $offset );
	$text = (string) fread( $handle, max( 1, $max ) );
	fclose( $handle );
	// phpcs:enable WordPress.WP.AlternativeFunctions
	$last = strrpos( $text, "\n" );
	if ( false === $last ) {
		return array(
			'text'   => '',
			'offset' => $offset,
		);
	}
	return array(
		'text'   => substr( $text, 0, $last ),
		'offset' => $offset + $last + 1,
	);
}

/**
 * A file's identity: its first line (up to 256 bytes). Access log lines start
 * with a client address and timestamp, so the first line identifies the file
 * across rename, even where inodes are unreliable.
 *
 * @param string $path File.
 * @return string Fingerprint, or '' for an empty/unreadable file.
 */
function extrachill_analytics_http_5xx_head( $path ) {
	$chunk = extrachill_analytics_http_5xx_read_from( $path, 0, 256 );
	return null === $chunk || '' === $chunk['text'] ? '' : md5( strtok( $chunk['text'], "\n" ) );
}

/**
 * Whether a file is the one the cursor was reading.
 *
 * @param array  $cursor Cursor.
 * @param string $path   File.
 * @param int    $inode  The file's inode.
 * @return bool
 */
function extrachill_analytics_http_5xx_is_cursor_file( array $cursor, $path, $inode ) {
	$head = (string) ( $cursor['head'] ?? '' );
	if ( '' !== $head ) {
		return extrachill_analytics_http_5xx_head( $path ) === $head;
	}
	return (int) $cursor['inode'] === (int) $inode;
}

/**
 * Advance the cursor over new access log lines and update the buckets.
 *
 * @param int|null $max_bytes Per-tick read budget (tests override).
 * @return array{status:string,bytes:int} Outcome for diagnostics.
 */
function extrachill_analytics_http_5xx_tail( $max_bytes = null ) {
	$path = extrachill_analytics_http_5xx_log_path();
	if ( '' === $path ) {
		return array(
			'status' => 'disabled',
			'bytes'  => 0,
		);
	}
	clearstatcache( true, $path );
	$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing log disables counting.
	if ( false === $stat || ! is_readable( $path ) ) {
		return array(
			'status' => 'unreadable',
			'bytes'  => 0,
		);
	}
	$max    = null === $max_bytes ? EC_ANALYTICS_HTTP_5XX_MAX_BYTES : max( 1, (int) $max_bytes );
	$cursor = get_site_option( EC_ANALYTICS_HTTP_5XX_CURSOR_OPTION, array() );
	$inode  = (int) $stat['ino'];
	$size   = (int) $stat['size'];

	if ( ! is_array( $cursor ) || ! isset( $cursor['inode'], $cursor['offset'] ) ) {
		update_site_option(
			EC_ANALYTICS_HTTP_5XX_CURSOR_OPTION,
			array(
				'inode'  => $inode,
				'offset' => $size,
				'head'   => extrachill_analytics_http_5xx_head( $path ),
			)
		);
		return array(
			'status' => 'initialized',
			'bytes'  => 0,
		);
	}

	$buckets = extrachill_analytics_http_5xx_buckets();
	$read    = 0;
	$offset  = (int) $cursor['offset'];

	if ( ! extrachill_analytics_http_5xx_is_cursor_file( $cursor, $path, $inode ) ) {
		// Rotated: finish the old file if it is now the ".1" sibling.
		$rotated = $path . '.1';
		clearstatcache( true, $rotated );
		$rstat = @stat( $rotated ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the sibling may not exist.
		if ( false !== $rstat && (int) $rstat['size'] > $offset && extrachill_analytics_http_5xx_is_cursor_file( $cursor, $rotated, (int) $rstat['ino'] ) ) {
			$chunk = extrachill_analytics_http_5xx_read_from( $rotated, $offset, $max );
			if ( null !== $chunk ) {
				$buckets = extrachill_analytics_http_5xx_ingest_text( $buckets, $chunk['text'] );
				$read   += $chunk['offset'] - $offset;
			}
		}
		$offset = 0;
	} elseif ( $size < $offset ) {
		$offset = 0; // Truncated in place.
	}

	$status = 'ok';
	if ( $size - $offset > $max - $read ) {
		// Too far behind to catch up in one tick: skip to the end, report it.
		$offset = $size;
		$status = 'skipped_backlog';
	} elseif ( $size > $offset ) {
		$chunk = extrachill_analytics_http_5xx_read_from( $path, $offset, $max - $read );
		if ( null !== $chunk ) {
			$buckets = extrachill_analytics_http_5xx_ingest_text( $buckets, $chunk['text'] );
			$read   += $chunk['offset'] - $offset;
			$offset  = $chunk['offset'];
		}
	}

	update_site_option( EC_ANALYTICS_HTTP_5XX_OPTION, $buckets );
	update_site_option(
		EC_ANALYTICS_HTTP_5XX_CURSOR_OPTION,
		array(
			'inode'  => $inode,
			'offset' => $offset,
			'head'   => extrachill_analytics_http_5xx_head( $path ),
		)
	);
	return array(
		'status' => $status,
		'bytes'  => $read,
	);
}

/**
 * Read stored buckets.
 *
 * @return array Hour => ( "class|status" => count ).
 */
function extrachill_analytics_http_5xx_buckets() {
	$value = get_site_option( EC_ANALYTICS_HTTP_5XX_OPTION, array() );
	return is_array( $value ) ? $value : array();
}

/**
 * Summarize HTTP 5xx buckets over a trailing window.
 *
 * Rows use the REST summary shape with `route` = path class and `code` = status.
 *
 * @param int $hours Trailing window.
 * @param int $now   Unix time.
 * @param int $limit Max rows; 0 for all.
 * @return array Summary.
 */
function extrachill_analytics_http_5xx_summarize( $hours, $now, $limit = 10 ) {
	$summary           = extrachill_analytics_rest_5xx_summarize( extrachill_analytics_http_5xx_buckets(), $hours, $now, $limit );
	$summary['source'] = 'access_log:' . extrachill_analytics_http_5xx_log_path();
	$summary['note']   = 'Counts every response with status >= 500 in the web server access log, by path class and status: gateway errors (502/504) the web server produced itself, plus 5xx WordPress returned. Counting starts when the cursor initializes; there is no backfill.';
	return $summary;
}

/**
 * Gateway statuses that page. WordPress-produced 500s already page through
 * the PHP fatal and REST 5xx alarms; 503 is the web server's rate-limit answer.
 *
 * @return int[] Statuses.
 */
function extrachill_analytics_http_5xx_alarm_statuses() {
	/**
	 * Filter which HTTP statuses from the access log can page.
	 *
	 * @param int[] $statuses Default 502 and 504.
	 */
	return array_map( 'intval', (array) apply_filters( 'extrachill_analytics_http_5xx_alarm_statuses', array( 502, 504 ) ) );
}

/**
 * Sustained gateway error threshold (responses per hour, all path classes).
 *
 * @return int Threshold.
 */
function extrachill_analytics_http_5xx_alarm_threshold() {
	/**
	 * Filter the sustained gateway error alarm threshold (per hour, per status).
	 *
	 * @param int $threshold Default 30.
	 */
	return max( 1, (int) apply_filters( 'extrachill_analytics_http_5xx_alarm_threshold', 30 ) );
}

/**
 * Build alarm candidates for sustained gateway errors.
 *
 * Counts are summed per status across path classes (saturation hits every
 * path). A status is sustained when the last complete hour reached the
 * threshold and it is still firing in the current hour. Dedupe follows the
 * REST rules: new, returned after quiet, or escalated (rate doubled).
 *
 * @param array $state  Alarm dedupe state keyed by signature.
 * @param int   $now    Unix time.
 * @param int   $window Alarm window seconds (re-arm horizon is 2 windows).
 * @return array<int, array<string, mixed>> Candidate rows.
 */
function extrachill_analytics_http_5xx_alarm_candidates( array $state, $now, $window ) {
	$buckets   = extrachill_analytics_http_5xx_buckets();
	$current   = (int) ( floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
	$previous  = $current - HOUR_IN_SECONDS;
	$statuses  = extrachill_analytics_http_5xx_alarm_statuses();
	$threshold = extrachill_analytics_http_5xx_alarm_threshold();
	$sum       = static function ( $slot ) use ( $statuses ) {
		$out = array();
		foreach ( is_array( $slot ) ? $slot : array() as $key => $count ) {
			$parts  = explode( '|', (string) $key );
			$status = (int) array_pop( $parts );
			if ( in_array( $status, $statuses, true ) ) {
				$out[ $status ]['total']                             = (int) ( $out[ $status ]['total'] ?? 0 ) + (int) $count;
				$out[ $status ]['classes'][ implode( '|', $parts ) ] = (int) $count;
			}
		}
		return $out;
	};
	$prev      = $sum( $buckets[ $previous ] ?? array() );
	$curr      = $sum( $buckets[ $current ] ?? array() );
	$rows      = array();

	foreach ( $prev as $status => $agg ) {
		$prev_count = (int) $agg['total'];
		if ( $prev_count < $threshold ) {
			continue;
		}
		$curr_count = (int) ( $curr[ $status ]['total'] ?? 0 );
		$classes    = $agg['classes'];
		arsort( $classes );
		$breakdown = array();
		foreach ( array_slice( $classes, 0, 4, true ) as $class => $count ) {
			$breakdown[] = $class . ' ' . $count;
		}
		$signature = 'http_5xx:' . $status;
		$is_live   = $curr_count > 0;
		$prior     = isset( $state[ $signature ] ) && is_array( $state[ $signature ] ) ? $state[ $signature ] : null;
		$is_new    = null === $prior;
		$rearmed   = ! $is_new && ( $now - (int) ( $prior['last_alerted_at'] ?? 0 ) ) > ( 2 * $window );
		$escalated = ! $is_new && $prev_count > ( (int) ( $prior['last_alerted_rate'] ?? 0 ) * 2 );
		$reasons   = array( 'sustained_http_5xx' );
		if ( $rearmed ) {
			$reasons[] = 'returned_after_quiet';
		}
		if ( $escalated ) {
			$reasons[] = 'escalated';
		}
		if ( ! $is_live ) {
			$reasons = array( 'resolved_not_firing' );
		}
		$rows[] = array(
			'kind'        => 'http_5xx',
			'signature'   => $signature,
			'severity'    => 'http_5xx',
			'file'        => extrachill_analytics_http_5xx_log_path(),
			'sample'      => sprintf( 'HTTP %d from the web server: %d in the last full hour (%s)', $status, $prev_count, implode( ', ', $breakdown ) ),
			'count'       => $prev_count + $curr_count,
			'rate_per_hr' => $prev_count,
			'first_seen'  => gmdate( 'Y-m-d H:i:s', $previous ),
			'last_seen'   => gmdate( 'Y-m-d H:i:s', $now ),
			'is_new'      => $is_new,
			'is_live'     => $is_live,
			'reasons'     => $reasons,
			'would_alarm' => $is_live && ( $is_new || $rearmed || $escalated ),
		);
	}
	return $rows;
}
