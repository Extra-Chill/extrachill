<?php
/**
 * Exact promoter-to-venue delegated action persistence.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Owns bounded grant reads and serialized mutations. */
class PromoterVenueGrantRepository {
	public const ACTION_ORGANIZE_LOCAL_SUPPORT = 'organize_local_support';
	public const MAX_GRANTS                    = 100;
	public const MAX_LOCK_MEMBERS              = 100;

	/** Return the concrete delegated actions supported today. */
	public static function actions(): array {
		return array( self::ACTION_ORGANIZE_LOCAL_SUPPORT );
	}

	/** Create one active natural-key grant as an explicit direct venue owner.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param int    $venue_term_id    Venue term ID.
	 * @param string $action           Delegated action.
	 * @param int    $actor_user_id    Actor user ID.
	 */
	public function create( int $promoter_term_id, int $venue_term_id, string $action, int $actor_user_id ) {
		$valid = $this->validate_request( $promoter_term_id, $venue_term_id, $action, $actor_user_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic grant creation.
			return $this->database_error( 'promoter_venue_grant_transaction_failed', __( 'The promoter venue grant transaction could not start.', 'extrachill-events' ) );
		}
		$locked = $this->lock_context( $promoter_term_id, $venue_term_id, $action );
		if ( is_wp_error( $locked ) ) {
			$this->rollback();
			return $locked;
		}
		$allowed = $this->authorize_locked_mutation( $actor_user_id, $locked, 'create' );
		if ( true !== $allowed ) {
			$this->rollback();
			return $allowed;
		}
		if ( $locked['grant'] ) {
			$this->rollback();
			return $this->conflict( $locked['grant'], 'promoter_venue_grant_exists' );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'promoter_term_id'   => $promoter_term_id,
			'venue_term_id'      => $venue_term_id,
			'action'             => $action,
			'status'             => PromoterAuthorityRepository::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => $actor_user_id,
			'created_at'         => $now,
			'updated_by_user_id' => $actor_user_id,
			'updated_at'         => $now,
			'revoked_by_user_id' => null,
			'revoked_at'         => null,
		);
		if ( false === $wpdb->insert( PromoterAuthoritySchema::venue_grants_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private authority write.
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			$winner = $this->get( $promoter_term_id, $venue_term_id, $action );
			if ( is_array( $winner ) ) {
				$this->report_database_error( 'promoter_venue_grant_create_race_lost', $database_error );
				return $this->conflict( $winner, 'promoter_venue_grant_exists' );
			}
			return $this->database_error( 'promoter_venue_grant_create_failed', __( 'The promoter venue grant could not be created.', 'extrachill-events' ), $database_error );
		}
		if ( ! $this->audit( $promoter_term_id, $venue_term_id, $action, 'venue_grant_created', $actor_user_id, 1 ) ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_authority_audit_failed', __( 'Promoter authority audit evidence could not be recorded.', 'extrachill-events' ), $database_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes grant creation.
			return $this->database_error( 'promoter_venue_grant_commit_uncertain', __( 'The promoter venue grant transaction outcome could not be confirmed.', 'extrachill-events' ) );
		}
		return $this->get( $promoter_term_id, $venue_term_id, $action );
	}

	/** Revoke one active grant at an expected version.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param int    $venue_term_id    Venue term ID.
	 * @param string $action           Delegated action.
	 * @param int    $expected_version Expected version.
	 * @param int    $actor_user_id    Actor user ID.
	 */
	public function revoke( int $promoter_term_id, int $venue_term_id, string $action, int $expected_version, int $actor_user_id ) {
		return $this->mutate( $promoter_term_id, $venue_term_id, $action, $expected_version, $actor_user_id, PromoterAuthorityRepository::STATUS_REVOKED );
	}

	/** Reactivate one revoked grant at an expected version.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param int    $venue_term_id    Venue term ID.
	 * @param string $action           Delegated action.
	 * @param int    $expected_version Expected version.
	 * @param int    $actor_user_id    Actor user ID.
	 */
	public function reactivate( int $promoter_term_id, int $venue_term_id, string $action, int $expected_version, int $actor_user_id ) {
		return $this->mutate( $promoter_term_id, $venue_term_id, $action, $expected_version, $actor_user_id, PromoterAuthorityRepository::STATUS_ACTIVE );
	}

	/** Read one exact natural-key grant.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param int    $venue_term_id    Venue term ID.
	 * @param string $action           Delegated action.
	 */
	public function get( int $promoter_term_id, int $venue_term_id, string $action ) {
		global $wpdb;
		$table = PromoterAuthoritySchema::venue_grants_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE promoter_term_id = %d AND venue_term_id = %d AND action = %s LIMIT 1", $promoter_term_id, $venue_term_id, $action ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact private authority read.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->database_error( 'promoter_venue_grant_read_failed', __( 'The promoter venue grant could not be read.', 'extrachill-events' ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** List the bounded grants for one exact promoter/venue pair.
	 *
	 * @param int $promoter_term_id Promoter term ID.
	 * @param int $venue_term_id    Venue term ID.
	 */
	public function list_for_pair( int $promoter_term_id, int $venue_term_id ) {
		global $wpdb;
		$table = PromoterAuthoritySchema::venue_grants_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE promoter_term_id = %d AND venue_term_id = %d ORDER BY action ASC, id ASC LIMIT %d", $promoter_term_id, $venue_term_id, self::MAX_GRANTS + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded exact-pair read.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->database_error( 'promoter_venue_grant_list_failed', __( 'Promoter venue grants could not be listed.', 'extrachill-events' ) );
		}
		if ( count( (array) $rows ) > self::MAX_GRANTS ) {
			return new \WP_Error(
				'promoter_venue_grant_limit_exceeded',
				__( 'This promoter and venue exceed the supported grant limit.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => self::MAX_GRANTS,
				)
			);
		}
		$grants = array();
		foreach ( (array) $rows as $row ) {
			$grant = $this->hydrate( $row );
			if ( is_wp_error( $grant ) ) {
				return $grant;
			}
			$grants[] = $grant;
		}
		return $grants;
	}

	/** Return exact active venue IDs for one promoter and action.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param string $action           Delegated action.
	 */
	public function list_active_venue_ids( int $promoter_term_id, string $action ) {
		global $wpdb;
		$table = PromoterAuthoritySchema::venue_grants_table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT venue_term_id FROM {$table} WHERE promoter_term_id = %d AND action = %s AND status = %s ORDER BY venue_term_id ASC LIMIT %d", $promoter_term_id, $action, PromoterAuthorityRepository::STATUS_ACTIVE, self::MAX_GRANTS + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reduced effective grant projection.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->database_error( 'promoter_venue_grant_list_failed', __( 'Promoter venue grants could not be listed.', 'extrachill-events' ) );
		}
		if ( count( (array) $ids ) > self::MAX_GRANTS ) {
			return new \WP_Error(
				'promoter_venue_grant_limit_exceeded',
				__( 'This promoter exceeds the supported active venue grant limit.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => self::MAX_GRANTS,
				)
			);
		}
		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/** Hydrate one grant and reject corrupt statuses or actions.
	 *
	 * @param array $row Raw database row.
	 */
	public function hydrate( array $row ) {
		if ( ! in_array( $row['status'] ?? '', PromoterAuthorityRepository::statuses(), true ) ) {
			return new \WP_Error( 'promoter_venue_grant_corrupt_status', __( 'The stored promoter venue grant status is invalid.', 'extrachill-events' ) );
		}
		if ( ! in_array( $row['action'] ?? '', self::actions(), true ) ) {
			return new \WP_Error( 'promoter_venue_grant_corrupt_action', __( 'The stored promoter venue grant action is invalid.', 'extrachill-events' ) );
		}
		foreach ( array( 'id', 'promoter_term_id', 'venue_term_id', 'version', 'created_by_user_id', 'updated_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['revoked_by_user_id'] = empty( $row['revoked_by_user_id'] ) ? null : (int) $row['revoked_by_user_id'];
		$row['revoked_at']         = empty( $row['revoked_at'] ) ? null : (string) $row['revoked_at'];
		return $row;
	}

	/** Execute one serialized revoke or reactivate mutation.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param int    $venue_term_id    Venue term ID.
	 * @param string $action           Delegated action.
	 * @param int    $expected_version Expected version.
	 * @param int    $actor_user_id    Actor user ID.
	 * @param string $target_status    Target status.
	 */
	private function mutate( int $promoter_term_id, int $venue_term_id, string $action, int $expected_version, int $actor_user_id, string $target_status ) {
		$valid = $this->validate_request( $promoter_term_id, $venue_term_id, $action, $actor_user_id );
		if ( is_wp_error( $valid ) || $expected_version < 1 ) {
			return is_wp_error( $valid ) ? $valid : new \WP_Error( 'invalid_promoter_venue_grant_version', __( 'A positive expected grant version is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialized grant mutation.
			return $this->database_error( 'promoter_venue_grant_transaction_failed', __( 'The promoter venue grant transaction could not start.', 'extrachill-events' ) );
		}
		$locked = $this->lock_context( $promoter_term_id, $venue_term_id, $action );
		if ( is_wp_error( $locked ) ) {
			$this->rollback();
			return $locked;
		}
		$operation = PromoterAuthorityRepository::STATUS_ACTIVE === $target_status ? 'reactivate' : 'revoke';
		$allowed   = $this->authorize_locked_mutation( $actor_user_id, $locked, $operation );
		if ( true !== $allowed ) {
			$this->rollback();
			return $allowed;
		}
		$current = $locked['grant'];
		if ( ! is_array( $current ) ) {
			$this->rollback();
			return new \WP_Error( 'promoter_venue_grant_not_found', __( 'The promoter venue grant was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( $current['version'] !== $expected_version ) {
			$this->rollback();
			return $this->conflict( $current, 'promoter_venue_grant_version_conflict' );
		}
		if ( $current['status'] === $target_status ) {
			$this->rollback();
			return new \WP_Error(
				'promoter_venue_grant_state_conflict',
				__( 'The promoter venue grant is already in the requested state.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'current_version' => $current['version'],
				)
			);
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = PromoterAuthoritySchema::venue_grants_table();
		if ( PromoterAuthorityRepository::STATUS_REVOKED === $target_status ) {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, version = version + 1, updated_by_user_id = %d, updated_at = %s, revoked_by_user_id = %d, revoked_at = %s WHERE promoter_term_id = %d AND venue_term_id = %d AND action = %s AND version = %d", $target_status, $actor_user_id, $now, $actor_user_id, $now, $promoter_term_id, $venue_term_id, $action, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed prepared grant mutation.
		} else {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, version = version + 1, updated_by_user_id = %d, updated_at = %s, revoked_by_user_id = NULL, revoked_at = NULL WHERE promoter_term_id = %d AND venue_term_id = %d AND action = %s AND version = %d", $target_status, $actor_user_id, $now, $promoter_term_id, $venue_term_id, $action, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed prepared grant mutation.
		}
		if ( false === $result ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_venue_grant_update_failed', __( 'The promoter venue grant could not be changed.', 'extrachill-events' ), $database_error );
		}
		if ( 0 === $result ) {
			$this->rollback();
			$latest = $this->get( $promoter_term_id, $venue_term_id, $action );
			return is_array( $latest ) ? $this->conflict( $latest, 'promoter_venue_grant_version_conflict' ) : new \WP_Error( 'promoter_venue_grant_not_found', __( 'The promoter venue grant was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$event = PromoterAuthorityRepository::STATUS_ACTIVE === $target_status ? 'venue_grant_reactivated' : 'venue_grant_revoked';
		if ( ! $this->audit( $promoter_term_id, $venue_term_id, $action, $event, $actor_user_id, $expected_version + 1 ) ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_authority_audit_failed', __( 'Promoter authority audit evidence could not be recorded.', 'extrachill-events' ), $database_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes grant mutation.
			return $this->database_error( 'promoter_venue_grant_commit_uncertain', __( 'The promoter venue grant transaction outcome could not be confirmed.', 'extrachill-events' ) );
		}
		return $this->get( $promoter_term_id, $venue_term_id, $action );
	}

	/**
	 * Lock organization, promoter members, venue members, then exact grant.
	 *
	 * This fixed order prevents cross-domain mutation deadlocks and deliberately
	 * avoids VenueAuthorization's administrator-aware management action.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param int    $venue_term_id    Venue term ID.
	 * @param string $action           Delegated action.
	 */
	private function lock_context( int $promoter_term_id, int $venue_term_id, string $action ) {
		global $wpdb;
		$organizations    = PromoterAuthoritySchema::organizations_table();
		$promoter_members = PromoterAuthoritySchema::memberships_table();
		$venue_members    = BookingSchema::memberships_table();
		$grants           = PromoterAuthoritySchema::venue_grants_table();
		$organization_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$organizations} WHERE promoter_term_id = %d FOR UPDATE", $promoter_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- First deterministic authority lock.
		$promoter_rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$promoter_members} WHERE promoter_term_id = %d ORDER BY id ASC LIMIT %d FOR UPDATE", $promoter_term_id, self::MAX_LOCK_MEMBERS + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Second deterministic authority lock with one-row overflow probe.
		$venue_rows       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$venue_members} WHERE venue_term_id = %d ORDER BY id ASC LIMIT %d FOR UPDATE", $venue_term_id, self::MAX_LOCK_MEMBERS + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Third deterministic authority lock with one-row overflow probe.
		$grant_row        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$grants} WHERE promoter_term_id = %d AND venue_term_id = %d AND action = %s FOR UPDATE", $promoter_term_id, $venue_term_id, $action ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Final exact natural-key lock.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->database_error( 'promoter_venue_grant_read_failed', __( 'Promoter venue authority could not be locked.', 'extrachill-events' ) );
		}
		if ( count( (array) $promoter_rows ) > self::MAX_LOCK_MEMBERS ) {
			return new \WP_Error(
				'promoter_venue_grant_promoter_membership_limit_exceeded',
				__( 'This promoter exceeds the supported lock-current membership limit.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => self::MAX_LOCK_MEMBERS,
				)
			);
		}
		if ( count( (array) $venue_rows ) > self::MAX_LOCK_MEMBERS ) {
			return new \WP_Error(
				'promoter_venue_grant_venue_membership_limit_exceeded',
				__( 'This venue exceeds the supported lock-current membership limit.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => self::MAX_LOCK_MEMBERS,
				)
			);
		}
		$promoters    = new PromoterAuthorityRepository();
		$venues       = new VenueMembershipRepository();
		$organization = is_array( $organization_row ) ? $promoters->hydrate_organization( $organization_row ) : null;
		if ( is_wp_error( $organization ) ) {
			return $organization;
		}
		$promoter_memberships = array();
		foreach ( (array) $promoter_rows as $row ) {
			$membership = $promoters->hydrate_membership( $row );
			if ( is_wp_error( $membership ) ) {
				return $membership;
			}
			$promoter_memberships[] = $membership;
		}
		$venue_memberships = array();
		foreach ( (array) $venue_rows as $row ) {
			$membership = $venues->hydrate( $row );
			if ( is_wp_error( $membership ) ) {
				return $membership;
			}
			$venue_memberships[] = $membership;
		}
		$grant = is_array( $grant_row ) ? $this->hydrate( $grant_row ) : null;
		if ( is_wp_error( $grant ) ) {
			return $grant;
		}
		return compact( 'organization', 'promoter_memberships', 'venue_memberships', 'grant' );
	}

	/** Recheck direct-owner issuance or promoter-owner relinquishment under lock.
	 *
	 * @param int    $actor_user_id Actor user ID.
	 * @param array  $locked        Lock-current context.
	 * @param string $operation     Mutation operation.
	 */
	private function authorize_locked_mutation( int $actor_user_id, array $locked, string $operation ) {
		$active_organization = is_array( $locked['organization'] ) && PromoterAuthorityRepository::STATUS_ACTIVE === $locked['organization']['status'];
		$has_active_member   = false;
		foreach ( $locked['promoter_memberships'] as $membership ) {
			if ( PromoterAuthorityRepository::STATUS_ACTIVE === $membership['status'] ) {
				$has_active_member = true;
				break;
			}
		}
		$direct_owner = false;
		foreach ( $locked['venue_memberships'] as $membership ) {
			if ( $membership['user_id'] === $actor_user_id && $membership['is_owner'] && VenueAuthorization::STATUS_ACTIVE === $membership['status'] ) {
				$direct_owner = true;
				break;
			}
		}
		$can_issue = $direct_owner && $this->has_feature_access( $actor_user_id );
		if ( in_array( $operation, array( 'create', 'reactivate' ), true ) && $can_issue && $active_organization && $has_active_member ) {
			return true;
		}
		if ( 'revoke' === $operation && $can_issue ) {
			return true;
		}
		if ( 'revoke' === $operation && $active_organization ) {
			foreach ( $locked['promoter_memberships'] as $membership ) {
				if ( $membership['user_id'] === $actor_user_id && $membership['is_owner'] && PromoterAuthorityRepository::STATUS_ACTIVE === $membership['status'] && $this->has_feature_access( $actor_user_id ) ) {
					return true;
				}
			}
		}
		return $this->forbidden();
	}

	/** Validate exact terms, action, user, and both storage boundaries.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param int    $venue_term_id    Venue term ID.
	 * @param string $action           Delegated action.
	 * @param int    $actor_user_id    Actor user ID.
	 */
	private function validate_request( int $promoter_term_id, int $venue_term_id, string $action, int $actor_user_id ) {
		if ( ! PromoterAuthoritySchema::is_ready() || ! BookingSchema::is_ready() ) {
			return new \WP_Error( 'promoter_venue_authority_schema_unavailable', __( 'Promoter venue authority storage is not ready.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$promoter = get_term( $promoter_term_id, 'promoter' );
		$venue    = get_term( $venue_term_id, 'venue' );
		if ( $promoter_term_id < 1 || ! $promoter || is_wp_error( $promoter ) || 'promoter' !== $promoter->taxonomy ) {
			return new \WP_Error( 'invalid_promoter_authority_term', __( 'A valid current-site promoter term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( $venue_term_id < 1 || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_promoter_venue_grant_venue', __( 'A valid current-site venue term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $action, self::actions(), true ) ) {
			return new \WP_Error( 'invalid_promoter_venue_grant_action', __( 'The delegated venue action is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( $actor_user_id < 1 || ! get_userdata( $actor_user_id ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/** Check the current feature and capability gate. @param int $user_id Network user ID. */
	private function has_feature_access( int $user_id ): bool {
		return user_can( $user_id, VenueAuthorization::ACCESS_CAPABILITY ) && function_exists( 'ec_feature_available' ) && ec_feature_available( VenueAuthorization::FEATURE, $user_id );
	}

	/** Append privacy-safe grant mutation evidence. */
	private function audit( int $promoter_term_id, int $venue_term_id, string $action, string $event, int $actor_user_id, int $result_version ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy-safe structural authority evidence.
		return false !== $wpdb->insert(
			PromoterAuthoritySchema::activity_table(),
			array(
				'promoter_term_id' => $promoter_term_id,
				'event'            => $event,
				'actor_user_id'    => $actor_user_id,
				'subject_user_id'  => null,
				'result_version'   => $result_version,
				'payload'          => wp_json_encode(
					array(
						'venue_term_id' => $venue_term_id,
						'action'        => $action,
					)
				),
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/** Roll back the current grant mutation. */
	private function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back failed grant mutations.
	}

	/** Build a stable natural-key or version conflict. */
	private function conflict( array $grant, string $code ): \WP_Error {
		return new \WP_Error(
			$code,
			__( 'The promoter venue grant conflicts with the current stored version.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => $grant['version'],
			)
		);
	}

	/** Build the shared non-enumerating denial. */
	private function forbidden(): \WP_Error {
		return new \WP_Error( 'promoter_venue_action_forbidden', __( 'You are not authorized to manage this promoter venue grant.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/** Build a safe public database error and emit private diagnostics. */
	private function database_error( string $code, string $message, ?string $database_error = null ): \WP_Error {
		global $wpdb;
		$this->report_database_error( $code, null === $database_error ? (string) $wpdb->last_error : $database_error );
		return new \WP_Error( $code, $message, array( 'status' => 500 ) );
	}

	/** Emit private database diagnostics through the promoter authority hook. */
	private function report_database_error( string $code, string $database_error ): void {
		do_action(
			'extrachill_events_promoter_authority_database_error',
			array(
				'code'           => $code,
				'database_error' => $database_error,
			)
		);
	}
}
