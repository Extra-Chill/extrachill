<?php
/**
 * Revenue ledger store for the ingestion engine.
 *
 * Implements the storage contract `extrachill_analytics_revenue_ingest_rows()`
 * drives (get_snapshot / upsert / delete_ids / begin / commit / rollback /
 * lock / unlock) on top of the Data Machine Business revenue ledger. Extra
 * Chill no longer writes its own table: upserts and deletes are buffered for
 * the open snapshot and `commit()` writes the final snapshot in ONE
 * `datamachine/revenue-ingest` call, whose (source, account, kind, window)
 * replace scope is exactly the Extra Chill snapshot (see revenue-ledger.php).
 * The ledger write is itself atomic, so a failure before commit leaves the
 * stored snapshot untouched and rollback only discards the buffer.
 *
 * Tests inject an in-memory fake with the same contract.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ledger-backed revenue store.
 */
final class Extrachill_Analytics_Revenue_Store {

	/**
	 * Ingestion mode (replace|additive).
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Ledger source slug.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Rows read for the open snapshot, keyed by ledger id.
	 *
	 * @var array<int, \stdClass>
	 */
	private $existing = array();

	/**
	 * Whether the snapshot read was truncated by the query row cap.
	 *
	 * @var bool
	 */
	private $truncated = false;

	/**
	 * Buffered upserts keyed by slug.
	 *
	 * @var array<string, array>
	 */
	private $pending = array();

	/**
	 * Ledger ids buffered for deletion.
	 *
	 * @var array<int, bool>
	 */
	private $deleted = array();

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Constructor.
	 *
	 * @param string $mode   Ingestion mode.
	 * @param string $source Revenue source slug (ledger source).
	 */
	public function __construct( $mode = 'replace', $source = 'mediavine' ) {
		$this->mode   = 'additive' === $mode ? 'additive' : 'replace';
		$source       = strtolower( preg_replace( '/[^a-z0-9_.-]/i', '', (string) $source ) );
		$this->source = '' !== $source ? substr( $source, 0, 64 ) : 'mediavine';
	}

	/**
	 * Read the exact snapshot from the ledger.
	 *
	 * @param int    $blog_id      Blog ID.
	 * @param string $period_label Period label.
	 * @param string $import_batch Snapshot identity.
	 * @return array<int, \stdClass> Rows (each with ->id and ->slug).
	 */
	public function get_snapshot( $blog_id, $period_label, $import_batch ) {
		$rows = extrachill_analytics_revenue_get_rows(
			array(
				'blog_id'               => $blog_id,
				'period_label'          => $period_label,
				'import_batch'          => $import_batch,
				'restrict_import_batch' => true,
			)
		);

		$status          = extrachill_analytics_revenue_ledger_read_status();
		$this->truncated = ! empty( $status['truncated_snapshots'] );
		$this->existing  = array();
		foreach ( $rows as $row ) {
			$this->existing[ (int) $row->id ] = $row;
		}

		return $rows;
	}

	/**
	 * Buffer one snapshot row.
	 *
	 * @param array $row Normalized record.
	 * @return bool
	 */
	public function upsert( array $row ) {
		$this->pending[ isset( $row['slug'] ) ? (string) $row['slug'] : '' ] = $row;
		return true;
	}

	/**
	 * Buffer stale-row removal (the ledger replace drops absent rows).
	 *
	 * @param array<int> $ids Ledger row ids.
	 * @return bool
	 */
	public function delete_ids( array $ids ) {
		foreach ( $ids as $id ) {
			$this->deleted[ (int) $id ] = true;
		}
		return true;
	}

	/**
	 * Acquire the per-period advisory lock.
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds.
	 * @return bool
	 */
	public function lock( $name, $timeout = 10 ) {
		return extrachill_analytics_revenue_lock_acquire( $name, $timeout );
	}

	/**
	 * Release the advisory lock.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public function unlock( $name ) {
		return extrachill_analytics_revenue_lock_release( $name );
	}

	/**
	 * Open a buffered snapshot write.
	 *
	 * @return bool
	 */
	public function begin() {
		$this->pending    = array();
		$this->deleted    = array();
		$this->last_error = '';
		return true;
	}

	/**
	 * Write the final snapshot through datamachine/revenue-ingest.
	 *
	 * @return bool
	 */
	public function commit() {
		if ( empty( $this->pending ) ) {
			$this->last_error = 'No revenue rows to write.';
			return false;
		}

		$final = array();
		foreach ( $this->existing as $id => $row ) {
			if ( ! isset( $this->deleted[ $id ] ) ) {
				$final[ (string) $row->slug ] = (array) $row;
			}
		}
		if ( $this->truncated && 'replace' !== $this->mode ) {
			$this->last_error = 'The existing ledger snapshot exceeds the revenue-query row cap, so an additive merge cannot be verified (fail closed).';
			return false;
		}
		foreach ( $this->pending as $slug => $row ) {
			$final[ $slug ] = $row;
		}

		$first = reset( $this->pending );
		$start = isset( $first['period_start'] ) ? (string) $first['period_start'] : '';
		$end   = isset( $first['period_end'] ) ? (string) $first['period_end'] : '';
		if ( ! extrachill_analytics_revenue_is_valid_date( $start ) || ! extrachill_analytics_revenue_is_valid_date( $end ) ) {
			$this->last_error = 'The revenue ledger requires a dated period (YYYY-MM, YYYY, or explicit period_start/period_end); undated lifetime snapshots cannot be stored.';
			return false;
		}

		$rows = array();
		foreach ( $final as $record ) {
			$rows[] = extrachill_analytics_revenue_ledger_row_from_record( $record );
		}

		$batch  = isset( $first['import_batch'] ) ? (string) $first['import_batch'] : '';
		$result = extrachill_analytics_revenue_ledger_execute(
			EXTRACHILL_ANALYTICS_REVENUE_LEDGER_INGEST_ABILITY,
			array(
				'source'       => $this->source,
				'account'      => extrachill_analytics_revenue_ledger_account( isset( $first['blog_id'] ) ? (int) $first['blog_id'] : get_current_blog_id(), $batch ),
				'start_date'   => $start,
				'end_date'     => $end,
				'kinds'        => array( EXTRACHILL_ANALYTICS_REVENUE_LEDGER_KIND ),
				'rows'         => $rows,
				'import_batch' => '' !== $batch ? substr( $batch, 0, 64 ) : 'extrachill-revenue',
				'dry_run'      => false,
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->last_error = $result->get_error_message();
			return false;
		}

		$this->pending = array();
		$this->deleted = array();
		return true;
	}

	/**
	 * Discard the buffered snapshot (nothing was written).
	 *
	 * @return bool
	 */
	public function rollback() {
		$this->pending = array();
		$this->deleted = array();
		return true;
	}

	/**
	 * Last error message from commit().
	 *
	 * @return string
	 */
	public function last_error() {
		return $this->last_error;
	}
}
