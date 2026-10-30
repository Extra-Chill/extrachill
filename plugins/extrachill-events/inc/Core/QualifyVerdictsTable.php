<?php
/**
 * Qualify Verdicts Table
 *
 * Persistent verdict log for the venue qualification subsystem. Every call to
 * `extrachill/qualify-venue` writes one row, regardless of whether the venue
 * qualified or not. Historical rows are preserved so qualify quality can be
 * graphed over time and venues can be requalified when extractors improve.
 *
 * Schema: <prefix>_dme_qualify_verdicts
 *
 * The "dme" prefix mirrors the data-machine-events naming convention (e.g.
 * `<prefix>_datamachine_event_dates`) since this plugin owns the venue
 * discovery and qualification surface that sits on top of data-machine-events.
 *
 * @package ExtraChillEvents\Core
 * @since   0.20.0
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QualifyVerdictsTable {

	/**
	 * Bump this when the table schema changes — activation hook re-runs
	 * dbDelta when the stored version differs from this constant.
	 *
	 * v2: adds the agent_guidance column. Verdict-specific guidance strings
	 *     aimed at chat agents that read verdicts and decide next actions.
	 *     Human-readable hints stay in improvement_hint.
	 */
	private const SCHEMA_VERSION        = '2';
	private const SCHEMA_VERSION_OPTION = 'extrachill_events_qualify_verdicts_schema_version';

	/**
	 * Full table name for the current site.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'dme_qualify_verdicts';
	}

	/**
	 * Create the verdicts table via dbDelta.
	 *
	 * Safe to call on every plugin load — dbDelta is idempotent. Activation
	 * hooks should call this; the runtime maybe_install() helper short-circuits
	 * via the schema_version option once the table is current.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// LONGTEXT for fingerprint JSON so a verbose extractor_attempts array
		// is never truncated.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(512) NOT NULL,
			url_hash CHAR(40) NOT NULL,
			verdict VARCHAR(50) NOT NULL,
			events_url VARCHAR(512) DEFAULT NULL,
			fingerprint LONGTEXT NOT NULL,
			improvement_hint TEXT DEFAULT NULL,
			agent_guidance TEXT DEFAULT NULL,
			event_count INT UNSIGNED NOT NULL DEFAULT 0,
			qualified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			qualifier_version VARCHAR(20) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_url_hash (url_hash),
			KEY idx_verdict_qualified_at (verdict, qualified_at)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Idempotent install — creates the table if it does not exist or if the
	 * stored schema version is older than the constant. Safe to call on every
	 * `init` or `plugins_loaded` if needed; cheap when up to date.
	 */
	public static function maybe_install(): void {
		$stored = (string) get_option( self::SCHEMA_VERSION_OPTION, '' );
		if ( self::SCHEMA_VERSION === $stored && self::table_exists() ) {
			return;
		}
		self::create_table();
	}

	/**
	 * Check whether the verdicts table exists for the current site.
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
	}

	/**
	 * Insert a verdict row. ALWAYS insert (never UPDATE) — historical
	 * verdicts are preserved so qualify quality can be tracked over time.
	 *
	 * Latest-verdict-per-URL queries should use `idx_url_hash` + ORDER BY
	 * `qualified_at` DESC (see `latest_for_url_hash`).
	 *
	 * @param array $row {
	 *     @type string $url               Raw input URL (will be canonicalized).
	 *     @type string $verdict           One of the QualifyVerdict::* constants.
	 *     @type string $events_url        URL that actually qualified (may differ from input).
	 *     @type array  $fingerprint       Fingerprint payload (JSON-encoded on write).
	 *     @type string $improvement_hint  Human-readable hint.
	 *     @type int    $event_count       Number of events the extractor returned.
	 *     @type string $qualifier_version Plugin version at qualify time.
	 * }
	 * @return int Inserted row id, or 0 on failure.
	 */
	public static function insert( array $row ): int {
		global $wpdb;

		$url       = (string) ( $row['url'] ?? '' );
		$canonical = QualifyVerdict::canonicalize_url( $url );

		if ( '' === $canonical ) {
			return 0;
		}

		$verdict           = (string) ( $row['verdict'] ?? '' );
		$events_url        = isset( $row['events_url'] ) && '' !== $row['events_url']
			? (string) $row['events_url']
			: null;
		$fingerprint       = isset( $row['fingerprint'] ) && is_array( $row['fingerprint'] )
			? wp_json_encode( $row['fingerprint'] )
			: ( is_string( $row['fingerprint'] ?? null ) ? $row['fingerprint'] : '{}' );
		$improvement_hint  = isset( $row['improvement_hint'] ) && '' !== $row['improvement_hint']
			? (string) $row['improvement_hint']
			: null;
		$agent_guidance    = isset( $row['agent_guidance'] ) && '' !== $row['agent_guidance']
			? (string) $row['agent_guidance']
			: null;
		$event_count       = (int) ( $row['event_count'] ?? 0 );
		$qualifier_version = (string) ( $row['qualifier_version'] ?? '' );

		$data    = array(
			'url'               => mb_substr( $canonical, 0, 512 ),
			'url_hash'          => sha1( $canonical ),
			'verdict'           => mb_substr( $verdict, 0, 50 ),
			'events_url'        => null === $events_url ? null : mb_substr( $events_url, 0, 512 ),
			'fingerprint'       => $fingerprint ? $fingerprint : '{}',
			'improvement_hint'  => $improvement_hint,
			'agent_guidance'    => $agent_guidance,
			'event_count'       => $event_count < 0 ? 0 : $event_count,
			'qualified_at'      => current_time( 'mysql' ),
			'qualifier_version' => mb_substr( $qualifier_version, 0, 20 ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( self::table_name(), $data, $formats );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch the latest verdict row for a canonical URL.
	 *
	 * @param string $url Raw URL — canonicalized internally.
	 * @return array|null Associative row, or null if no verdict exists.
	 */
	public static function latest_for_url( string $url ): ?array {
		$hash = QualifyVerdict::url_hash( $url );
		if ( '' === $hash ) {
			return null;
		}
		return self::latest_for_url_hash( $hash );
	}

	/**
	 * Fetch the latest verdict row for a pre-computed url_hash.
	 *
	 * @param string $url_hash 40-char sha1 hash.
	 * @return array|null
	 */
	public static function latest_for_url_hash( string $url_hash ): ?array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->base_prefix.
				"SELECT * FROM {$table} WHERE url_hash = %s ORDER BY qualified_at DESC, id DESC LIMIT 1",
				$url_hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Count URLs whose canonical latest verdict matches inside a local-time window.
	 *
	 * Verdict timestamps are stored with current_time( 'mysql' ), so callers must
	 * pass site-local DATETIME bounds. The window is half-open [start, end).
	 * Canonical latest ordering matches latest_for_url_hash(): qualified_at DESC,
	 * then id DESC. This remains correct for backfilled rows whose IDs do not
	 * reflect event time and makes timestamp ties deterministic.
	 *
	 * Keep this query as the shared latest-window semantic for digest/cohort
	 * consumers; do not replace it with MAX(id) grouping.
	 *
	 * @param string   $verdict      Verdict to count.
	 * @param string   $start_local  Inclusive site-local DATETIME bound.
	 * @param string   $end_local    Exclusive site-local DATETIME bound.
	 * @param int|null $max_id       Optional inclusive snapshot upper bound.
	 * @return int Distinct canonical URLs matching the verdict.
	 */
	public static function count_latest_verdicts_in_window( string $verdict, string $start_local, string $end_local, ?int $max_id = null ): int {
		global $wpdb;
		$table                  = self::table_name();
		$current_snapshot_bound = null === $max_id ? '' : ' AND current_verdict.id <= %d';
		$newer_snapshot_bound   = null === $max_id ? '' : ' AND newer_verdict.id <= %d';
		$args                   = array( $verdict, $start_local, $end_local );
		if ( null !== $max_id ) {
			$args[] = $max_id;
			$args[] = $max_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Trusted table name; optional snapshot clauses determine the prepared argument count.
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT current_verdict.url_hash)
				 FROM {$table} current_verdict
				 WHERE current_verdict.verdict = %s
				   AND current_verdict.qualified_at >= %s
				   AND current_verdict.qualified_at < %s
				   {$current_snapshot_bound}
				   AND NOT EXISTS (
					 SELECT 1
					 FROM {$table} newer_verdict
					 WHERE newer_verdict.url_hash = current_verdict.url_hash
					   {$newer_snapshot_bound}
					   AND (
						 newer_verdict.qualified_at > current_verdict.qualified_at
						 OR (
							 newer_verdict.qualified_at = current_verdict.qualified_at
							 AND newer_verdict.id > current_verdict.id
						 )
					   )
				   )",
				...$args
			)
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		return (int) $count;
	}

	/**
	 * Fetch the N most recent verdict rows for a URL hash, newest first.
	 *
	 * Used by meets_pause_confirmation() to inspect verdict history when
	 * deciding whether a candidate pause has enough corroborating evidence.
	 *
	 * @param string $url_hash 40-char sha1 hash.
	 * @param int    $limit    Maximum rows to return. Capped at 50 defensively.
	 * @return array<int, array<string, mixed>> Rows ordered by qualified_at DESC, id DESC.
	 */
	public function latest_verdicts_for_url( string $url_hash, int $limit = 10 ): array {
		global $wpdb;
		if ( '' === $url_hash ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->base_prefix.
				"SELECT verdict, qualified_at, id FROM {$table} WHERE url_hash = %s ORDER BY qualified_at DESC, id DESC LIMIT %d",
				$url_hash,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Check whether a verdict has enough corroborating evidence to justify
	 * auto-pausing a flow.
	 *
	 * Looks up CONFIRMATION_RULES for the candidate verdict and walks the
	 * URL's recent verdict log. Returns true only when:
	 *
	 *  - the verdict has a rule (qualified verdicts return false — never paused),
	 *  - the last N rows all carry the same verdict, AND
	 *  - the oldest of those N rows is at least H hours old.
	 *
	 * Single-row rules (RESERVATION_ONLY / COVERED_ELSEWHERE) effectively
	 * pause on the latest verdict alone since N=1 and H=0.
	 *
	 * @param string $url_hash 40-char sha1 hash.
	 * @param string $verdict  The candidate verdict.
	 * @return bool True when the verdict meets its confirmation rule.
	 */
	public function meets_pause_confirmation( string $url_hash, string $verdict ): bool {
		$rule = QualifyVerdict::confirmation_for( $verdict );
		if ( null === $rule ) {
			return false;
		}

		$needed_verdicts = (int) ( $rule['verdicts'] ?? 0 );
		$needed_hours    = (int) ( $rule['hours'] ?? 0 );

		if ( $needed_verdicts < 1 ) {
			return false;
		}

		$rows = $this->latest_verdicts_for_url( $url_hash, $needed_verdicts );
		if ( count( $rows ) < $needed_verdicts ) {
			return false;
		}

		// Every one of the last N rows must match the candidate verdict.
		foreach ( $rows as $row ) {
			if ( (string) ( $row['verdict'] ?? '' ) !== $verdict ) {
				return false;
			}
		}

		// Hours window: the oldest of those N rows must be at least
		// $needed_hours old. A zero-hour rule short-circuits the check.
		if ( $needed_hours <= 0 ) {
			return true;
		}

		$oldest    = end( $rows );
		$oldest_ts = isset( $oldest['qualified_at'] ) ? strtotime( (string) $oldest['qualified_at'] . ' UTC' ) : false;
		if ( false === $oldest_ts ) {
			return false;
		}

		$cutoff = time() - ( $needed_hours * HOUR_IN_SECONDS );

		return $oldest_ts <= $cutoff;
	}
}
