<?php
/**
 * Guest List Party Storage (extrachill#1128)
 *
 * One row per invited party: a band or artist management a venue/promoter
 * host invited to fill guest list spots for one event. The party gets a
 * private link; through it they add guest names with no password and no
 * account. Passes they issue carry the party's ID (RsvpPassesTable
 * `party_id`), so the door list can show "Jake (Slim S.O.U.L.)" and an
 * allotment can be counted per party.
 *
 * The invite token is a 256-bit bearer secret, mailed once. Only its HMAC
 * is stored (same shape as VenueInvitationToken): a database read never
 * yields a working link. Revoking a party kills the link forever; the
 * passes it already issued stay on the list, and the host can remove them
 * one by one with the existing remove flow.
 *
 * Installed alongside the passes table, under RsvpPassesTable's schema
 * version, so one option tracks both.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Owns the guest list party table: schema, invite tokens, lookups, revocation. */
final class GuestListPartiesTable {

	const STATUS_ACTIVE  = 'active';
	const STATUS_REVOKED = 'revoked';

	/**
	 * Full table name. Site-scoped, like the passes table.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_event_guest_list_parties';
	}

	/**
	 * CREATE TABLE statement for dbDelta.
	 *
	 * @param string $charset Charset/collation clause.
	 * @return string
	 */
	public static function schema_sql( string $charset ): string {
		$table = self::table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			name varchar(100) NOT NULL,
			email varchar(200) NOT NULL DEFAULT '',
			token_hash char(64) NOT NULL,
			allotment int(10) unsigned NULL DEFAULT NULL,
			status varchar(16) NOT NULL DEFAULT 'active',
			created_by_user_id bigint(20) unsigned NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			revoked_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY event_status (event_id, status)
		) ENGINE=InnoDB {$charset};";
	}

	/**
	 * Generate fresh invite token material (256 bits, hex).
	 *
	 * @return string
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Keyed hash of a raw invite token. The only form ever stored.
	 *
	 * @param string $token Raw token.
	 * @return string 64 hex chars.
	 */
	public static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', 'guest-list-party|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Insert a party and return it with its one-time raw token.
	 *
	 * @param int      $event_id           Event post ID.
	 * @param string   $name               Party name shown on the door list.
	 * @param string   $email              Where the invite went.
	 * @param int|null $allotment          Per-party spot cap; null = none set.
	 * @param int      $created_by_user_id Host who invited them.
	 * @return array{party: array, token: string}|null Null on write failure.
	 */
	public static function create( int $event_id, string $name, string $email, ?int $allotment, int $created_by_user_id ): ?array {
		global $wpdb;

		$token = self::generate_token();
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
			self::table_name(),
			array(
				'event_id'   => $event_id,
				'name'       => $name,
				'email'      => $email,
				'token_hash' => self::hash_token( $token ),
				'status'     => self::STATUS_ACTIVE,
				'created_at' => $now,
				'updated_at' => $now,
			)
			+ ( null !== $allotment ? array( 'allotment' => max( 0, $allotment ) ) : array() )
			+ ( $created_by_user_id > 0 ? array( 'created_by_user_id' => $created_by_user_id ) : array() )
		);

		if ( false === $inserted ) {
			return null;
		}

		$party = self::find( (int) $wpdb->insert_id );
		return $party ? array(
			'party' => $party,
			'token' => $token,
		) : null;
	}

	/**
	 * Find a party by ID.
	 *
	 * @param int $party_id Party ID.
	 * @return array|null
	 */
	public static function find( int $party_id ): ?array {
		if ( $party_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$party_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Find a party by its raw invite token, regardless of status.
	 *
	 * @param string $token Raw token from the invite link.
	 * @return array|null
	 */
	public static function find_by_token( string $token ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}

		global $wpdb;
		$table = self::table_name();
		$hash  = self::hash_token( $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$hash
			),
			ARRAY_A
		);

		// The unique index did the lookup; compare in constant time anyway.
		return ( $row && hash_equals( (string) $row['token_hash'], $hash ) ) ? $row : null;
	}

	/**
	 * List an event's parties, oldest first.
	 *
	 * @param int $event_id Event post ID.
	 * @return array[]
	 */
	public static function list_for_event( int $event_id ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$event_id,
				500
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Revoke an active party: its link stops working. Passes stay.
	 *
	 * @param int $event_id Event the party must belong to.
	 * @param int $party_id Party ID.
	 * @return bool Whether a party was revoked.
	 */
	public static function revoke( int $event_id, int $party_id ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
			self::table_name(),
			array(
				'status'     => self::STATUS_REVOKED,
				'revoked_at' => $now,
				'updated_at' => $now,
			),
			array(
				'id'       => $party_id,
				'event_id' => $event_id,
				'status'   => self::STATUS_ACTIVE,
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Set (or clear, with null) a party's allotment.
	 *
	 * @param int      $event_id  Event the party must belong to.
	 * @param int      $party_id  Party ID.
	 * @param int|null $allotment Spots; null clears it.
	 * @return bool Whether the party exists on the event.
	 */
	public static function set_allotment( int $event_id, int $party_id, ?int $allotment ): bool {
		$party = self::find( $party_id );
		if ( ! $party || (int) $party['event_id'] !== $event_id ) {
			return false;
		}

		global $wpdb;
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
			self::table_name(),
			array(
				'allotment'  => null === $allotment ? null : max( 0, $allotment ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $party_id )
		);

		return false !== $updated;
	}
}
