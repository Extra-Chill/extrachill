<?php
/**
 * Site-scoped venue membership persistence.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides bounded persistence for venue/user relationships. */
class VenueMembershipRepository {

	/** Create one unique venue/user relationship. */
	public function create( array $data ) {
		global $wpdb;

		$venue_term_id = $this->positive_id( $data['venue_term_id'] ?? null, 'venue_term_id' );
		$user_id       = $this->positive_id( $data['user_id'] ?? null, 'user_id' );
		$creator_id    = $this->positive_id( $data['created_by_user_id'] ?? null, 'created_by_user_id' );
		foreach ( array( $venue_term_id, $user_id, $creator_id ) as $validated ) {
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}

		$venue = get_term( $venue_term_id, 'venue' );
		if ( ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_venue_membership_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ) );
		}
		if ( ! get_userdata( $user_id ) || ! get_userdata( $creator_id ) ) {
			return new \WP_Error( 'invalid_venue_membership_user', __( 'Venue memberships require existing network users.', 'extrachill-events' ) );
		}

		$is_owner = ! empty( $data['is_owner'] );
		$status   = sanitize_key( (string) ( $data['status'] ?? VenueAuthorization::STATUS_ACTIVE ) );
		if ( ! in_array( $status, VenueAuthorization::statuses(), true ) ) {
			return new \WP_Error( 'invalid_venue_membership_status', __( 'The venue membership status is not supported.', 'extrachill-events' ) );
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$row   = array(
			'venue_term_id'      => $venue_term_id,
			'user_id'            => $user_id,
			'is_owner'           => $is_owner ? 1 : 0,
			'status'             => $status,
			'version'            => 1,
			'created_by_user_id' => $creator_id,
			'created_at'         => $now,
			'updated_at'         => $now,
			'revoked_at'         => VenueAuthorization::STATUS_REVOKED === $status ? $now : null,
		);
		$table = BookingSchema::memberships_table();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes authorization and membership creation.
			return new \WP_Error( 'venue_membership_transaction_failed', __( 'The venue membership transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$locked = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks the venue authority range through the venue-prefixed unique index.
		if ( '' !== (string) $wpdb->last_error ) {
			$database_error = $wpdb->last_error;
			$this->rollback();
			return new \WP_Error( 'venue_membership_read_failed', __( 'Venue memberships could not be locked.', 'extrachill-events' ), array( 'database_error' => $database_error ) );
		}
		$memberships = $this->hydrate_locked( (array) $locked );
		if ( is_wp_error( $memberships ) ) {
			$this->rollback();
			return $memberships;
		}
		if ( ! $this->actor_can_manage_members( $creator_id, $memberships ) ) {
			$this->rollback();
			return $this->forbidden();
		}
		$has_active_owner = false;
		foreach ( $memberships as $existing ) {
			if ( $existing['user_id'] === $user_id ) {
				$this->rollback();
				return $this->conflict( $existing );
			}
			if ( $existing['is_owner'] && VenueAuthorization::STATUS_ACTIVE === $existing['status'] ) {
				$has_active_owner = true;
			}
		}
		if ( VenueAuthorization::STATUS_ACTIVE === $status && ! $is_owner && ! $has_active_owner ) {
			$this->rollback();
			return new \WP_Error( 'venue_membership_owner_required', __( 'The first active venue member must be an owner.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational table write.
		if ( false === $wpdb->insert( $table, $row ) ) {
			$database_error = $wpdb->last_error;
			$winner         = $this->get( $venue_term_id, $user_id );
			if ( is_array( $winner ) ) {
				$this->rollback();
				return $this->conflict( $winner );
			}
			if ( is_wp_error( $winner ) ) {
				$this->rollback();
				return $winner;
			}
			$this->rollback();
			return new \WP_Error(
				'venue_membership_create_failed',
				__( 'The venue membership could not be created.', 'extrachill-events' ),
				array( 'database_error' => $database_error )
			);
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes serialized creation.
			$database_error = $wpdb->last_error;
			return new \WP_Error( 'venue_membership_commit_uncertain', __( 'The venue membership transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $database_error ) );
		}

		return $this->get( $venue_term_id, $user_id );
	}

	/** Get one relationship by its venue/user natural key. */
	public function get( int $venue_term_id, int $user_id ) {
		global $wpdb;
		$table = BookingSchema::memberships_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d AND user_id = %d LIMIT 1", $venue_term_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_read_failed', __( 'The venue membership could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** Get an active relationship, returning null for invited or revoked rows. */
	public function get_active( int $venue_term_id, int $user_id ) {
		$membership = $this->get( $venue_term_id, $user_id );
		if ( is_wp_error( $membership ) || ! is_array( $membership ) ) {
			return $membership;
		}
		return VenueAuthorization::STATUS_ACTIVE === $membership['status'] ? $membership : null;
	}

	/** List memberships for one venue with bounded filters. */
	public function list_for_venue( int $venue_term_id, array $filters = array(), int $actor_user_id = 0 ) {
		global $wpdb;

		$table      = BookingSchema::memberships_table();
		$table_from = "{$table} AS member";
		$where      = array( 'member.venue_term_id = %d' );
		$values     = array( $venue_term_id );
		if ( $actor_user_id > 0 && ! user_can( $actor_user_id, 'manage_options' ) ) {
			$table_from .= " INNER JOIN {$table} AS actor ON actor.venue_term_id = member.venue_term_id AND actor.user_id = %d AND actor.is_owner = 1 AND actor.status = 'active'";
			array_unshift( $values, $actor_user_id );
		}
		if ( array_key_exists( 'is_owner', $filters ) ) {
			$where[]  = 'member.is_owner = %d';
			$values[] = $filters['is_owner'] ? 1 : 0;
		}
		if ( ! empty( $filters['status'] ) ) {
			$status = sanitize_key( (string) $filters['status'] );
			if ( ! in_array( $status, VenueAuthorization::statuses(), true ) ) {
				return new \WP_Error( 'invalid_venue_membership_status', __( 'The venue membership filter is not supported.', 'extrachill-events' ) );
			}
			$where[]  = 'member.status = %s';
			$values[] = $status;
		}

		$limit    = max( 1, min( 100, absint( $filters['limit'] ?? 50 ) ) );
		$offset   = max( 0, absint( $filters['offset'] ?? 0 ) );
		$values[] = $limit;
		$values[] = $offset;
		$query    = "SELECT member.* FROM {$table_from} WHERE " . implode( ' AND ', $where ) . ' ORDER BY member.created_at ASC, member.id ASC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table and fixed clauses.
		$rows     = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic values are prepared.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_list_failed', __( 'Venue memberships could not be listed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}

		$memberships = array();
		foreach ( (array) $rows as $row ) {
			$membership = $this->hydrate( $row );
			if ( is_wp_error( $membership ) ) {
				return $membership;
			}
			$memberships[] = $membership;
		}
		return $memberships;
	}

	/** Change structural ownership at an expected version. */
	public function update_owner( int $venue_term_id, int $user_id, bool $is_owner, int $expected_version, int $actor_user_id ) {
		return $this->mutate( $venue_term_id, $user_id, $expected_version, $is_owner, null, $actor_user_id );
	}

	/** Revoke one relationship at an expected version while preserving an active owner. */
	public function revoke( int $venue_term_id, int $user_id, int $expected_version, int $actor_user_id ) {
		return $this->mutate( $venue_term_id, $user_id, $expected_version, null, VenueAuthorization::STATUS_REVOKED, $actor_user_id );
	}

	/** Hydrate scalar fields and fail closed on corrupt status values. */
	public function hydrate( array $row ) {
		if ( ! in_array( $row['status'] ?? '', VenueAuthorization::statuses(), true ) ) {
			return new \WP_Error( 'venue_membership_corrupt_status', __( 'The stored venue membership status is invalid.', 'extrachill-events' ) );
		}
		if ( ! in_array( $row['is_owner'] ?? null, array( 0, 1, '0', '1' ), true ) ) {
			return new \WP_Error( 'venue_membership_corrupt_owner', __( 'The stored venue membership ownership value is invalid.', 'extrachill-events' ) );
		}
		foreach ( array( 'id', 'venue_term_id', 'user_id', 'version', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['is_owner']   = (bool) (int) ( $row['is_owner'] ?? 0 );
		$row['revoked_at'] = empty( $row['revoked_at'] ) ? null : (string) $row['revoked_at'];
		return $row;
	}

	/** Execute one serialized membership mutation. */
	private function mutate( int $venue_term_id, int $user_id, int $expected_version, ?bool $is_owner, ?string $status, int $actor_user_id ) {
		global $wpdb;

		if ( $venue_term_id < 1 || $user_id < 1 || $expected_version < 1 || $actor_user_id < 1 ) {
			return new \WP_Error( 'invalid_venue_membership_id', __( 'Positive venue, user, and version identifiers are required.', 'extrachill-events' ) );
		}

		$table = BookingSchema::memberships_table();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for last-owner serialization.
			return new \WP_Error( 'venue_membership_transaction_failed', __( 'The venue membership transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}

		$locked = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes membership mutations for one venue.
		if ( '' !== (string) $wpdb->last_error ) {
			$database_error = $wpdb->last_error;
			$this->rollback();
			return new \WP_Error( 'venue_membership_read_failed', __( 'Venue memberships could not be locked.', 'extrachill-events' ), array( 'database_error' => $database_error ) );
		}

		$current      = null;
		$active_owner = 0;
		$memberships  = $this->hydrate_locked( (array) $locked );
		if ( is_wp_error( $memberships ) ) {
			$this->rollback();
			return $memberships;
		}
		foreach ( $memberships as $membership ) {
			if ( $membership['user_id'] === $user_id ) {
				$current = $membership;
			}
			if ( $membership['is_owner'] && VenueAuthorization::STATUS_ACTIVE === $membership['status'] ) {
				++$active_owner;
			}
		}
		if ( ! $this->actor_can_manage_members( $actor_user_id, $memberships ) ) {
			$this->rollback();
			return $this->forbidden();
		}

		if ( null === $current ) {
			$this->rollback();
			return new \WP_Error( 'venue_membership_not_found', __( 'The venue membership was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( $current['version'] !== $expected_version ) {
			$this->rollback();
			return $this->version_conflict( $current );
		}

		$removes_owner = $current['is_owner']
			&& VenueAuthorization::STATUS_ACTIVE === $current['status']
			&& ( false === $is_owner || VenueAuthorization::STATUS_REVOKED === $status );
		if ( $removes_owner && $active_owner < 2 ) {
			$this->rollback();
			return new \WP_Error( 'venue_membership_last_owner', __( 'The final active venue owner cannot be removed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$set    = array( 'version = version + 1', 'updated_at = %s' );
		$values = array( gmdate( 'Y-m-d H:i:s' ) );
		if ( null !== $is_owner ) {
			$set[]    = 'is_owner = %d';
			$values[] = $is_owner ? 1 : 0;
		}
		if ( null !== $status ) {
			$set[]    = 'status = %s';
			$values[] = $status;
			$set[]    = 'revoked_at = %s';
			$values[] = gmdate( 'Y-m-d H:i:s' );
		}
		$values[] = $venue_term_id;
		$values[] = $user_id;
		$values[] = $expected_version;
		$query    = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE venue_term_id = %d AND user_id = %d AND version = %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed columns and trusted table.
		$result   = $wpdb->query( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic values are prepared.
		if ( false === $result ) {
			$database_error = $wpdb->last_error;
			$this->rollback();
			return new \WP_Error( 'venue_membership_update_failed', __( 'The venue membership could not be updated.', 'extrachill-events' ), array( 'database_error' => $database_error ) );
		}
		if ( 0 === $result ) {
			$this->rollback();
			$latest = $this->get( $venue_term_id, $user_id );
			return is_array( $latest ) ? $this->version_conflict( $latest ) : new \WP_Error( 'venue_membership_not_found', __( 'The venue membership was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes serialized mutation.
			$database_error = $wpdb->last_error;
			return new \WP_Error( 'venue_membership_commit_uncertain', __( 'The venue membership transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $database_error ) );
		}
		return $this->get( $venue_term_id, $user_id );
	}

	/** Hydrate rows held under the venue transaction lock. */
	private function hydrate_locked( array $rows ) {
		$memberships = array();
		foreach ( $rows as $row ) {
			$membership = $this->hydrate( $row );
			if ( is_wp_error( $membership ) ) {
				return $membership;
			}
			$memberships[] = $membership;
		}
		return $memberships;
	}

	/** Recheck owner/admin authority after venue rows are locked. */
	private function actor_can_manage_members( int $actor_user_id, array $memberships ): bool {
		if ( user_can( $actor_user_id, 'manage_options' ) ) {
			return true;
		}
		if ( ! user_can( $actor_user_id, VenueAuthorization::ACCESS_CAPABILITY ) ) {
			return false;
		}
		if ( ! function_exists( 'ec_feature_available' ) || ! ec_feature_available( VenueAuthorization::FEATURE, $actor_user_id ) ) {
			return false;
		}
		foreach ( $memberships as $membership ) {
			if ( $membership['user_id'] === $actor_user_id && $membership['is_owner'] && VenueAuthorization::STATUS_ACTIVE === $membership['status'] ) {
				return true;
			}
		}
		return false;
	}

	private function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back failed membership mutation.
	}

	private function positive_id( $value, string $field ) {
		if ( ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) || (int) $value < 1 ) {
			return new \WP_Error( 'invalid_venue_membership_id', __( 'Venue membership identifiers must be positive integers.', 'extrachill-events' ), array( 'field' => $field ) );
		}
		return (int) $value;
	}

	private function conflict( array $membership ): \WP_Error {
		return new \WP_Error(
			'venue_membership_exists',
			__( 'A venue membership already exists for this user.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => $membership['version'],
			)
		);
	}

	private function version_conflict( array $membership ): \WP_Error {
		return new \WP_Error(
			'venue_membership_version_conflict',
			__( 'The venue membership changed since it was read.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => $membership['version'],
			)
		);
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to manage members for this venue.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
