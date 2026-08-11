<?php
/**
 * Artist URL Submissions Table
 *
 * Stores the moderation queue for qualified recurring event sources. The
 * original table name and artist columns remain compatibility contracts;
 * generic source identity, canonical URL, and qualification fields extend
 * those rows without moving or rewriting deployed data.
 *
 * Ownership note (extrachill-events#200): this subsystem was migrated out
 * of the generic `data-machine-events` substrate, which must not carry
 * "artist" domain knowledge. The table name is unchanged
 * (`<base_prefix>artist_url_submissions`) and the schema is identical, so
 * the migration is ownership-only — no data move, no row loss. The
 * version option key changed from the old DME key to an EC-namespaced key
 * (see VERSION_OPTION); `maybe_install()` re-runs the idempotent dbDelta
 * against the already-correct table, which is a no-op, and stamps the new
 * key.
 *
 * Table lives under `$wpdb->base_prefix` (network-scoped) so the
 * moderation queue is global across the multisite, matching the prompt's
 * dedupe contract ("if user A submits the same URL as user B already
 * did, the second submission returns 'this URL is already being
 * tracked'"). The plugin is per-site activated, so we install lazily on
 * `plugins_loaded` via a version option check.
 *
 * @package ExtraChillEvents\Core
 * @since   0.35.0
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtistUrlSubmissionsTable {

	/**
	 * Schema version. Bump when CREATE TABLE definition changes.
	 */
	const SCHEMA_VERSION = '2';

	/**
	 * Site option key that stores the installed schema version.
	 *
	 * EC-namespaced (extrachill-events#200). The migrated table keeps its
	 * original name; only the owning plugin and this version key change.
	 */
	const VERSION_OPTION = 'extrachill_events_artist_url_submissions_db_version';

	/**
	 * Submission statuses.
	 */
	const STATUS_PENDING_REVIEW  = 'pending_review';
	const STATUS_APPROVED        = 'approved';
	const STATUS_REJECTED        = 'rejected';
	const STATUS_SCRAPING_FAILED = 'scraping_failed';

	/**
	 * Get the full table name. Uses base prefix so the moderation queue
	 * is shared across the multisite network (see class docblock).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'artist_url_submissions';
	}

	/**
	 * Install / upgrade the table via dbDelta. Idempotent — safe to call
	 * on every request once the version guard is in place.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NULL,
			contact_email varchar(255) NULL,
			contact_name varchar(255) NULL,
			url varchar(2048) NOT NULL,
			url_hash char(64) NOT NULL,
			canonical_url varchar(2048) NULL,
			source_kind varchar(32) NULL,
			entity_taxonomy varchar(32) NULL,
			entity_term_id bigint(20) unsigned NULL,
			entity_name varchar(255) NULL,
			qualification_verdict varchar(64) NULL,
			qualification_data longtext NULL,
			suggested_artist_name varchar(255) NULL,
			suggested_artist_term_id bigint(20) unsigned NULL,
			detected_format varchar(64) NULL,
			events_found_count int unsigned NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'pending_review',
			admin_notes text NULL,
			rejection_reason text NULL,
			pipeline_id bigint(20) unsigned NULL,
			flow_id bigint(20) unsigned NULL,
			artist_term_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			reviewed_at datetime NULL,
			reviewed_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY idx_status (status, created_at),
			KEY idx_user (user_id, created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_site_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Check whether the installed schema is current. Called on every
	 * request via the install hook; only triggers dbDelta when the
	 * stored version mismatches the class constant.
	 */
	public static function maybe_install(): void {
		$installed = get_site_option( self::VERSION_OPTION, '' );
		if ( self::SCHEMA_VERSION === $installed ) {
			return;
		}
		self::create_table();
	}

	/**
	 * Check if the table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Normalize a URL for hashing/dedupe.
	 *
	 * Lowercases scheme + host, strips fragment, trims trailing slash
	 * (except for root paths), and removes default ports.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalized URL, or empty string if the input is not parseable.
	 */
	public static function normalize_url( string $url ): string {
		return QualifyVerdict::canonicalize_url( $url );
	}

	/**
	 * Compute the dedupe hash for a URL's canonical identity.
	 *
	 * @param string $url Operational or canonical URL.
	 * @return string sha256 hex.
	 */
	public static function url_hash( string $url ): string {
		return hash( 'sha256', self::normalize_url( $url ) );
	}

	/**
	 * Look up a submission by its URL hash.
	 *
	 * @param string $url_hash sha256 hex.
	 * @return array|null Submission row as assoc array, or null.
	 */
	public static function find_by_hash( string $url_hash ): ?array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s LIMIT 1", $url_hash ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->base_prefix.
			ARRAY_A
		);

		return $row ? self::with_compatibility_defaults( $row ) : null;
	}

	/**
	 * Find a pending or approved source by canonical URL identity.
	 *
	 * The hash fast path covers Phase 1 rows. The legacy scan keeps old
	 * artist records readable when their stored hash includes tracking/query or
	 * trailing-slash variants from the pre-canonical schema.
	 */
	public static function find_tracked_by_url( string $url, int $exclude_id = 0 ): ?array {
		$canonical = self::normalize_url( $url );
		if ( '' === $canonical ) {
			return null;
		}
		$hashed = self::find_by_hash( self::url_hash( $canonical ) );
		if ( $hashed && (int) $hashed['id'] !== $exclude_id && in_array( $hashed['status'], array( self::STATUS_PENDING_REVIEW, self::STATUS_APPROVED ), true ) ) {
			return $hashed;
		}

		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY id DESC",
				self::STATUS_PENDING_REVIEW,
				self::STATUS_APPROVED
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			if ( (int) $row['id'] === $exclude_id ) {
				continue;
			}
			$candidate = (string) ( $row['canonical_url'] ?? $row['url'] ?? '' );
			if ( self::normalize_url( $candidate ) === $canonical ) {
				return self::with_compatibility_defaults( $row );
			}
		}
		return null;
	}

	/**
	 * Look up a submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->base_prefix.
			ARRAY_A
		);

		return $row ? self::with_compatibility_defaults( $row ) : null;
	}

	/**
	 * Insert a new submission row. Returns the new ID, or null on failure.
	 *
	 * @param array $data Row data.
	 * @return int|null
	 */
	public static function insert( array $data ): ?int {
		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'user_id'                  => null,
			'contact_email'            => null,
			'contact_name'             => null,
			'url'                      => '',
			'url_hash'                 => '',
			'canonical_url'            => null,
			'source_kind'              => null,
			'entity_taxonomy'          => null,
			'entity_term_id'           => null,
			'entity_name'              => null,
			'qualification_verdict'    => null,
			'qualification_data'       => null,
			'suggested_artist_name'    => null,
			'suggested_artist_term_id' => null,
			'detected_format'          => null,
			'events_found_count'       => 0,
			'status'                   => self::STATUS_PENDING_REVIEW,
			'admin_notes'              => null,
			'rejection_reason'         => null,
			'pipeline_id'              => null,
			'flow_id'                  => null,
			'artist_term_id'           => null,
		);

		$row = array_merge( $defaults, array_intersect_key( $data, $defaults ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $table, $row );
		if ( false === $result ) {
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a submission row by ID.
	 *
	 * @param int   $id   Submission ID.
	 * @param array $data Columns to update.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$table = self::table_name();

		$allowed = array(
			'status',
			'admin_notes',
			'rejection_reason',
			'pipeline_id',
			'flow_id',
			'artist_term_id',
			'canonical_url',
			'source_kind',
			'entity_taxonomy',
			'entity_term_id',
			'entity_name',
			'qualification_verdict',
			'qualification_data',
			'suggested_artist_name',
			'suggested_artist_term_id',
			'detected_format',
			'events_found_count',
			'reviewed_at',
			'reviewed_by',
		);

		$update = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $update ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, $update, array( 'id' => $id ) );
		return false !== $result;
	}

	/**
	 * List submissions filtered by status.
	 *
	 * @param string $status   One of the STATUS_* constants, or 'all'.
	 * @param int    $per_page Page size.
	 * @param int    $offset   Offset for pagination.
	 * @return array<int, array>
	 */
	public static function list_by_status( string $status, int $per_page = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table_name();

		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = max( 0, $offset );

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->base_prefix.
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->base_prefix.
					"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return $rows ? array_map( array( self::class, 'with_compatibility_defaults' ), $rows ) : array();
	}

	/**
	 * Read legacy artist rows through the generic event-source model.
	 *
	 * Existing records predate the source columns, so an empty source kind is
	 * concrete evidence of the shipped artist-tour contract rather than an
	 * unknown classification.
	 */
	public static function with_compatibility_defaults( array $row ): array {
		if ( empty( $row['source_kind'] ) ) {
			$row['source_kind']          = 'artist';
			$row['compatibility_legacy'] = true;
		} else {
			$row['compatibility_legacy'] = false;
		}
		if ( empty( $row['canonical_url'] ) ) {
			$row['canonical_url'] = (string) ( $row['url'] ?? '' );
		}
		if ( empty( $row['entity_taxonomy'] ) && 'artist' === $row['source_kind'] ) {
			$row['entity_taxonomy'] = 'artist';
		}
		if ( empty( $row['entity_term_id'] ) && ! empty( $row['suggested_artist_term_id'] ) ) {
			$row['entity_term_id'] = (int) $row['suggested_artist_term_id'];
		}
		if ( empty( $row['entity_name'] ) && ! empty( $row['suggested_artist_name'] ) ) {
			$row['entity_name'] = (string) $row['suggested_artist_name'];
		}
		return $row;
	}

	/**
	 * Count submissions by status. Returns an associative array keyed by
	 * status with row counts.
	 *
	 * @return array<string,int>
	 */
	public static function counts_by_status(): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->base_prefix; query has no user-supplied values.
			"SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status",
			ARRAY_A
		);

		$out = array(
			self::STATUS_PENDING_REVIEW  => 0,
			self::STATUS_APPROVED        => 0,
			self::STATUS_REJECTED        => 0,
			self::STATUS_SCRAPING_FAILED => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Count approved submissions for a given user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public static function count_approved_by_user( int $user_id ): int {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted internal table name returned by self::table_name(); not user input.
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = %s",
				$user_id,
				self::STATUS_APPROVED
			)
		);
	}
}
