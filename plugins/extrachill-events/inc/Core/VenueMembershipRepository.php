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

use ExtraChill\Users\Teams\TeamMembershipRepository;

/** Provides bounded persistence for venue/user relationships. */
class VenueMembershipRepository {
	public const MAX_USER_VENUES = 500;

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

		$row = ( new TeamMembershipRepository() )->new_row( 'venue', $venue_term_id, $user_id, $is_owner, $creator_id, $status );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes authorization and membership creation.
			return new \WP_Error( 'venue_membership_transaction_failed', __( 'The venue membership transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$locked = $this->lock_for_venue( $venue_term_id );
		if ( is_wp_error( $locked ) ) {
			$this->rollback();
			return $locked;
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

		if ( false === ( new TeamMembershipRepository() )->insert( 'venue', $row ) ) {
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

	/** Lock raw membership rows inside the caller's transaction. */
	public function lock_for_venue( int $venue_term_id ): array|\WP_Error {
		global $wpdb;
		$rows = ( new TeamMembershipRepository() )->lock_team( 'venue', $venue_term_id );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'venue_membership_read_failed', __( 'Venue memberships could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : (array) $rows;
	}

	/** Lock one raw member inside the caller's transaction; an absent member returns an empty array. */
	public function lock_member( int $venue_term_id, int $user_id ): array|\WP_Error {
		global $wpdb;
		$rows = ( new TeamMembershipRepository() )->lock_member( 'venue', $venue_term_id, $user_id );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'venue_membership_read_failed', __( 'Venue memberships could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : (array) $rows;
	}

	/** Test whether a user has any active relationship, without a venue identity join. */
	public function has_active_membership_for_user( int $user_id ): bool|\WP_Error {
		global $wpdb;
		$found = ( new TeamMembershipRepository() )->select( 'venue', array(
			'user_id' => $user_id,
			'status'  => VenueAuthorization::STATUS_ACTIVE,
		), 'id ASC', 1 );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'venue_membership_read_failed', __( 'The venue membership could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : (bool) $found;
	}

	/** Read the current user's settings projection, including inactive relationships. */
	public function list_settings_for_user( int $user_id ): array|\WP_Error {
		global $wpdb;
		$rows = ( new TeamMembershipRepository() )->select( 'venue', array( 'user_id' => $user_id ), 'created_at ASC, id ASC' );
		$keep = array_flip( array( 'venue_term_id', 'is_owner', 'status' ) );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'venue_membership_read_failed', __( 'The venue membership could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : array_map( static fn( $row ) => array_intersect_key( $row, $keep ), $rows );
	}

	/** Insert an onboarding relationship after the caller has locked and validated venue authority. */
	public function insert_onboarding_member( array $row ) {
		return ( new TeamMembershipRepository() )->insert( 'venue', $row );
	}

	/** Transition a validated onboarding relationship at its locked version. */
	public function update_onboarding_member( int $venue_term_id, int $user_id, int $version, array $data ) {
		return ( new TeamMembershipRepository() )->update( 'venue', $venue_term_id, $user_id, $version, $data );
	}

	/** Lock every relationship and insertion gap for account-compensation checks. */
	public function lock_for_user( int $user_id ): array|\WP_Error {
		global $wpdb;
		$rows = ( new TeamMembershipRepository() )->select( 'venue', array( 'user_id' => $user_id ), 'team_id ASC, id ASC', null, true );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'venue_membership_read_failed', __( 'Venue memberships could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : (array) $rows;
	}

	/** Lock a bounded venue set for promoter grant authority checks. */
	public function lock_for_grant( int $venue_term_id, int $limit ): array|\WP_Error {
		global $wpdb;
		$rows = ( new TeamMembershipRepository() )->lock_team( 'venue', $venue_term_id, $limit );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'venue_membership_read_failed', __( 'Venue memberships could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : (array) $rows;
	}

	/** Get one relationship by its venue/user natural key. */
	public function get( int $venue_term_id, int $user_id ) {
		return ( new TeamMembershipRepository() )->get( 'venue', $venue_term_id, $user_id );
	}

	/** Get an active relationship, returning null for invited or revoked rows. */
	public function get_active( int $venue_term_id, int $user_id ) {
		$membership = $this->get( $venue_term_id, $user_id );
		if ( is_wp_error( $membership ) || ! is_array( $membership ) ) {
			return $membership;
		}
		return VenueAuthorization::STATUS_ACTIVE === $membership['status'] ? $membership : null;
	}

	/**
	 * Test for one active membership backed by a current venue identity.
	 *
	 * @param int $user_id Network user ID.
	 */
	public function has_active_venue_for_user( int $user_id ) {
		if ( $user_id < 1 ) {
			return false;
		}
		global $wpdb;
		$table = TeamMembershipRepository::table();
		$found = null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} AS membership INNER JOIN {$wpdb->term_taxonomy} AS identity ON identity.term_id = membership.team_id AND identity.taxonomy = %s WHERE membership.team_type = %s AND membership.user_id = %d AND membership.status = %s LIMIT 1", 'venue', 'venue', $user_id, 'active' ) ); // phpcs:ignore WordPress.DB -- Prepared authority query on the caller's site.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_read_failed', __( 'Managed venue access could not be read.', 'extrachill-events' ), array( 'status' => 500 ) );
		}
		return $found;
	}

	/**
	 * List canonical venue IDs actively represented by one network user.
	 *
	 * @param int $user_id Network user ID.
	 * @return int[]|\WP_Error Canonical venue term IDs or a database error.
	 */
	public function list_active_venue_ids_for_user( int $user_id ) {
		global $wpdb;

		if ( $user_id < 1 ) {
			return array();
		}

		$ids = ( new TeamMembershipRepository() )->term_ids( 'venue', array(
			'user_id' => $user_id,
			'status'  => VenueAuthorization::STATUS_ACTIVE,
		), self::MAX_USER_VENUES + 1 );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_list_failed', __( 'Managed venues could not be read.', 'extrachill-events' ), array( 'status' => 500 ) );
		}
		if ( count( (array) $ids ) > self::MAX_USER_VENUES ) {
			return new \WP_Error(
				'venue_membership_user_limit_exceeded',
				__( 'This user exceeds the supported managed venue limit.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => self::MAX_USER_VENUES,
				)
			);
		}

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * List canonical venues that have at least one active platform member.
	 *
	 * @return int[]|\WP_Error Canonical venue term IDs or a database error.
	 */
	public function list_active_venue_ids() {
		global $wpdb;

		$ids = ( new TeamMembershipRepository() )->term_ids( 'venue', array( 'status' => VenueAuthorization::STATUS_ACTIVE ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_list_failed', __( 'Managed venues could not be read.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/** List memberships for one venue with bounded filters. */
	public function list_for_venue( int $venue_term_id, array $filters = array(), int $actor_user_id = 0 ) {
		global $wpdb;

		$criteria = array_intersect_key( $filters, array( 'is_owner' => true ) );
		if ( ! empty( $filters['status'] ) ) {
			$criteria['status'] = sanitize_key( (string) $filters['status'] );
			if ( ! in_array( $criteria['status'], VenueAuthorization::statuses(), true ) ) {
				return new \WP_Error( 'invalid_venue_membership_status', __( 'The venue membership filter is not supported.', 'extrachill-events' ) );
			}
		}
		$owner_actor = $actor_user_id > 0 && ! user_can( $actor_user_id, 'manage_options' ) ? $actor_user_id : 0;
		$limit       = max( 1, min( 100, absint( $filters['limit'] ?? 50 ) ) );
		$rows        = ( new TeamMembershipRepository() )->list_team( 'venue', $venue_term_id, $criteria, $limit, max( 0, absint( $filters['offset'] ?? 0 ) ), $owner_actor );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_list_failed', __( 'Venue memberships could not be listed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}

		return ( new TeamMembershipRepository() )->hydrate_rows( 'venue', (array) $rows );
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
		return ( new TeamMembershipRepository() )->hydrate( 'venue', $row );
	}

	/** Execute one serialized membership mutation. */
	private function mutate( int $venue_term_id, int $user_id, int $expected_version, ?bool $is_owner, ?string $status, int $actor_user_id ) {
		global $wpdb;

		if ( $venue_term_id < 1 || $user_id < 1 || $expected_version < 1 || $actor_user_id < 1 ) {
			return new \WP_Error( 'invalid_venue_membership_id', __( 'Positive venue, user, and version identifiers are required.', 'extrachill-events' ) );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for last-owner serialization.
			return new \WP_Error( 'venue_membership_transaction_failed', __( 'The venue membership transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}

		$locked = $this->lock_for_venue( $venue_term_id );
		if ( is_wp_error( $locked ) ) {
			$this->rollback();
			return $locked;
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

		$result = ( new TeamMembershipRepository() )->mutate( 'venue', $venue_term_id, $user_id, $expected_version, $is_owner, null !== $status, $actor_user_id );
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
		return ( new TeamMembershipRepository() )->hydrate_rows( 'venue', $rows );
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
