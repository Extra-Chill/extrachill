<?php
/**
 * In-memory Data Machine Business revenue ledger for analytics tests.
 *
 * Mirrors the contracts of datamachine/revenue-ingest (replace every row of
 * (source, account, kind) contained in [start_date, end_date]; rows must lie
 * inside the window; dry_run writes nothing) and datamachine/revenue-query
 * (contained-row totals, coverage per source/account, rows paged by
 * limit + offset with counts.matched_rows / returned_rows / offset).
 * Set $ignore_offset to emulate a provider without offset support.
 * Installed through the `extrachill_analytics_revenue_ledger_ability` filter.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-fake-revenue-ledger-ability.php';

/**
 * In-memory revenue ledger.
 */
final class Fake_Revenue_Ledger {

	/**
	 * Stored rows (hydrated public shape).
	 *
	 * @var array<int, array>
	 */
	public $rows = array();

	/**
	 * Recorded calls: array( ability, input ).
	 *
	 * @var array<int, array>
	 */
	public $calls = array();

	/**
	 * When true, query() ignores `offset` and omits counts.offset (pre-offset provider).
	 *
	 * @var bool
	 */
	public $ignore_offset = false;

	/**
	 * With $ignore_offset, still echo the requested offset in counts.offset
	 * (a provider that claims offset support but always returns page one).
	 *
	 * @var bool
	 */
	public $echo_ignored_offset = false;

	/**
	 * Next id.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Filter callback reference.
	 *
	 * @var callable|null
	 */
	private $filter = null;

	/**
	 * Install as the ledger ability provider.
	 */
	public function install(): void {
		$this->filter = function ( $ability, $name ) {
			if ( 'datamachine/revenue-ingest' === $name ) {
				return new Fake_Revenue_Ledger_Ability( array( $this, 'ingest' ) );
			}
			if ( 'datamachine/revenue-query' === $name ) {
				return new Fake_Revenue_Ledger_Ability( array( $this, 'query' ) );
			}
			return $ability;
		};
		add_filter( 'extrachill_analytics_revenue_ledger_ability', $this->filter, 10, 2 );
	}

	/**
	 * Remove the filter.
	 */
	public function uninstall(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'extrachill_analytics_revenue_ledger_ability', $this->filter, 10 );
		}
	}

	/**
	 * Revenue-ingest double.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function ingest( array $input ) {
		$this->calls[] = array( 'datamachine/revenue-ingest', $input );
		$source        = (string) ( $input['source'] ?? '' );
		$account       = trim( (string) ( $input['account'] ?? '' ) );
		$start         = (string) ( $input['start_date'] ?? '' );
		$end           = (string) ( $input['end_date'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_.-]{0,63}$/', $source ) || '' === $start || '' === $end || $start > $end ) {
			return new WP_Error( 'revenue_invalid', 'invalid source or window' );
		}
		$normalized = array();
		$kinds      = array();
		foreach ( (array) ( $input['rows'] ?? array() ) as $row ) {
			$period_start = '' !== (string) ( $row['period_start'] ?? '' ) ? (string) $row['period_start'] : $start;
			$period_end   = '' !== (string) ( $row['period_end'] ?? '' ) ? (string) $row['period_end'] : $end;
			if ( $period_start < $start || $period_end > $end ) {
				return new WP_Error( 'revenue_row_outside_window', 'row outside window' );
			}
			if ( ! is_string( $row['amount'] ?? null ) && ! is_numeric( $row['amount'] ?? null ) ) {
				return new WP_Error( 'revenue_invalid_amount', 'bad amount' );
			}
			$kinds[ $row['kind'] ] = true;
			$normalized[]          = array(
				'source'       => $source,
				'account'      => $account,
				'kind'         => (string) $row['kind'],
				'period_start' => $period_start,
				'period_end'   => $period_end,
				'grain'        => 'month',
				'amount'       => number_format( (float) $row['amount'], 6, '.', '' ),
				'currency'     => strtoupper( (string) $row['currency'] ),
				'url'          => isset( $row['url'] ) && '' !== (string) $row['url'] ? (string) $row['url'] : null,
				'object_ref'   => isset( $row['object_ref'] ) && '' !== (string) $row['object_ref'] ? (string) $row['object_ref'] : null,
				'blog_id'      => isset( $row['blog_id'] ) && null !== $row['blog_id'] ? (int) $row['blog_id'] : null,
				'metrics'      => isset( $row['metrics'] ) && is_array( $row['metrics'] ) ? json_decode( wp_json_encode( $row['metrics'], JSON_PRESERVE_ZERO_FRACTION ), true ) : null,
				'import_batch' => (string) ( $input['import_batch'] ?? 'batch' ),
				'imported_at'  => '2026-07-01 00:00:00',
			);
		}
		foreach ( (array) ( $input['kinds'] ?? array() ) as $kind ) {
			$kinds[ $kind ] = true;
		}

		$replaced = 0;
		$keep     = array();
		foreach ( $this->rows as $stored ) {
			$in_scope = $stored['source'] === $source && $stored['account'] === $account && isset( $kinds[ $stored['kind'] ] ) && $stored['period_start'] >= $start && $stored['period_end'] <= $end;
			if ( $in_scope ) {
				++$replaced;
			} else {
				$keep[] = $stored;
			}
		}

		if ( empty( $input['dry_run'] ) ) {
			foreach ( $normalized as &$row ) {
				$row['id'] = $this->next_id++;
			}
			unset( $row );
			$this->rows = array_merge( $keep, $normalized );
		}

		return array(
			'success'  => true,
			'dry_run'  => ! empty( $input['dry_run'] ),
			'source'   => $source,
			'account'  => $account,
			'replaced' => $replaced,
			'inserted' => count( $normalized ),
		);
	}

	/**
	 * Revenue-query double.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function query( array $input ) {
		$this->calls[] = array( 'datamachine/revenue-query', $input );
		$start         = (string) ( $input['start_date'] ?? '' );
		$end           = (string) ( $input['end_date'] ?? '' );

		$contained = array();
		$coverage  = array();
		foreach ( $this->rows as $row ) {
			foreach ( array( 'source', 'account', 'kind' ) as $field ) {
				if ( isset( $input[ $field ] ) && '' !== (string) $input[ $field ] && $row[ $field ] !== (string) $input[ $field ] ) {
					continue 2;
				}
			}
			if ( ( '' !== $end && $row['period_start'] > $end ) || ( '' !== $start && $row['period_end'] < $start ) ) {
				continue;
			}
			$key = $row['source'] . "\0" . $row['account'];
			if ( ! isset( $coverage[ $key ] ) ) {
				$coverage[ $key ] = array(
					'source'     => $row['source'],
					'account'    => $row['account'],
					'first_date' => $row['period_start'],
					'last_date'  => $row['period_end'],
				);
			}
			$coverage[ $key ]['first_date'] = min( $coverage[ $key ]['first_date'], '' !== $start ? max( $start, $row['period_start'] ) : $row['period_start'] );
			$coverage[ $key ]['last_date']  = max( $coverage[ $key ]['last_date'], '' !== $end ? min( $end, $row['period_end'] ) : $row['period_end'] );
			if ( ( '' === $start || $row['period_start'] >= $start ) && ( '' === $end || $row['period_end'] <= $end ) ) {
				$contained[] = $row;
			}
		}
		ksort( $coverage );

		$totals = array();
		foreach ( $contained as $row ) {
			$key = $row['kind'] . '|' . $row['currency'];
			if ( ! isset( $totals[ $key ] ) ) {
				$totals[ $key ] = array(
					'source'   => $row['source'],
					'kind'     => $row['kind'],
					'currency' => $row['currency'],
					'amount'   => 0.0,
					'rows'     => 0,
				);
			}
			$totals[ $key ]['amount'] += (float) $row['amount'];
			++$totals[ $key ]['rows'];
		}
		foreach ( $totals as &$total ) {
			$total['amount'] = number_format( $total['amount'], 6, '.', '' );
		}
		unset( $total );

		$limit  = max( 1, min( 1000, (int) ( $input['limit'] ?? 100 ) ) );
		$offset = $this->ignore_offset ? 0 : max( 0, (int) ( $input['offset'] ?? 0 ) );
		$page   = ! empty( $input['include_rows'] ) ? array_slice( $contained, $offset, $limit ) : array();
		$counts = array(
			'matched_rows'  => count( $contained ),
			'returned_rows' => count( $page ),
		);
		if ( ! $this->ignore_offset ) {
			$counts['offset'] = $offset;
		} elseif ( $this->echo_ignored_offset ) {
			$counts['offset'] = max( 0, (int) ( $input['offset'] ?? 0 ) );
		}
		$result = array(
			'success'  => true,
			'totals'   => array_values( $totals ),
			'coverage' => array_values( $coverage ),
			'counts'   => $counts,
		);
		if ( ! empty( $input['include_rows'] ) ) {
			$result['rows'] = $page;
		}
		return $result;
	}
}
