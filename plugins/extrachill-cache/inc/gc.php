<?php
/**
 * Garbage collection for the page cache.
 *
 * The read path (extrachill_cache_read) evicts stale files one at a time, but
 * the long tail of crawler-hit URLs, one-off query-string variants, and quiet
 * archive pages would otherwise accumulate forever. This module adds a
 * batched/time-budgeted scheduled GC pass that walks the cache tree and removes
 * files older than EXTRACHILL_CACHE_TTL.
 *
 * Scheduled once network-wide on the main site; the walker touches every blog
 * partition, so per-site cron jobs are unnecessary.
 *
 * @package ExtraChillCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EXTRACHILL_CACHE_GC_EVENT' ) ) {
	define( 'EXTRACHILL_CACHE_GC_EVENT', 'extrachill_cache_gc' );
}

add_action( 'wp', 'extrachill_cache_schedule_gc' );

/**
 * Ensure the network-wide GC cron event is scheduled exactly once.
 *
 * Network-activated on multisite, so we only schedule on the main site. The
 * walker itself cleans all blog partitions, and a single hourly pass is enough
 * to keep up with steady-state expiration.
 *
 * @return void
 */
function extrachill_cache_schedule_gc() {
	if ( ! is_main_site() ) {
		return;
	}

	if ( false !== wp_next_scheduled( EXTRACHILL_CACHE_GC_EVENT ) ) {
		return;
	}

	wp_schedule_event( time(), 'hourly', EXTRACHILL_CACHE_GC_EVENT );
}

/**
 * Clear the GC cron event.
 *
 * Called on deactivation after the cache tree has already been purged.
 *
 * @return void
 */
function extrachill_cache_unschedule_gc() {
	$timestamp = wp_next_scheduled( EXTRACHILL_CACHE_GC_EVENT );
	if ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, EXTRACHILL_CACHE_GC_EVENT );
	}
}

add_action( EXTRACHILL_CACHE_GC_EVENT, 'extrachill_cache_run_gc' );

/**
 * Cron callback: run a single batched GC pass.
 *
 * Logs the result to the WordPress debug log when WP_DEBUG_LOG is enabled.
 *
 * @return void
 */
function extrachill_cache_run_gc() {
	$stats = extrachill_cache_gc();

	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational GC telemetry; only emitted when debug logging is explicitly enabled.
			sprintf(
				'Extra Chill Cache GC: examined=%d deleted=%d bytes=%d completed=%s',
				$stats['examined'],
				$stats['deleted'],
				$stats['bytes'],
				$stats['completed'] ? 'yes' : 'no'
			)
		);
	}
}

/**
 * Walk the cache tree and delete expired files.
 *
 * The walk is bounded by a file-count limit AND a time budget so a 150k-file
 * production cache cannot block a cron run. A cursor option records the last
 * path examined; the next run resumes from that point and clears the cursor
 * when the walk wraps. Files deleted during the pass are tracked so their
 * parent directories can be removed if empty.
 *
 * @param array $args {
 *     Optional. GC runtime arguments.
 *
 *     @type bool $dry_run     If true, count what would be deleted but do not unlink.
 *     @type int  $file_limit  Max files to examine this run. Default 2000.
 *     @type int  $time_budget Max seconds to spend walking. Default 30.
 * }
 * @return array {
 *     GC result counters.
 *
 *     @type int  $examined  Files examined.
 *     @type int  $deleted   Files unlinked.
 *     @type int  $bytes     Bytes reclaimed (or that would be reclaimed in dry-run).
 *     @type bool $completed True if the walk reached the end of the tree.
 * }
 */
function extrachill_cache_gc( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'dry_run'     => false,
			'file_limit'  => defined( 'EXTRACHILL_CACHE_GC_FILE_LIMIT' ) ? (int) EXTRACHILL_CACHE_GC_FILE_LIMIT : 2000,
			'time_budget' => defined( 'EXTRACHILL_CACHE_GC_TIME_BUDGET' ) ? (int) EXTRACHILL_CACHE_GC_TIME_BUDGET : 30,
		)
	);

	$base = extrachill_cache_base_dir();

	$stats = array(
		'examined'  => 0,
		'deleted'   => 0,
		'bytes'     => 0,
		'completed' => false,
	);

	if ( ! is_dir( $base ) ) {
		return $stats;
	}

	$ttl    = defined( 'EXTRACHILL_CACHE_TTL' ) ? (int) EXTRACHILL_CACHE_TTL : DAY_IN_SECONDS;
	$cutoff = time() - $ttl;

	$cursor       = (string) get_option( 'extrachill_cache_gc_cursor', '' );
	$start        = microtime( true );
	$examined     = 0;
	$deleted      = 0;
	$bytes        = 0;
	$emptied_dirs = array();
	$next_cursor      = '';
	$started          = false;
	$budget_exhausted = false;

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ( $iterator as $file ) {
		$path = $file->getPathname();

		// Resume from the previous cursor, if any. The cursor is always set to
		// a KEPT file (never a deleted one), so it normally still exists on the
		// next run. If it was removed by a content-change purge the walk
		// exhausts without examining anything and the cursor is cleared below.
		if ( '' !== $cursor && ! $started ) {
			if ( $path === $cursor ) {
				$started = true;
			}
			continue;
		}

		++$examined;

		// Only consider regular cache payload files (*.html). Anything else in
		// the tree (tmp files, stray uploads) is ignored.
		if ( ! $file->isFile() || '.html' !== substr( $path, -5 ) ) {
			$next_cursor = $path;
			if ( extrachill_cache_gc_should_stop( $start, $examined, $args ) ) {
				$budget_exhausted = true;
				break;
			}
			continue;
		}

		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Local cache file mtime read during GC; failure is handled via the false check.
		if ( false !== $mtime && $mtime >= $cutoff ) {
			$next_cursor = $path;
			if ( extrachill_cache_gc_should_stop( $start, $examined, $args ) ) {
				$budget_exhausted = true;
				break;
			}
			continue;
		}

		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Local cache file size read during GC; failure is handled via the false check.
		if ( false === $size ) {
			$size = 0;
		}

		if ( ! $args['dry_run'] ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes an expired local cache file during GC; @ guards a benign unlink failure.
			if ( @unlink( $path ) ) {
				++$deleted;
				$bytes                           += $size;
				$emptied_dirs[ $file->getPath() ] = true;
			}
		} else {
			++$deleted;
			$bytes += $size;
		}

		// Do NOT advance $next_cursor to a deleted file: it won't exist on the
		// next run, so it can't serve as a resume point. $next_cursor retains
		// the last KEPT file's path (fresh .html or non-cache file), which is
		// stable across runs. If the entire batch was expired (no kept file
		// seen), $next_cursor stays '' and the cursor is cleared below so the
		// next run starts fresh — at most one no-op run, self-correcting.
		if ( extrachill_cache_gc_should_stop( $start, $examined, $args ) ) {
			$budget_exhausted = true;
			break;
		}
	}

	// The walk completed if the iterator was exhausted naturally (no budget
	// break). A stale cursor (cursor file purged between runs) causes the
	// iterator to exhaust without examining anything; that clears the cursor
	// so the next run starts fresh — one no-op run, self-correcting.
	$completed = ! $budget_exhausted;

	if ( ! $args['dry_run'] ) {
		extrachill_cache_gc_remove_empty_dirs( array_keys( $emptied_dirs ) );

		if ( $completed || '' === $next_cursor ) {
			// Walk finished, or the batch was all-expired with no kept file to
			// anchor a resume point. Clear the cursor so the next run starts
			// from the beginning of the tree.
			delete_option( 'extrachill_cache_gc_cursor' );
		} else {
			// Resume from the last kept file on the next run.
			update_option( 'extrachill_cache_gc_cursor', $next_cursor, false );
		}
	}

	return array(
		'examined'  => $examined,
		'deleted'   => $deleted,
		'bytes'     => $bytes,
		'completed' => $completed,
	);
}

/**
 * Decide whether the current GC pass has exhausted its budget.
 *
 * @param float $start     microtime(true) when the walk began.
 * @param int   $examined  Files examined so far.
 * @param array $args      GC arguments.
 * @return bool True if the walk should stop.
 */
function extrachill_cache_gc_should_stop( $start, $examined, $args ) {
	if ( $examined >= $args['file_limit'] ) {
		return true;
	}

	if ( ( microtime( true ) - $start ) >= $args['time_budget'] ) {
		return true;
	}

	return false;
}

/**
 * Attempt to remove directories that may have been emptied by GC.
 *
 * Silently ignores non-empty or non-existent directories. Only acts on
 * per-blog subdirectories under the cache root, never the root itself.
 *
 * @param string[] $dirs Candidate directory paths.
 * @return void
 */
function extrachill_cache_gc_remove_empty_dirs( $dirs ) {
	$base = extrachill_cache_base_dir();

	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		// Never remove the cache root.
		if ( rtrim( $dir, '/\\' ) === $base ) {
			continue;
		}

		// Safety: only remove numeric per-blog directories immediately under the root.
		$relative = trim( str_replace( $base, '', $dir ), '/\\' );
		if ( ! is_numeric( $relative ) ) {
			continue;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an emptied per-blog cache directory during GC; @ guards a benign rmdir failure (non-empty dir or concurrent writer).
		@rmdir( $dir );
	}
}
