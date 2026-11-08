<?php
/**
 * Shared membership row persistence for typed authority repositories.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Centralizes natural-key reads, row locks and authority hydration. */
class TeamMembershipRepository {
	/** Read one typed relationship, preserving the facade's error contract. */
	public function get( string $team_type, int $term_id, int $user_id ) {
		global $wpdb;
		$table = $this->table( $team_type );
		$field = $team_type . '_term_id';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$field} = %d AND user_id = %d LIMIT 1", $term_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed typed storage identifiers and prepared natural key.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( $team_type . '_membership_read_failed', 'venue' === $team_type ? __( 'The venue membership could not be read.', 'extrachill-events' ) : __( 'The promoter membership could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $team_type, $row ) : null;
	}

	/** Lock a team's raw rows; the caller owns the transaction and error policy. */
	public function lock_team( string $team_type, int $term_id, ?int $limit = null ): array {
		global $wpdb;
		$table  = $this->table( $team_type );
		$field  = $team_type . '_term_id';
		$query  = "SELECT * FROM {$table} WHERE {$field} = %d ORDER BY id ASC";
		$values = array( $term_id );
		if ( null !== $limit ) {
			$query   .= ' LIMIT %d';
			$values[] = $limit;
		}
		return (array) $wpdb->get_results( $wpdb->prepare( $query . ' FOR UPDATE', ...$values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed typed storage identifiers and prepared range.
	}

	/** Lock an exact raw relationship inside the caller's transaction. */
	public function lock_member( string $team_type, int $term_id, int $user_id ): array {
		global $wpdb;
		$table = $this->table( $team_type );
		$field = $team_type . '_term_id';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$field} = %d AND user_id = %d FOR UPDATE", $term_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact caller-owned member lock.
	}

	/** Hydrate a row set, failing closed on the first corrupt relationship. */
	public function hydrate_rows( string $team_type, array $rows ) {
		$memberships = array();
		foreach ( $rows as $row ) {
			$membership = $this->hydrate( $team_type, $row );
			if ( is_wp_error( $membership ) ) {
				return $membership;
			}
			$memberships[] = $membership;
		}
		return $memberships;
	}

	/** Normalize authority scalars while preserving each typed facade's keys. */
	public function hydrate( string $team_type, array $row ) {
		$venue    = 'venue' === $team_type;
		$statuses = $venue ? VenueAuthorization::statuses() : PromoterAuthorityRepository::statuses();
		if ( ! in_array( $row['status'] ?? '', $statuses, true ) ) {
			return new \WP_Error( $team_type . '_membership_corrupt_status', $venue ? __( 'The stored venue membership status is invalid.', 'extrachill-events' ) : __( 'The stored promoter membership status is invalid.', 'extrachill-events' ) );
		}
		if ( ! in_array( $row['is_owner'] ?? null, array( 0, 1, '0', '1' ), true ) ) {
			return new \WP_Error( $team_type . '_membership_corrupt_owner', $venue ? __( 'The stored venue membership ownership value is invalid.', 'extrachill-events' ) : __( 'The stored promoter membership ownership value is invalid.', 'extrachill-events' ) );
		}
		foreach ( array( 'id', $team_type . '_term_id', 'user_id', 'version', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['is_owner']   = (bool) (int) $row['is_owner'];
		$row['revoked_at'] = empty( $row['revoked_at'] ) ? null : (string) $row['revoked_at'];
		if ( ! $venue ) {
			$row['revoked_by_user_id'] = empty( $row['revoked_by_user_id'] ) ? null : (int) $row['revoked_by_user_id'];
		}
		return $row;
	}

	/** Resolve only the two supported authority types to trusted storage names. */
	private function table( string $team_type ): string {
		if ( 'venue' === $team_type ) {
			return VenueSchema::memberships_table();
		}
		if ( 'promoter' === $team_type ) {
			return PromoterAuthoritySchema::memberships_table();
		}
		throw new \InvalidArgumentException( 'Unsupported membership team type.' );
	}
}
