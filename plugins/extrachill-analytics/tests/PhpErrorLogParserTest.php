<?php
/**
 * Tests for the PHP error-log parser byte-watermark semantics (issue #128).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

// The whole class exercises direct filesystem calls against temp fixtures, so
// the WP_Filesystem alternative-function sniff is disabled for it.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * The live tail must start at the snapshot byte watermark so persisted bytes are
 * not re-read and double counted. start_offset is the mechanism; this proves it
 * skips already-snapshotted bytes while preserving the first entry at a line
 * boundary.
 */
final class PhpErrorLogParserTest extends TestCase {

	/**
	 * Build a small debug.log fixture with three timestamped fatal entries.
	 *
	 * @return array{path:string, entries:array<int,string>} Log path and the raw entry strings.
	 */
	private function make_log() {
		$entries = array(
			'[10-Jul-2026 01:00:00 UTC] PHP Fatal error:  Old spike number one in /var/www/wp-content/plugins/foo/a.php on line 1',
			'[10-Jul-2026 02:00:00 UTC] PHP Fatal error:  Old spike number two in /var/www/wp-content/plugins/foo/a.php on line 1',
			'[13-Jul-2026 12:00:00 UTC] PHP Fatal error:  Recent occurrence in /var/www/wp-content/plugins/foo/b.php on line 2',
		);

		$path = tempnam( sys_get_temp_dir(), 'ec_err_' );
		file_put_contents( $path, implode( "\n", $entries ) . "\n" );

		return array(
			'path'    => $path,
			'entries' => $entries,
		);
	}

	/**
	 * Without a watermark the whole file parses.
	 */
	public function test_full_read_returns_all_entries(): void {
		$log = $this->make_log();

		try {
			$parsed = extrachill_analytics_parse_php_error_log( $log['path'], null, 0, 0 );
			$this->assertCount( 3, $parsed['entries'] );
		} finally {
			@unlink( $log['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * The byte watermark (start_offset) lands on a line boundary after a snapshot
	 * pass. Reading from it must return ONLY the not-yet-snapshotted entries —
	 * the persisted half is excluded so it is never double counted. The entry at
	 * the boundary must NOT be dropped.
	 */
	public function test_start_offset_at_line_boundary_skips_snapshotted_bytes(): void {
		$log     = $this->make_log();
		$entries = $log['entries'];
		$path    = $log['path'];

		// The watermark is the byte offset where the third entry begins — exactly
		// where ftell lands after a snapshot consumed the first two full lines.
		$marker = 'PHP Fatal error:  Recent occurrence';
		$offset = strpos( (string) file_get_contents( $path ), $marker );
		// Back up to the '[' that opens the third entry (the line boundary).
		$boundary = strrpos( substr( (string) file_get_contents( $path ), 0, $offset ), '[' );

		try {
			$parsed = extrachill_analytics_parse_php_error_log( $path, null, 0, $boundary );
			$this->assertCount( 1, $parsed['entries'], 'Only the unsnapshotted (live) entry must be returned.' );
			$this->assertStringContainsString( 'Recent occurrence', $parsed['entries'][0]['sample'] );
		} finally {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * A start_offset landing mid-line discards the partial fragment (the
	 * tail-memory-cap path) and never splits a single entry in two.
	 */
	public function test_start_offset_mid_line_discards_partial_fragment(): void {
		$log     = $this->make_log();
		$path    = $log['path'];
		$content = (string) file_get_contents( $path );

		// Seek a few bytes into the third entry's headline (mid-line).
		$marker = 'Recent occurrence';
		$mid    = strpos( $content, $marker ) + 2;

		try {
			$parsed = extrachill_analytics_parse_php_error_log( $path, null, 0, $mid );
			// The partial third entry is dropped; nothing usable remains.
			$this->assertCount( 0, $parsed['entries'] );
		} finally {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * The since_ts lower bound filters out occurrences older than the window even
	 * when they are physically present in the read range.
	 */
	public function test_since_ts_filters_old_entries(): void {
		$log = $this->make_log();

		try {
			// A timestamp inside the "recent" entry's day but before it excludes
			// the two old entries and keeps only the recent one.
			$cutoff = strtotime( '13-Jul-2026 11:00:00 UTC' );
			$parsed = extrachill_analytics_parse_php_error_log( $log['path'], $cutoff, 0, 0 );
			$this->assertCount( 1, $parsed['entries'] );
			$this->assertStringContainsString( 'Recent occurrence', $parsed['entries'][0]['sample'] );
		} finally {
			@unlink( $log['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
// phpcs:enable WordPress.WP.AlternativeFunctions
