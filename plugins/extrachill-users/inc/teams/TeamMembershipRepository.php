<?php
/**
 * The one store for venue, promoter and artist team membership.
 *
 * Rows live in `ec_team_members`, keyed by (team_type, team_id, user_id).
 * Reads alias team_id back to `venue_term_id`, `promoter_term_id` or
 * `artist_id` so the typed facades keep their public row shapes.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChill\Users\Teams;

defined( 'ABSPATH' ) || exit;

/** Owns every query against the shared team membership table. */
class TeamMembershipRepository {
	public const STATUS_ACTIVE  = 'active';
	public const STATUS_INVITED = 'invited';
	public const STATUS_REVOKED = 'revoked';

	/** Supported team types. */
	public const TYPES = array( 'venue', 'promoter', 'artist' );

	/** The network's team membership table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'ec_team_members';
	}

	/** Network invitation storage for every team type. */
	public static function invitations_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'ec_team_invitations';
	}

	/** Network claim storage for every team type. */
	public static function claims_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'ec_team_claims';
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
			return new \WP_Error( $team_type . '_membership_read_failed', sprintf( /* translators: %s: team type, such as venue, promoter or artist. */ __( 'The %s membership could not be read.', 'extrachill-users' ), $team_type ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $team_type, $row ) : null;
	}

	/** Serialize an adapter-authorized ownership change or revocation. */
	public function change_member( TeamOnboardingAdapter $adapter, int $team_id, int $user_id, int $expected_version, ?bool $is_owner, bool $revoke, int $actor_user_id, bool $enforce_owner_floor = true ) {
		global $wpdb;
		if ( $team_id < 1 || $user_id < 1 || $expected_version < 1 || $actor_user_id < 1 ) {
			return $this->change_error( $adapter, 'invalid_id', 400 );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB -- Guarded membership transaction.
			return $this->change_error( $adapter, 'transaction_failed', 500 );
		}
		$result = $adapter->check_team( $team_id, true );
		if ( ! is_wp_error( $result ) ) {
			$rows   = $this->lock_team( $adapter->team_type(), $team_id );
			$result = '' !== (string) $wpdb->last_error ? $this->change_error( $adapter, 'read_failed', 500 ) : $this->hydrate_rows( $adapter->team_type(), $rows );
		}
		if ( ! is_wp_error( $result ) ) {
			$members = $result;
			$result  = $adapter->authorize_locked( $actor_user_id, $team_id, $members );
		}
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB -- No write has committed.
			return $result;
		}
		$members = $members ?? array();
		$current = null;
		$owners  = 0;
		foreach ( $members as $member ) {
			if ( $user_id === $member['user_id'] ) {
				$current = $member;
			}
			$owners += $member['is_owner'] && self::STATUS_ACTIVE === $member['status'] ? 1 : 0;
		}
		if ( null === $current ) {
			$result = $this->change_error( $adapter, 'not_found', 404 );
		} elseif ( $expected_version !== $current['version'] ) {
			$result = $this->change_error( $adapter, 'version_conflict', 409, array( 'current_version' => $current['version'] ) );
		} elseif ( self::STATUS_REVOKED === $current['status'] ) {
			$result = $this->change_error( $adapter, 'revoked', 409 );
		} elseif ( $enforce_owner_floor && $current['is_owner'] && self::STATUS_ACTIVE === $current['status'] && ( $revoke || false === $is_owner ) && $owners < 2 ) {
			$result = $this->change_error( $adapter, 'last_owner', 409 );
		} else {
			$result = $this->mutate( $adapter->team_type(), $team_id, $user_id, $expected_version, $is_owner, $revoke, $actor_user_id );
			if ( false === $result ) {
				$result = $this->change_error( $adapter, 'update_failed', 500 );
			} elseif ( 0 === $result ) {
				$result = $this->change_error( $adapter, 'version_conflict', 409, array( 'current_version' => $current['version'] ) );
			}
		}
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB -- Failed guarded change.
			return $result;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB -- A failed commit has an uncertain outcome; never roll back.
			return $this->change_error( $adapter, 'commit_uncertain', 500 );
		}
		return $this->get( $adapter->team_type(), $team_id, $user_id );
	}

	/** Serialize an adapter-authorized membership creation. */
	public function add_member( TeamOnboardingAdapter $adapter, int $team_id, int $user_id, bool $is_owner, int $actor_user_id, string $status = 'active', ?int $max_members = null ) {
		global $wpdb;
		if ( $team_id < 1 || $user_id < 1 || $actor_user_id < 1 ) {
			return $this->change_error( $adapter, 'invalid_id', 400 );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB -- Guarded membership transaction.
			return $this->change_error( $adapter, 'transaction_failed', 500 );
		}
		$result = $adapter->check_team( $team_id, true );
		if ( ! is_wp_error( $result ) ) {
			$rows   = $this->lock_team( $adapter->team_type(), $team_id );
			$result = '' !== (string) $wpdb->last_error ? $this->change_error( $adapter, 'read_failed', 500 ) : $this->hydrate_rows( $adapter->team_type(), $rows );
		}
		if ( ! is_wp_error( $result ) ) {
			$members = $result;
			$result  = $adapter->authorize_locked( $actor_user_id, $team_id, $members );
		}
		if ( ! is_wp_error( $result ) ) {
			$members   = $members ?? array();
			$has_owner = false;
			foreach ( $members as $member ) {
				if ( $user_id === $member['user_id'] ) {
					$result = $this->change_error( $adapter, 'exists', 409, array( 'current_version' => $member['version'] ) );
					break;
				}
				$has_owner = $has_owner || ( $member['is_owner'] && self::STATUS_ACTIVE === $member['status'] );
			}
			if ( ! is_wp_error( $result ) ) {
				if ( null !== $max_members && count( $members ) >= $max_members ) {
					$result = $this->change_error( $adapter, 'limit_reached', 409, array( 'maximum' => $max_members ) );
				} elseif ( self::STATUS_ACTIVE === $status && ! $is_owner && ! $has_owner ) {
					$result = $this->change_error( $adapter, 'owner_required', 409 );
				} else {
					$result = $this->insert( $adapter->team_type(), $this->new_row( $adapter->team_type(), $team_id, $user_id, $is_owner, $actor_user_id, $status ) );
					if ( false === $result ) {
						$winner = $this->get( $adapter->team_type(), $team_id, $user_id );
						$result = is_array( $winner ) ? $this->change_error( $adapter, 'exists', 409, array( 'current_version' => $winner['version'] ) ) : ( is_wp_error( $winner ) ? $winner : $this->change_error( $adapter, 'create_failed', 500 ) );
					}
				}
			}
		}
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB -- Failed guarded creation.
			return $result;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB -- A failed commit has an uncertain outcome; never roll back.
			return $this->change_error( $adapter, 'commit_uncertain', 500 );
		}
		return $this->get( $adapter->team_type(), $team_id, $user_id );
	}

	/** Map shared mutation failures through the type-owned public contract. */
	private function change_error( TeamOnboardingAdapter $adapter, string $reason, int $status, array $data = array() ): \WP_Error {
		$messages = array(
			/* translators: %s: team type, such as venue, promoter or artist. */
			'invalid_id'         => __( 'Positive identifiers are required for the %s membership.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'exists'             => __( 'A %s membership already exists for this user.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'owner_required'     => __( 'The first active %s member must be an owner.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'limit_reached'      => __( 'This %s team has reached the supported membership limit.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'create_failed'      => __( 'The %s membership could not be created.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'transaction_failed' => __( 'The %s membership transaction could not start.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'read_failed'        => __( 'The %s membership could not be read.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'not_found'          => __( 'The %s membership was not found.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'version_conflict'   => __( 'The %s membership changed since it was read.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'revoked'            => __( 'A revoked %s membership cannot be changed.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'last_owner'         => __( 'The final active %s owner cannot be removed.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'update_failed'      => __( 'The %s membership could not be changed.', 'extrachill-users' ),
			/* translators: %s: team type, such as venue, promoter or artist. */
			'commit_uncertain'   => __( 'The %s membership transaction outcome could not be confirmed.', 'extrachill-users' ),
		);
		return new \WP_Error(
			$adapter->error_code( 'team_membership_' . $reason ),
			sprintf( $messages[ $reason ], $adapter->team_type() ),
			array_merge( array( 'status' => $status ), $data )
		);
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
			return new \WP_Error( 'team_membership_read_failed', __( 'Team memberships could not be locked.', 'extrachill-users' ) );
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
		$statuses = array( 'active', 'invited', 'revoked' );
		if ( ! in_array( $row['status'] ?? '', $statuses, true ) ) {
			return new \WP_Error( $team_type . '_membership_corrupt_status', sprintf( /* translators: %s: team type, such as venue, promoter or artist. */ __( 'The stored %s membership status is invalid.', 'extrachill-users' ), $team_type ) );
		}
		if ( ! in_array( $row['is_owner'] ?? null, array( 0, 1, '0', '1', true, false ), true ) ) {
			return new \WP_Error( $team_type . '_membership_corrupt_owner', sprintf( /* translators: %s: team type, such as venue, promoter or artist. */ __( 'The stored %s membership ownership value is invalid.', 'extrachill-users' ), $team_type ) );
		}
		foreach ( array( 'id', $this->id_key( $team_type ), 'user_id', 'version', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['is_owner']           = (bool) (int) $row['is_owner'];
		$row['revoked_at']         = empty( $row['revoked_at'] ) ? null : (string) $row['revoked_at'];
		$row['revoked_by_user_id'] = empty( $row['revoked_by_user_id'] ) ? null : (int) $row['revoked_by_user_id'];
		return $row;
	}

	/** Build the initial row for a validated relationship. */
	public function new_row( string $team_type, int $term_id, int $user_id, bool $is_owner, int $creator_id, string $status = 'active' ): array {
		$now                       = gmdate( 'Y-m-d H:i:s' );
		$row                       = array(
			$this->id_key( $team_type ) => $term_id,
			'user_id'                   => $user_id,
			'is_owner'                  => $is_owner ? 1 : 0,
			'status'                    => $status,
			'version'                   => 1,
			'created_by_user_id'        => $creator_id,
			'created_at'                => $now,
			'updated_at'                => $now,
			'revoked_at'                => 'revoked' === $status ? $now : null,
		);
		$row['revoked_by_user_id'] = null;
		return $row;
	}

	/** Insert a validated typed relationship inside the caller's transaction. */
	public function insert( string $team_type, array $row ) {
		global $wpdb;
		$key              = $this->id_key( $team_type );
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
			$set[]    = 'revoked_by_user_id = %d';
			$values[] = $actor_user_id;
			$set[]    = 'revoked_at = %s';
			$values[] = $now;
		}
		array_push( $values, $this->type( $team_type ), $term_id, $user_id, $version );
		$query = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE team_type = %s AND team_id = %d AND user_id = %d AND version = %d';
		return $wpdb->query( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed columns; every value is prepared.
	}

	/** Typed read columns, aliasing the shared term key back to the facade's key. */
	private function columns( string $team_type, string $alias = '' ): string {
		$key       = $this->id_key( $team_type );
		$columns   = array( 'id', "team_id AS {$key}", 'user_id', 'is_owner', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_at', 'revoked_at' );
		$columns[] = 'revoked_by_user_id';
		return $alias . implode( ', ' . $alias, $columns );
	}

	/** Public identifier key for each typed relationship. */
	public function id_key( string $team_type ): string {
		return 'artist' === $team_type ? 'artist_id' : $team_type . '_term_id';
	}

	/** Accept only the supported team types. */
	private function type( string $team_type ): string {
		if ( ! in_array( $team_type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported membership team type.' );
		}
		return $team_type;
	}
}
