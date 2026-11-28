<?php
/**
 * GC accounting for the flat, per-blog page-cache partitions.
 *
 * @package ExtraChillCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the payload byte budget for a partition without switching sites.
 *
 * @param int $blog_id Partition ID (0 for the shared root partition).
 * @return int Budget in bytes.
 */
function extrachill_cache_max_bytes( $blog_id ) {
	$default = defined( 'EXTRACHILL_CACHE_MAX_BYTES' ) ? EXTRACHILL_CACHE_MAX_BYTES : 2 * 1024 ** 3;

	/**
	 * Filters the cache budget in bytes for a blog partition.
	 *
	 * @param int $default Configured default budget.
	 * @param int $blog_id Partition ID, not necessarily the current site.
	 */
	return max( 0, (int) apply_filters( 'extrachill_cache_max_bytes', (int) $default, $blog_id ) );
}

/**
 * Discover the existing partitions. Root-level files are blog 0, not a total.
 *
 * @return iterable<int,string> Partition directories.
 */
function extrachill_cache_partitions() {
	$base = extrachill_cache_base_dir();
	if ( ! is_dir( $base ) ) {
		return;
	}

	yield 0 => $base;
	try {
		foreach ( new FilesystemIterator( $base ) as $entry ) {
			$name = $entry->getFilename();
			if ( preg_match( '/^[1-9][0-9]*$/D', $name ) && ! $entry->isLink() && $entry->isDir() ) {
				yield (int) $name => $entry->getPathname();
			}
		}
	} catch ( UnexpectedValueException $e ) {
		// A concurrent full purge may remove the directory before we open it.
		return;
	}
}

/**
 * Measure one partition and optionally trim it in mtime order.
 *
 * Use a flat, metadata-only scan and keep only filename-to-mtime pairs for
 * eviction. No payload reads, recursive stats, or persistent counters to drift
 * when a reader, CLI, or content hook purges a file. This is a snapshot, not a
 * write-time quota; concurrent changes are reconciled in the next GC pass.
 *
 * @param string            $dir         Partition directory.
 * @param int               $blog_id     Partition ID.
 * @param bool              $trim        Whether to enforce the budget.
 * @param bool              $dry_run     Simulate deletions.
 * @param array<string,bool> $ttl_deleted Paths already counted by TTL GC in dry runs.
 * @return array Partition counters after trimming (projected in dry runs).
 */
function extrachill_cache_measure_partition( $dir, $blog_id, $trim = false, $dry_run = false, $ttl_deleted = array() ) {
	$usage  = array(
		'files'     => 0,
		'bytes'     => 0,
		'max_bytes' => extrachill_cache_max_bytes( $blog_id ),
		'deleted'   => 0,
		'reclaimed' => 0,
	);
	$mtimes = array();
	try {
		$entries = new FilesystemIterator( $dir );
	} catch ( UnexpectedValueException $e ) {
		// Empty or concurrently purged partition.
		return $usage;
	}

	foreach ( $entries as $entry ) {
		$name = $entry->getFilename();
		$path = $entry->getPathname();
		if ( ! str_ends_with( $name, '.html' ) || isset( $ttl_deleted[ $path ] ) ) {
			continue;
		}

		$stat = extrachill_cache_payload_stat( $path );
		if ( false === $stat ) {
			continue;
		}

		++$usage['files'];
		$usage['bytes'] += $stat['size'];
		if ( $trim ) {
			$mtimes[ $name ] = $stat['mtime'];
		}
	}

	if ( ! $trim || $usage['bytes'] <= $usage['max_bytes'] ) {
		return $usage;
	}

	asort( $mtimes, SORT_NUMERIC );
	foreach ( $mtimes as $name => $mtime ) {
		$path = $dir . '/' . $name;
		$stat = extrachill_cache_payload_stat( $path );
		if ( false === $stat || $mtime !== $stat['mtime'] ) {
			// Skip files that disappeared or whose mtime changed since the scan.
			continue;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a local cache file; failure leaves it in the accounting.
		if ( ! $dry_run && ! @unlink( $path ) ) {
			continue;
		}

		--$usage['files'];
		++$usage['deleted'];
		$usage['bytes']     -= $stat['size'];
		$usage['reclaimed'] += $stat['size'];
		if ( $usage['bytes'] <= $usage['max_bytes'] ) {
			break;
		}
	}

	return $usage;
}

/**
 * Get metadata for a local regular payload file without following symlinks.
 *
 * @param string $path Cache file path.
 * @return array|false File metadata, or false if absent or not a regular file.
 */
function extrachill_cache_payload_stat( $path ) {
	clearstatcache( false, $path );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent purge may delete a file while it is being measured.
	$stat = @lstat( $path );
	return false !== $stat && 0100000 === ( $stat['mode'] & 0170000 ) ? $stat : false;
}
