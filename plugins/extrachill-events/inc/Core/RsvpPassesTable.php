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
	const SCHEMA_VERSION = '2';

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
			user_id bigint(20) unsigned NULL DEFAULT NULL,
			code varchar(20) NOT NULL,
			guest_name varchar(100) NULL DEFAULT NULL,
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

		// Schema 2 (guest list passes): user_id became nullable so a pass can
		// be held by no account. dbDelta compares column types only, never
		// nullability, so the existing NOT NULL column needs an explicit
		// MODIFY. NULLs never collide on the (event_id, user_id) unique key,
		// so any number of account-less passes fit on one event.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time schema migration on a trusted internal table name.
		$wpdb->query( "ALTER TABLE {$table} MODIFY user_id bigint(20) unsigned NULL DEFAULT NULL" );

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
	 * Count an event's outstanding passes for guest list capacity.
	 *
	 * `claimed` is every pass that holds a spot (active + redeemed); a
	 * revoked pass gives its spot back. `checked_in` is the redeemed subset.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{claimed:int, checked_in:int}
	 */
	public static function count_for_event( int $event_id ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Capacity must read live rows, never a cache.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(status IN (%s, %s)) AS claimed, SUM(status = %s) AS checked_in FROM {$table} WHERE event_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				self::STATUS_ACTIVE,
				self::STATUS_REDEEMED,
				self::STATUS_REDEEMED,
				$event_id
			),
			ARRAY_A
		);

		return array(
			'claimed'    => (int) ( $row['claimed'] ?? 0 ),
			'checked_in' => (int) ( $row['checked_in'] ?? 0 ),
		);
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
	 * With a capacity (guest list, extrachill#433), the count-and-issue runs
	 * under a per-event MySQL named lock (GET_LOCK), so two guests claiming
	 * the last spot at the same moment can't both get it. An attendee who
	 * already holds an active pass always gets it back; capacity only gates
	 * new or reactivated passes.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $user_id  User ID.
	 * @param int $capacity Max outstanding passes (active + redeemed). 0 = unlimited.
	 * @return array|null The active pass row, or null when full or on unrecoverable failure.
	 */
	public static function issue_or_reactivate( int $event_id, int $user_id, int $capacity = 0 ): ?array {
		$existing = self::find_for_user_event( $event_id, $user_id );
		if ( $existing && self::STATUS_ACTIVE === $existing['status'] ) {
			return $existing;
		}

		if ( $capacity <= 0 ) {
			return self::write_pass( $event_id, $user_id, $existing );
		}

		if ( ! self::acquire_capacity_lock( $event_id ) ) {
			return null;
		}

		try {
			// Re-read under the lock: a concurrent request may have issued
			// this user's pass, or filled the last spot, since the check above.
			$existing = self::find_for_user_event( $event_id, $user_id );
			if ( $existing && self::STATUS_ACTIVE === $existing['status'] ) {
				return $existing;
			}

			$counts = self::count_for_event( $event_id );
			if ( $counts['claimed'] >= $capacity ) {
				return null;
			}

			return self::write_pass( $event_id, $user_id, $existing );
		} finally {
			self::release_capacity_lock( $event_id );
		}
	}

	/**
	 * Whether an event has room for one more pass for this user.
	 *
	 * A non-locking pre-check for friendly errors; issue_or_reactivate()
	 * remains the authority under its lock.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $user_id  User ID.
	 * @param int $capacity Capacity; 0 = unlimited.
	 * @return bool
	 */
	public static function has_room_for( int $event_id, int $user_id, int $capacity ): bool {
		if ( $capacity <= 0 ) {
			return true;
		}

		$existing = self::find_for_user_event( $event_id, $user_id );
		if ( $existing && self::STATUS_REVOKED !== $existing['status'] ) {
			return true;
		}

		return self::count_for_event( $event_id )['claimed'] < $capacity;
	}

	/**
	 * Per-event capacity lock name. Includes the table name so blogs and
	 * installs sharing a MySQL server never contend.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	private static function capacity_lock_name( int $event_id ): string {
		return substr( 'ec_rsvp_cap_' . md5( self::table_name() . ':' . $event_id ), 0, 64 );
	}

	/**
	 * Acquire the per-event capacity lock, waiting up to 5 seconds.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	private static function acquire_capacity_lock( int $event_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock is the concurrency primitive.
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::capacity_lock_name( $event_id ), 5 ) );
	}

	/**
	 * Release the per-event capacity lock.
	 *
	 * @param int $event_id Event post ID.
	 */
	private static function release_capacity_lock( int $event_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock is the concurrency primitive.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::capacity_lock_name( $event_id ) ) );
	}

	/**
	 * Insert a new pass, or reactivate an existing non-active row.
	 *
	 * @param int        $event_id Event post ID.
	 * @param int        $user_id  User ID.
	 * @param array|null $existing Existing non-active row, if any.
	 * @return array|null
	 */
	private static function write_pass( int $event_id, int $user_id, ?array $existing ): ?array {
		global $wpdb;
		$table = self::table_name();

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
	 * Issue a pass that belongs to no account (guest list, extrachill#433).
	 *
	 * The holder proves nothing but knowledge of the guest list password;
	 * the code itself is the bearer token they keep on their phone. Always
	 * issues a new row, so capacity is the only bound, and it is enforced
	 * under the same per-event lock as account passes.
	 *
	 * @param int    $event_id   Event post ID.
	 * @param int    $capacity   Max outstanding passes (active + redeemed). 0 = unlimited.
	 * @param string $guest_name Name the guest gave, shown at check-in.
	 * @return array|null The new active pass row, or null when full or the lock can't be taken.
	 */
	public static function issue_anonymous( int $event_id, int $capacity = 0, string $guest_name = '' ): ?array {
		if ( $capacity > 0 && ! self::acquire_capacity_lock( $event_id ) ) {
			return null;
		}

		try {
			if ( $capacity > 0 && self::count_for_event( $event_id )['claimed'] >= $capacity ) {
				return null;
			}

			global $wpdb;
			$now  = current_time( 'mysql', true );
			$code = self::generate_code();

			$inserted = $wpdb->insert(
				self::table_name(),
				array(
					'event_id'   => $event_id,
					'code'       => $code,
					'guest_name' => '' === $guest_name ? null : $guest_name,
					'status'     => self::STATUS_ACTIVE,
					'issued_at'  => $now,
					'updated_at' => $now,
				)
			);

			return false === $inserted ? null : self::find_by_code( $code );
		} finally {
			if ( $capacity > 0 ) {
				self::release_capacity_lock( $event_id );
			}
		}
	}

	/**
	 * List an event's account-less guest list passes for the door list.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $limit    Max rows. Clamped to 1-1000.
	 * @return array[] Pass rows, oldest first.
	 */
	public static function list_guest_passes( int $event_id, int $limit = 500 ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 1000, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d AND user_id IS NULL ORDER BY issued_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$event_id,
				$limit
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Redeem a pass by its code, for passes that belong to no account.
	 *
	 * @param int    $event_id            Event post ID the code must belong to.
	 * @param string $code                Exact pass code.
	 * @param int    $redeemed_by_user_id Host user ID performing the redemption.
	 * @return array|\WP_Error Same shape as redeem().
	 */
	public static function redeem_by_code( int $event_id, string $code, int $redeemed_by_user_id ) {
		$pass = '' === $code ? null : self::find_by_code( $code );
		if ( ! $pass || (int) $pass['event_id'] !== $event_id ) {
			return new \WP_Error( 'rsvp_pass_not_found', __( 'No pass found for this code.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		return self::redeem_row( $pass, $redeemed_by_user_id );
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
		$pass = self::find_for_user_event( $event_id, $user_id );
		if ( ! $pass ) {
			return new \WP_Error( 'rsvp_pass_not_found', __( 'No pass found for this attendee.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		return self::redeem_row( $pass, $redeemed_by_user_id );
	}

	/**
	 * Atomically redeem a loaded pass row (compare-and-set on status).
	 *
	 * @param array $pass                Pass row.
	 * @param int   $redeemed_by_user_id Host user ID.
	 * @return array|\WP_Error
	 */
	private static function redeem_row( array $pass, int $redeemed_by_user_id ) {
		global $wpdb;
		$table = self::table_name();

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
			$current = self::find_by_code( (string) $pass['code'] );
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
