<?php
/**
 * The one store for venue and promoter team membership.
 *
 * Rows live in `ec_team_members`, keyed by (team_type, team_id, user_id).
 * Reads alias team_id back to the typed `venue_term_id` / `promoter_term_id`
 * key so the typed facades keep their public row shapes.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChill\Users\Teams;

defined( 'ABSPATH' ) || exit;

/** Owns every query against the shared team membership table. */
class TeamMembershipRepository {
	/** Supported team types. */
	public const TYPES = array( 'venue', 'promoter' );

	/** The network's team membership table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'ec_team_members';
	}

	/** Read one typed relationship, preserving the facade's error contract. */
	public function get( string $team_type, int $term_id, int $user_id ) {
		global $wpdb;
		$sql = $this->sql(
			$team_type,
			array(
				'term'    => $term_id,
				'user_id' => $user_id,
			),
			'id ASC',
			1
		);
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by sql().
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( $team_type . '_membership_read_failed', 'venue' === $team_type ? __( 'The venue membership could not be read.', 'extrachill-events' ) : __( 'The promoter membership could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $team_type, $row ) : null;
	}

	/** Lock a team's raw rows; the caller owns the transaction and error policy. */
	public function lock_team( string $team_type, int $term_id, ?int $limit = null ): array {
		return $this->select( $team_type, array( 'term' => $term_id ), 'id ASC', $limit, true );
	}

	/** Lock every team relationship for account compensation, including other team types. */
	public function lock_user( int $user_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT *, team_id AS team_term_id FROM {$table} WHERE user_id = %d ORDER BY team_type ASC, team_id ASC, id ASC FOR UPDATE", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB -- Fixed table and prepared user; caller owns the transaction.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'team_membership_read_failed', __( 'Team memberships could not be locked.', 'extrachill-events' ) );
		}
		return (array) $rows;
	}

	/** Lock an exact raw relationship inside the caller's transaction. */
	public function lock_member( string $team_type, int $term_id, int $user_id ): array {
		return $this->select(
			$team_type,
			array(
				'term'    => $term_id,
				'user_id' => $user_id,
			),
			'id ASC',
			null,
			true
		);
	}

	/**
	 * Read typed rows matching exact criteria.
	 *
	 * @param string   $team_type Team type.
	 * @param array    $criteria  Any of term, user_id, status, is_owner.
	 * @param string   $order     Fixed ORDER BY clause.
	 * @param int|null $limit     Optional bound.
	 * @param bool     $lock      Whether to lock the rows for update.
	 * @param int      $offset    Optional page offset.
	 */
	public function select( string $team_type, array $criteria, string $order, ?int $limit = null, bool $lock = false, int $offset = 0 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $this->sql( $team_type, $criteria, $order, $limit, $lock, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by sql().
	}

	/** Read the team term IDs of rows matching exact criteria, in term order. */
	public function term_ids( string $team_type, array $criteria, ?int $limit = null ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $this->sql( $team_type, $criteria, 'team_id ASC', $limit, false, 0, 'team_id' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by sql().
	}

	/** Build one prepared typed read; see select() for parameters. */
	private function sql( string $team_type, array $criteria, string $order, ?int $limit = null, bool $lock = false, int $offset = 0, string $columns = '' ): string {
		global $wpdb;
		$table  = self::table();
		$where  = array( 'team_type = %s' );
		$values = array( $this->type( $team_type ) );
		foreach ( array(
			'term'     => 'team_id = %d',
			'user_id'  => 'user_id = %d',
			'status'   => 'status = %s',
			'is_owner' => 'is_owner = %d',
		) as $key => $clause ) {
			if ( array_key_exists( $key, $criteria ) ) {
				$where[]  = $clause;
				$values[] = 'is_owner' === $key ? (int) (bool) $criteria[ $key ] : $criteria[ $key ];
			}
		}
		$query = 'SELECT ' . ( '' !== $columns ? $columns : $this->columns( $team_type ) ) . " FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order}";
		if ( null !== $limit ) {
			$query   .= $offset > 0 ? ' LIMIT %d OFFSET %d' : ' LIMIT %d';
			$values[] = $limit;
			if ( $offset > 0 ) {
				$values[] = $offset;
			}
		}
		return $wpdb->prepare( $query . ( $lock ? ' FOR UPDATE' : '' ), $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed columns and clauses; every value is prepared.
	}

	/** List one team's members, optionally only when the actor is an active owner of it. */
	public function list_team( string $team_type, int $term_id, array $criteria, int $limit, int $offset, int $owner_actor_id = 0 ): array {
		global $wpdb;
		$table  = self::table();
		$from   = "{$table} AS member";
		$where  = array( 'member.team_type = %s', 'member.team_id = %d' );
		$values = array( $this->type( $team_type ), $term_id );
		if ( $owner_actor_id > 0 ) {
			$from .= " INNER JOIN {$table} AS actor ON actor.team_type = member.team_type AND actor.team_id = member.team_id AND actor.user_id = %d AND actor.is_owner = 1 AND actor.status = 'active'";
			array_unshift( $values, $owner_actor_id );
		}
		if ( array_key_exists( 'is_owner', $criteria ) ) {
			$where[]  = 'member.is_owner = %d';
			$values[] = $criteria['is_owner'] ? 1 : 0;
		}
		if ( isset( $criteria['status'] ) ) {
			$where[]  = 'member.status = %s';
			$values[] = $criteria['status'];
		}
		$values[] = $limit;
		$values[] = $offset;
		$query    = 'SELECT ' . $this->columns( $team_type, 'member.' ) . " FROM {$from} WHERE " . implode( ' AND ', $where ) . ' ORDER BY member.created_at ASC, member.id ASC LIMIT %d OFFSET %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed clauses; every value is prepared.
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
		$statuses = $venue ? array( 'active', 'invited', 'revoked' ) : array( 'active', 'revoked' );
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

	/** Insert a validated typed relationship inside the caller's transaction. */
	public function insert( string $team_type, array $row ) {
		global $wpdb;
		$key              = $team_type . '_term_id';
		$row['team_type'] = $this->type( $team_type );
		$row['team_id']   = (int) $row[ $key ];
		unset( $row[ $key ] );
		return $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Typed repository owns validation and transaction.
	}

	/** Update a validated relationship at its expected version. */
	public function update( string $team_type, int $term_id, int $user_id, int $version, array $data ) {
		global $wpdb;
		$where = array(
			'team_type' => $this->type( $team_type ),
			'team_id'   => $term_id,
			'user_id'   => $user_id,
			'version'   => $version,
		);
		return $wpdb->update( self::table(), $data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Natural key and optimistic version are always included.
	}

	/** Apply a versioned owner change or revocation under the caller's lock. */
	public function mutate( string $team_type, int $term_id, int $user_id, int $version, ?bool $is_owner, bool $revoke, int $actor_user_id ) {
		global $wpdb;
		$table  = self::table();
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
		array_push( $values, $this->type( $team_type ), $term_id, $user_id, $version );
		$query = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE team_type = %s AND team_id = %d AND user_id = %d AND version = %d';
		return $wpdb->query( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed columns; every value is prepared.
	}

	/** Typed read columns, aliasing the shared term key back to the facade's key. */
	private function columns( string $team_type, string $alias = '' ): string {
		$columns = array( 'id', "team_id AS {$team_type}_term_id", 'user_id', 'is_owner', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_at', 'revoked_at' );
		if ( 'promoter' === $team_type ) {
			$columns[] = 'revoked_by_user_id';
		}
		return $alias . implode( ', ' . $alias, $columns );
	}

	/** Accept only the supported team types. */
	private function type( string $team_type ): string {
		if ( ! in_array( $team_type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported membership team type.' );
		}
		return $team_type;
	}
}
