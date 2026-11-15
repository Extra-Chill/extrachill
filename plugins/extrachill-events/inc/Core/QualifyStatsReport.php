<?php
/**
 * Verdict histogram over the persistent qualification log.
 *
 * Aggregates the latest verdict per canonical URL from
 * <prefix>_dme_qualify_verdicts into a histogram by verdict, with detected
 * platform counts and remediation cohorts. Consumed by the
 * `wp extrachill venues qualify-stats` adapter in extrachill-cli.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds the latest-verdict-per-URL histogram and remediation cohorts. */
class QualifyStatsReport {
	private const PAGE_SIZE = 250;

	/**
	 * Build the report for verdicts logged in the last N days.
	 *
	 * Aggregates over the LATEST verdict per canonical URL so historical
	 * verdicts do not double-count.
	 *
	 * @param int $days Lookback days (minimum 1).
	 * @return array{days:int,verdicts:array<string,array<string,mixed>>,cohorts:array<int,array<string,mixed>>}
	 */
	public function build( int $days ): array {
		$days   = max( 1, $days );
		$cutoff = $this->site_local_cutoff( $days );

		$rows         = array();
		$cohort_state = QualifyCohortDeriver::start();
		$after_id     = 0;
		$page_count   = 0;
		$snapshot_id  = $this->snapshot_upper_bound();
		do {
			$page = $this->latest_rows_page( $cutoff, $snapshot_id, $after_id, self::PAGE_SIZE );
			if ( empty( $page ) ) {
				break;
			}
			$this->aggregate_latest( $page, $rows );
			QualifyCohortDeriver::accumulate( $cohort_state, $page );
			$last       = end( $page );
			$after_id   = (int) ( $last['id'] ?? 0 );
			$page_count = count( $page );
		} while ( self::PAGE_SIZE === $page_count );

		$this->sort_histogram( $rows );

		return array(
			'days'     => $days,
			'verdicts' => $rows,
			'cohorts'  => QualifyCohortDeriver::finish( $cohort_state ),
		);
	}

	/**
	 * Histogram rows in stable display order: QualifyVerdict::all() order
	 * (qualified, then fixable, then permanent), unknown verdicts last.
	 *
	 * @param array<string,array<string,mixed>> $verdicts Histogram keyed by verdict.
	 * @return array<int,array<string,mixed>>
	 */
	public static function ordered( array $verdicts ): array {
		$ordered = array();
		foreach ( QualifyVerdict::all() as $v ) {
			if ( isset( $verdicts[ $v ] ) ) {
				$ordered[] = $verdicts[ $v ];
			}
		}
		foreach ( $verdicts as $verdict => $row ) {
			if ( ! in_array( $verdict, QualifyVerdict::all(), true ) ) {
				$ordered[] = $row;
			}
		}
		return $ordered;
	}

	/**
	 * Verdict → guidance string for every known verdict, so agents reading
	 * the stats programmatically get the canonical guidance in one payload.
	 *
	 * @return array<string,string>
	 */
	public static function guidance_index(): array {
		$out = array();
		foreach ( QualifyVerdict::all() as $verdict ) {
			$out[ $verdict ] = QualifyVerdict::guidance_for( $verdict );
		}
		return $out;
	}

	/**
	 * Read one bounded page of current rows per canonical URL.
	 *
	 * The query keeps history-aware semantics: many runs against the same URL
	 * collapse to a single "current" verdict (the most recent one).
	 *
	 * @param string $cutoff      Inclusive site-local cutoff.
	 * @param int    $snapshot_id Inclusive snapshot upper bound.
	 * @param int    $after_id    Exclusive keyset cursor.
	 * @param int    $limit       Page size.
	 * @return array<int,array<string,mixed>>
	 */
	private function latest_rows_page( string $cutoff, int $snapshot_id, int $after_id, int $limit ): array {
		global $wpdb;
		$table = QualifyVerdictsTable::table_name();

		// Match QualifyVerdictsTable::latest_for_url_hash(): qualified_at DESC,
		// then id DESC. The fixed upper bound excludes concurrent appends.
		// Table name is a trusted internal identifier built from $wpdb->prefix.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only, bounded report against an append-only internal table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.id, v.url, v.verdict, v.fingerprint
			FROM {$table} v
			WHERE v.id <= %d
			AND v.qualified_at >= %s
			AND v.id > %d
			AND NOT EXISTS (
				SELECT 1 FROM {$table} newer
				WHERE newer.url_hash = v.url_hash
				AND newer.id <= %d
				AND (
					newer.qualified_at > v.qualified_at
					OR ( newer.qualified_at = v.qualified_at AND newer.id > v.id )
				)
			)
			ORDER BY v.id ASC
			LIMIT %d",
				$snapshot_id,
				$cutoff,
				$after_id,
				$snapshot_id,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Capture the finite verdict-log snapshot used by every page.
	 *
	 * This excludes ordinary appends allocated above the bound. An InnoDB
	 * transaction can reserve a lower auto-increment id and commit after this
	 * read; avoiding a long locking transaction means that rare row may wait
	 * until the next report, but it cannot make this scan duplicate or loop.
	 */
	private function snapshot_upper_bound(): int {
		global $wpdb;
		$table = QualifyVerdictsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table; read-only snapshot boundary.
		return (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" );
	}

	/**
	 * Compute the explicit site-local cutoff used by qualified_at queries.
	 *
	 * @param int      $days   Lookback days.
	 * @param int|null $now_ts Current timestamp override for tests.
	 */
	private function site_local_cutoff( int $days, ?int $now_ts = null ): string {
		$now_ts = null === $now_ts ? time() : $now_ts;
		$now    = new \DateTimeImmutable( '@' . $now_ts );
		return $now->setTimezone( wp_timezone() )->modify( sprintf( '-%d days', max( 1, $days ) ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Build a verdict histogram from latest-verdict rows.
	 *
	 * @param array $rows      Latest verdict rows.
	 * @param array $histogram Verdict histogram accumulator.
	 */
	private function aggregate_latest( array $rows, array &$histogram ): void {
		foreach ( $rows as $row ) {
			$verdict = (string) ( $row['verdict'] ?? '' );
			if ( '' === $verdict ) {
				continue;
			}
			if ( ! isset( $histogram[ $verdict ] ) ) {
				$histogram[ $verdict ] = array(
					'verdict'        => $verdict,
					'count'          => 0,
					'platforms'      => array(),
					'agent_guidance' => QualifyVerdict::guidance_for( $verdict ),
				);
			}
			++$histogram[ $verdict ]['count'];

			$decoded = json_decode( (string) ( $row['fingerprint'] ?? '' ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['platforms_detected'] )
				&& is_array( $decoded['platforms_detected'] ) ) {
				foreach ( $decoded['platforms_detected'] as $p ) {
					$p = (string) $p;
					if ( '' === $p ) {
						continue;
					}
					if ( ! isset( $histogram[ $verdict ]['platforms'][ $p ] ) ) {
						$histogram[ $verdict ]['platforms'][ $p ] = 0;
					}
					++$histogram[ $verdict ]['platforms'][ $p ];
				}
			}
		}
	}

	/**
	 * Sort platform counts after all pages have been accumulated.
	 *
	 * @param array<string,array<string,mixed>> $histogram Verdict histogram.
	 */
	private function sort_histogram( array &$histogram ): void {
		foreach ( $histogram as &$row ) {
			uksort(
				$row['platforms'],
				static function ( string $a, string $b ) use ( $row ): int {
					$count_order = $row['platforms'][ $b ] <=> $row['platforms'][ $a ];
					return 0 !== $count_order ? $count_order : strcmp( $a, $b );
				}
			);
		}
		unset( $row );
	}
}
