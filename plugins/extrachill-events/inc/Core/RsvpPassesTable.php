<?php
/**
 * RSVP Perk Pass Storage
 *
 * One row per (event, user): the attendee's RSVP perk pass. Storage lives
 * here, in extrachill-events, because the perk/pass/redemption concept is an
 * Events product feature — attendance itself stays owned by extrachill-users
 * (extrachill-users#414/#415, ec_concert_tracking). This table only exists
 * for events that have a perk enabled; see inc/admin/event-perks.php.
 *
 * Pass codes are stored in plaintext, not hashed. This is a deliberate
 * choice, not an oversight: a pass code is a single-use, single-event,
 * instantly-revocable bearer token analogous to a boarding-pass barcode or a
 * PNR — not a password whose compromise threatens a reusable credential
 * elsewhere. The attendee legitimately needs the same code redisplayed on
 * page reload, in the confirmation email, and later in My Shows; hashing it
 * would require a parallel plaintext-recovery path that adds complexity
 * without a matching security benefit. Exposure is bounded by scope (one
 * event, one attendee), by revocation (unmarking Going kills the code
 * forever — a reactivated pass gets a fresh code, see issue_or_reactivate()),
 * and by single-use redemption (once redeemed, the code is dead).
 *
 * @package ExtraChillEvents\Core
 * @since   0.69.0
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Owns the RSVP perk pass table: schema, issuance, revocation, redemption. */
final class RsvpPassesTable {

	/** Schema version. Bump when the CREATE TABLE definition changes. */
	const SCHEMA_VERSION = '1';

	/** Site option key that stores the installed schema version. */
	const VERSION_OPTION = 'extrachill_events_rsvp_passes_db_version';

	const STATUS_ACTIVE   = 'active';
	const STATUS_REVOKED  = 'revoked';
	const STATUS_REDEEMED = 'redeemed';

	/** Charset-safe alphabet for pass codes: no ambiguous 0/O/1/I/L. */
	private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

	/** Random bytes consumed per code (75 bits of entropy at 5 bits/char). */
	private const CODE_BYTES = 15;

	/**
	 * Get the full table name. Site-scoped: this plugin is Network: false
	 * and runs only on events.extrachill.com.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_event_rsvp_passes';
	}

	/**
	 * Install / upgrade the table via dbDelta. Idempotent.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			code varchar(20) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'active',
			issued_at datetime NOT NULL,
			revoked_at datetime NULL,
			redeemed_at datetime NULL,
			redeemed_by_user_id bigint(20) unsigned NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_user (event_id, user_id),
			UNIQUE KEY code (code),
			KEY event_status (event_id, status)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/** Install only when the stored schema version is stale. */
	public static function maybe_install(): void {
		if ( self::SCHEMA_VERSION === get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}
		self::create_table();
	}

	/** Check if the table exists. */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name; schema existence cannot use cached data.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** Cheap request-time readiness signal, mirroring the repo's other schema classes. */
	public static function is_ready(): bool {
		return self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' );
	}

	/**
	 * Generate a fresh, unguessable pass code.
	 *
	 * Not derived from the user or event ID. 15 random bytes over a
	 * 32-symbol alphabet (256 is evenly divisible by 32, so no modulo
	 * bias) yields 75 bits of entropy, grouped for on-screen readability.
	 *
	 * @return string e.g. "ABCDE-FGH2J-K3LMN"
	 */
	public static function generate_code(): string {
		$bytes    = random_bytes( self::CODE_BYTES );
		$alphabet = self::CODE_ALPHABET;
		$length   = strlen( $alphabet );
		$code     = '';
		foreach ( str_split( $bytes ) as $byte ) {
			$code .= $alphabet[ ord( $byte ) % $length ];
		}
		return implode( '-', str_split( $code, 5 ) );
	}

	/**
	 * Find a pass by its (event, user) pair, regardless of status.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $user_id  User ID.
	 * @return array|null
	 */
	public static function find_for_user_event( int $event_id, int $user_id ): ?array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d AND user_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$event_id,
				$user_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Find a pass by its exact code. Used by the slice-2 verify/redeem-by-scan
	 * path; harmless to ship now since the unique index already exists.
	 *
	 * @param string $code Exact pass code, as issued.
	 * @return array|null
	 */
	public static function find_by_code( string $code ): ?array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE code = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$code
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * List every pass issued for an event, keyed by user_id, for the door list.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $limit    Max rows. Clamped to 1-1000.
	 * @return array<int, array> Keyed by user_id.
	 */
	public static function list_for_event( int $event_id, int $limit = 500 ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 1000, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d ORDER BY issued_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$event_id,
				$limit
			),
			ARRAY_A
		);

		$by_user = array();
		foreach ( (array) $rows as $row ) {
			$by_user[ (int) $row['user_id'] ] = $row;
		}
		return $by_user;
	}

	/**
	 * Idempotently issue a pass, or reactivate a previously revoked one.
	 *
	 * Called only from the transition unmarked -> marked (see
	 * inc/core/rsvp-pass-service.php), so a genuine double-issue race is
	 * already rare; the unique (event_id, user_id) key makes the remaining
	 * window safe: a losing concurrent insert fails on the duplicate key and
	 * simply re-selects the winner's row.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $user_id  User ID.
	 * @return array|null The active pass row, or null on unrecoverable failure.
	 */
	public static function issue_or_reactivate( int $event_id, int $user_id ): ?array {
		global $wpdb;
		$table = self::table_name();

		$existing = self::find_for_user_event( $event_id, $user_id );
		if ( $existing && self::STATUS_ACTIVE === $existing['status'] ) {
			return $existing;
		}

		$now  = current_time( 'mysql', true );
		$code = self::generate_code();

		if ( $existing ) {
			// Reactivating a revoked pass gets a fresh code — the previous
			// code is dead forever, even if it had already been shown/shared.
			$wpdb->update(
				$table,
				array(
					'code'                => $code,
					'status'              => self::STATUS_ACTIVE,
					'issued_at'           => $now,
					'revoked_at'          => null,
					'redeemed_at'         => null,
					'redeemed_by_user_id' => null,
					'updated_at'          => $now,
				),
				array( 'id' => $existing['id'] )
			);
			return self::find_for_user_event( $event_id, $user_id );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'event_id'   => $event_id,
				'user_id'    => $user_id,
				'code'       => $code,
				'status'     => self::STATUS_ACTIVE,
				'issued_at'  => $now,
				'updated_at' => $now,
			)
		);

		if ( false === $inserted ) {
			// Lost a concurrent-insert race against the unique (event_id,
			// user_id) key; the winner's row is the correct one to return.
			$existing = self::find_for_user_event( $event_id, $user_id );
			return $existing ? $existing : null;
		}

		return self::find_for_user_event( $event_id, $user_id );
	}

	/**
	 * Revoke an active pass. No-op against a pass that is already revoked
	 * or already redeemed — a redemption record must never be erased by a
	 * later unmark; the perk was already given.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $user_id  User ID.
	 * @return bool Whether a pass was revoked.
	 */
	public static function revoke( int $event_id, int $user_id ): bool {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => self::STATUS_REVOKED,
				'revoked_at' => $now,
				'updated_at' => $now,
			),
			array(
				'event_id' => $event_id,
				'user_id'  => $user_id,
				'status'   => self::STATUS_ACTIVE,
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Atomically redeem a pass. Safe against a double-tap or two hosts
	 * redeeming at once: the UPDATE's WHERE status='active' clause is the
	 * compare-and-set primitive, not an application-level lock.
	 *
	 * @param int $event_id            Event post ID.
	 * @param int $user_id             Attendee user ID.
	 * @param int $redeemed_by_user_id Host user ID performing the redemption.
	 * @return array|\WP_Error {
	 *     @type bool        $already_redeemed    Whether this call found the pass already redeemed.
	 *     @type string      $redeemed_at         MySQL UTC datetime.
	 *     @type int         $redeemed_by_user_id Host who redeemed it (first winner on a race).
	 * }
	 */
	public static function redeem( int $event_id, int $user_id, int $redeemed_by_user_id ) {
		global $wpdb;
		$table = self::table_name();

		$pass = self::find_for_user_event( $event_id, $user_id );
		if ( ! $pass ) {
			return new \WP_Error( 'rsvp_pass_not_found', __( 'No pass found for this attendee.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		if ( self::STATUS_REVOKED === $pass['status'] ) {
			return new \WP_Error( 'rsvp_pass_revoked', __( "This attendee's RSVP was cancelled; their pass is no longer valid.", 'extrachill-events' ), array( 'status' => 409 ) );
		}

		if ( self::STATUS_REDEEMED === $pass['status'] ) {
			return array(
				'already_redeemed'    => true,
				'redeemed_at'         => $pass['redeemed_at'],
				'redeemed_by_user_id' => (int) $pass['redeemed_by_user_id'],
			);
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic CAS; the WHERE status='active' clause is the concurrency primitive, not an application lock.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, redeemed_at = %s, redeemed_by_user_id = %d, updated_at = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				self::STATUS_REDEEMED,
				$now,
				$redeemed_by_user_id,
				$now,
				$pass['id'],
				self::STATUS_ACTIVE
			)
		);

		if ( 1 !== (int) $updated ) {
			// Lost the race to a concurrent redeemer (two hosts tapping at
			// the same moment). Report the winner's state, not an error.
			$current = self::find_for_user_event( $event_id, $user_id );
			return array(
				'already_redeemed'    => true,
				'redeemed_at'         => $current['redeemed_at'] ?? $now,
				'redeemed_by_user_id' => (int) ( $current['redeemed_by_user_id'] ?? $redeemed_by_user_id ),
			);
		}

		return array(
			'already_redeemed'    => false,
			'redeemed_at'         => $now,
			'redeemed_by_user_id' => $redeemed_by_user_id,
		);
	}
}
