<?php
/**
 * Owner's Guest List Storage (extrachill#1130)
 *
 * A permanent guest list owned by a venue (venue term) or a promoter
 * organization (promoter term), not by any one event: the owners' friends,
 * partners, regular engineers. One row per person, with one durable pass
 * code that works at every published event tagged with that venue or
 * promoter.
 *
 * Two tables:
 *  - members: the people on the list. Removing someone flips the row to
 *    `removed` and the code is dead at once; a re-add gets a fresh code.
 *  - check-ins: one row per (member, event). The unique key is the
 *    compare-and-set: the same person checks in at tonight's show and next
 *    week's, never twice at one show.
 *
 * Kept apart from the per-event passes table on purpose: a standing pass
 * has no event_id, never counts against an event's guest list spots, and
 * checks in many times (once per event), none of which fit a per-event
 * pass row. Codes use the same alphabet and format as per-event passes
 * (RsvpPassesTable::generate_code()), so the existing QR endpoint and
 * /rsvp-verify/ scan target serve them unchanged.
 *
 * Installed alongside the passes table, under RsvpPassesTable's schema
 * version (one option tracks every guest list table).
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Owns the owner's guest list tables: schema, members, per-event check-ins. */
final class OwnerGuestListTable {

	const STATUS_ACTIVE  = 'active';
	const STATUS_REMOVED = 'removed';

	/** Owner types, which are also the owning taxonomies. */
	const OWNER_VENUE    = 'venue';
	const OWNER_PROMOTER = 'promoter';

	/**
	 * Members table name. Site-scoped, like the passes table.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_owner_guest_list';
	}

	/**
	 * Check-ins table name.
	 *
	 * @return string
	 */
	public static function checkins_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_owner_guest_list_checkins';
	}

	/**
	 * Owner types that can hold a list.
	 *
	 * @return string[]
	 */
	public static function owner_types(): array {
		return array( self::OWNER_VENUE, self::OWNER_PROMOTER );
	}

	/**
	 * CREATE TABLE statements for dbDelta, one per table.
	 *
	 * @param string $charset Charset/collation clause.
	 * @return string[]
	 */
	public static function schema_sql( string $charset ): array {
		$members  = self::table_name();
		$checkins = self::checkins_table_name();

		return array(
			"CREATE TABLE {$members} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_type varchar(16) NOT NULL,
			owner_term_id bigint(20) unsigned NOT NULL,
			name varchar(100) NOT NULL,
			email varchar(200) NOT NULL DEFAULT '',
			code varchar(20) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'active',
			added_by_user_id bigint(20) unsigned NULL DEFAULT NULL,
			removed_by_user_id bigint(20) unsigned NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			removed_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY owner_status (owner_type, owner_term_id, status)
		) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$checkins} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			checked_in_at datetime NOT NULL,
			checked_in_by_user_id bigint(20) unsigned NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY member_event (member_id, event_id),
			KEY event_id (event_id)
		) ENGINE=InnoDB {$charset};",
		);
	}

	/**
	 * Put a person on an owner's list with a fresh pass code.
	 *
	 * @param string $owner_type       venue|promoter.
	 * @param int    $owner_term_id    Venue or promoter term ID.
	 * @param string $name             Name shown at the door.
	 * @param string $email            Optional email ('' for none).
	 * @param int    $added_by_user_id Team member adding them.
	 * @return array|null The new member row, or null on write failure.
	 */
	public static function create( string $owner_type, int $owner_term_id, string $name, string $email, int $added_by_user_id ): ?array {
		global $wpdb;
		$now = current_time( 'mysql', true );

		// A code collision on the unique key (75 bits; vanishingly rare)
		// fails the insert; one retry with a new code covers it.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$code     = RsvpPassesTable::generate_code();
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
				self::table_name(),
				array(
					'owner_type'    => $owner_type,
					'owner_term_id' => $owner_term_id,
					'name'          => $name,
					'email'         => $email,
					'code'          => $code,
					'status'        => self::STATUS_ACTIVE,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
				+ ( $added_by_user_id > 0 ? array( 'added_by_user_id' => $added_by_user_id ) : array() )
			);
			if ( false !== $inserted ) {
				return self::find( (int) $wpdb->insert_id );
			}
		}

		return null;
	}

	/**
	 * Find a member by ID, any status.
	 *
	 * @param int $member_id Member ID.
	 * @return array|null
	 */
	public static function find( int $member_id ): ?array {
		if ( $member_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Door checks must read live rows.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$member_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Find a member by exact pass code, any status.
	 *
	 * @param string $code Pass code.
	 * @return array|null
	 */
	public static function find_by_code( string $code ): ?array {
		if ( '' === $code ) {
			return null;
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Door checks must read live rows; a removed pass dies at once.
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
	 * Active people on one owner's list, oldest first.
	 *
	 * @param string $owner_type    venue|promoter.
	 * @param int    $owner_term_id Term ID.
	 * @return array[]
	 */
	public static function list_for_owner( string $owner_type, int $owner_term_id ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_type = %s AND owner_term_id = %d AND status = %s ORDER BY created_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$owner_type,
				$owner_term_id,
				self::STATUS_ACTIVE,
				500
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Take someone off an owner's list. Their code stops working at once;
	 * past check-ins stay on record.
	 *
	 * @param string $owner_type      venue|promoter the member must belong to.
	 * @param int    $owner_term_id   Term the member must belong to.
	 * @param int    $member_id       Member ID.
	 * @param int    $removed_by_user Team member removing them.
	 * @return bool Whether an active member was removed.
	 */
	public static function remove( string $owner_type, int $owner_term_id, int $member_id, int $removed_by_user ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; the status in WHERE is the compare-and-set.
			self::table_name(),
			array(
				'status'     => self::STATUS_REMOVED,
				'removed_at' => $now,
				'updated_at' => $now,
			)
			+ ( $removed_by_user > 0 ? array( 'removed_by_user_id' => $removed_by_user ) : array() ),
			array(
				'id'            => $member_id,
				'owner_type'    => $owner_type,
				'owner_term_id' => $owner_term_id,
				'status'        => self::STATUS_ACTIVE,
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * A member's check-in at one event, if any.
	 *
	 * @param int $member_id Member ID.
	 * @param int $event_id  Event post ID.
	 * @return array|null
	 */
	public static function find_checkin( int $member_id, int $event_id ): ?array {
		global $wpdb;
		$table = self::checkins_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Door state must read live rows.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE member_id = %d AND event_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$member_id,
				$event_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Every owner's-list check-in at one event, keyed by member ID.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<int, array>
	 */
	public static function list_checkins_for_event( int $event_id ): array {
		global $wpdb;
		$table = self::checkins_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Door state must read live rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_id = %d LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				$event_id,
				1000
			),
			ARRAY_A
		);

		$by_member = array();
		foreach ( (array) $rows as $row ) {
			$by_member[ (int) $row['member_id'] ] = $row;
		}
		return $by_member;
	}

	/**
	 * Check a member in at one event. Atomic: the (member, event) unique key
	 * is the compare-and-set, so a double-tap or two door staff at once
	 * record one check-in and the loser reads the winner's.
	 *
	 * Eligibility (active member, event tagged with the owner) is the
	 * caller's job; see extrachill_events_owner_list_check_in().
	 *
	 * @param int $member_id     Member ID.
	 * @param int $event_id      Event post ID.
	 * @param int $by_user_id    Door staff checking them in.
	 * @return array{already_redeemed: bool, redeemed_at: string, redeemed_by_user_id: int}
	 */
	public static function check_in( int $member_id, int $event_id, int $by_user_id ): array {
		$existing = self::find_checkin( $member_id, $event_id );
		if ( $existing ) {
			return self::checkin_result( $existing, true );
		}

		global $wpdb;
		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write; the unique key is the concurrency primitive.
			self::checkins_table_name(),
			array(
				'member_id'     => $member_id,
				'event_id'      => $event_id,
				'checked_in_at' => $now,
			)
			+ ( $by_user_id > 0 ? array( 'checked_in_by_user_id' => $by_user_id ) : array() )
		);

		if ( false === $inserted ) {
			// Lost the race on the unique key: report the winner's check-in.
			$winner = self::find_checkin( $member_id, $event_id );
			return $winner
				? self::checkin_result( $winner, true )
				: array(
					'already_redeemed'    => true,
					'redeemed_at'         => $now,
					'redeemed_by_user_id' => $by_user_id,
				);
		}

		return array(
			'already_redeemed'    => false,
			'redeemed_at'         => $now,
			'redeemed_by_user_id' => $by_user_id,
		);
	}

	/**
	 * Shape a check-in row like a pass redemption result.
	 *
	 * @param array $row     Check-in row.
	 * @param bool  $already Whether it predates this call.
	 * @return array{already_redeemed: bool, redeemed_at: string, redeemed_by_user_id: int}
	 */
	private static function checkin_result( array $row, bool $already ): array {
		return array(
			'already_redeemed'    => $already,
			'redeemed_at'         => (string) $row['checked_in_at'],
			'redeemed_by_user_id' => (int) ( $row['checked_in_by_user_id'] ?? 0 ),
		);
	}
}
