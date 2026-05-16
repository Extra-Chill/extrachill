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
		if ( $stored === self::SCHEMA_VERSION && self::table_exists() ) {
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
			'fingerprint'       => $fingerprint ?: '{}',
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
				"SELECT * FROM {$table} WHERE url_hash = %s ORDER BY qualified_at DESC, id DESC LIMIT 1",
				$url_hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
