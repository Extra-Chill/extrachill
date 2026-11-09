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

	/** Read a bounded team or active-user projection; facades retain error policy. */
	public function list_rows( string $team_type, int $id, int $limit, bool $by_user = false ): array {
		global $wpdb;
		$table = $this->table( $team_type );
		$field = $team_type . '_term_id';
		$where = $by_user ? "user_id = %d AND status = 'active'" : "{$field} = %d";
		$order = $by_user ? "{$field} ASC, id ASC" : 'created_at ASC, id ASC';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT %d", $id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed typed columns and prepared bounded projection.
	}

	/** Test active authority against a current identity and, for promoters, organization. */
	public function has_active_identity( string $team_type, int $user_id ): bool {
		global $wpdb;
		$table = $this->table( $team_type );
		$field = $team_type . '_term_id';
		$join  = '';
		if ( 'promoter' === $team_type ) {
			$organizations = PromoterAuthoritySchema::organizations_table();
			$join          = " INNER JOIN {$organizations} AS organization ON organization.promoter_term_id = membership.{$field} AND organization.status = 'active'";
		}
		$values = array( $team_type, $user_id, 'active' );
		$found  = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} AS membership{$join} INNER JOIN {$wpdb->term_taxonomy} AS identity ON identity.term_id = membership.{$field} AND identity.taxonomy = %s WHERE membership.user_id = %d AND membership.status = %s LIMIT 1", $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed typed join and prepared authority values.
		return null !== $found;
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

	/** Build the initial row for a validated relationship. */
	public function new_row( string $team_type, int $term_id, int $user_id, bool $is_owner, int $creator_id, string $status = 'active' ): array {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			$team_type . '_term_id' => $term_id,
			'user_id'               => $user_id,
			'is_owner'              => $is_owner ? 1 : 0,
			'status'                => $status,
			'version'               => 1,
			'created_by_user_id'    => $creator_id,
			'created_at'            => $now,
			'updated_at'            => $now,
			'revoked_at'            => 'revoked' === $status ? $now : null,
		);
		if ( 'promoter' === $team_type ) {
			$row['revoked_by_user_id'] = null;
		}
		return $row;
	}

	/** Insert a validated relationship inside the caller's transaction. */
	public function insert( string $team_type, array $row ) {
		global $wpdb;
		return $wpdb->insert( $this->table( $team_type ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Typed repository owns validation and transaction.
	}

	/** Update a validated relationship at its expected version. */
	public function update( string $team_type, int $term_id, int $user_id, int $version, array $data ) {
		global $wpdb;
		$where = array_combine( array( $team_type . '_term_id', 'user_id', 'version' ), array( $term_id, $user_id, $version ) );
		return $wpdb->update( $this->table( $team_type ), $data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Natural key and optimistic version are always included.
	}

	/** Apply a versioned owner change or revocation under the caller's lock. */
	public function mutate( string $team_type, int $term_id, int $user_id, int $version, ?bool $is_owner, bool $revoke, int $actor_user_id ) {
		global $wpdb;
		$table  = $this->table( $team_type );
		$field  = $team_type . '_term_id';
		$now    = gmdate( 'Y-m-d H:i:s' );
		$set    = array( 'version = version + 1', 'updated_at = %s' );
		$values = array( $now );
		if ( null !== $is_owner ) {
			$set[]    = 'is_owner = %d';
			$values[] = $is_owner ? 1 : 0;
		}
		if ( $revoke ) {
			$set[]    = 'status = %s';
			$values[] = 'revoked';
			if ( 'promoter' === $team_type ) {
				$set[]    = 'revoked_by_user_id = %d';
				$values[] = $actor_user_id;
			}
			$set[]    = 'revoked_at = %s';
			$values[] = $now;
		}
		$values[] = $term_id;
		$values[] = $user_id;
		$values[] = $version;
		$query    = "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE {$field} = %d AND user_id = %d AND version = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed columns and trusted typed table.
		return $wpdb->query( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- All dynamic values are prepared.
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
