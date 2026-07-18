<?php
/**
 * Tests for historical source URL redaction.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/route-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/source-url-backfill.php';

/**
 * Verify the privacy backfill is bounded and dry-run by default.
 */
final class SourceUrlBackfillTest extends TestCase {
	/**
	 * Historical values are canonicalized without writes during a dry run.
	 */
	public function test_backfill_dry_run_counts_redactions_without_writing(): void {
		global $wpdb;

		$wpdb = new class() {
			/**
			 * Stored source fixtures.
			 *
			 * @var array<int,object>
			 */
			public $rows;

			/**
			 * Captured update calls.
			 *
			 * @var array<int,array>
			 */
			public $updates = array();

			/**
			 * Seed source URL fixtures.
			 */
			public function __construct() {
				$this->rows = array(
					(object) array(
						'id'         => 1,
						'source_url' => 'https://extrachill.com/login/?token=fixture#form',
					),
					(object) array(
						'id'         => 2,
						'source_url' => 'https://extrachill.com/story/',
					),
					(object) array(
						'id'         => 3,
						'source_url' => 'not a URL',
					),
				);
			}

			/**
			 * Capture prepared query arguments.
			 *
			 * @param string $sql  Query template.
			 * @param mixed  ...$args Query arguments.
			 * @return array Prepared query fixture.
			 */
			public function prepare( $sql, ...$args ) {
				return array( $sql, $args );
			}

			/**
			 * Return a cursor-paginated fixture batch.
			 *
			 * @param array $query Prepared query fixture.
			 * @return array<object> Matching rows.
			 */
			public function get_results( $query ) {
				$last_id = (int) $query[1][0];
				$limit   = (int) $query[1][1];
				$rows    = array_filter(
					$this->rows,
					static function ( $row ) use ( $last_id ) {
						return $row->id > $last_id;
					}
				);
				return array_slice( array_values( $rows ), 0, $limit );
			}

			/**
			 * Capture an update call.
			 *
			 * @param mixed ...$args Update arguments.
			 * @return int Updated row count.
			 */
			public function update( ...$args ) {
				$this->updates[] = $args;
				return 1;
			}
		};

		$result = extrachill_analytics_redact_source_urls( array( 'batch_size' => 2 ) );

		$this->assertSame( 3, $result['scanned'] );
		$this->assertSame( 2, $result['redacted'] );
		$this->assertSame( 1, $result['invalid'] );
		$this->assertSame( array(), $wpdb->updates );
	}
}
