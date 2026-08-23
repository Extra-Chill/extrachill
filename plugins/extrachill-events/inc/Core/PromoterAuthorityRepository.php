<?php
/**
 * Verified promoter organization persistence.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Executes serialized organization and membership mutations. */
class PromoterAuthorityRepository {
	public const STATUS_ACTIVE  = 'active';
	public const STATUS_REVOKED = 'revoked';
	public const MAX_MEMBERS    = 100;

	/** Return every persisted authority status. */
	public static function statuses(): array {
		return array( self::STATUS_ACTIVE, self::STATUS_REVOKED );
	}

	/**
	 * Atomically verify an organization and create its first explicit owner.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 * @param int $owner_user_id    Initial owner network user ID.
	 * @param int $actor_user_id    Administrator network user ID.
	 */
	public function verify( int $promoter_term_id, int $owner_user_id, int $actor_user_id ) {
		$valid = $this->validate_principals( $promoter_term_id, array( $owner_user_id, $actor_user_id ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! user_can( $actor_user_id, 'manage_options' ) ) {
			return $this->forbidden();
		}

		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic organization bootstrap.
			return $this->database_error( 'promoter_authority_transaction_failed', __( 'The promoter authority transaction could not start.', 'extrachill-events' ) );
		}
		$locked = $this->lock_rows( $promoter_term_id );
		if ( is_wp_error( $locked ) ) {
			$this->rollback();
			return $locked;
		}
		if ( $locked['organization'] ) {
			$this->rollback();
			return new \WP_Error(
				'promoter_organization_exists',
				__( 'This promoter organization has already been verified.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'current_version' => $locked['organization']['version'],
				)
			);
		}

		$now          = gmdate( 'Y-m-d H:i:s' );
		$organization = array(
			'promoter_term_id'    => $promoter_term_id,
			'status'              => self::STATUS_ACTIVE,
			'version'             => 1,
			'verified_by_user_id' => $actor_user_id,
			'verified_at'         => $now,
			'updated_at'          => $now,
			'revoked_by_user_id'  => null,
			'revoked_at'          => null,
		);
		if ( false === $wpdb->insert( PromoterAuthoritySchema::organizations_table(), $organization ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private authority write.
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			$winner = $this->get_organization( $promoter_term_id );
			if ( is_array( $winner ) ) {
				$this->report_database_error( 'promoter_organization_create_race_lost', $database_error );
				return new \WP_Error(
					'promoter_organization_exists',
					__( 'This promoter organization has already been verified.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $winner['version'],
					)
				);
			}
			return $this->database_error( 'promoter_organization_create_failed', __( 'The promoter organization could not be verified.', 'extrachill-events' ), $database_error );
		}
		$membership = array(
			'promoter_term_id'   => $promoter_term_id,
			'user_id'            => $owner_user_id,
			'is_owner'           => 1,
			'status'             => self::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => $actor_user_id,
			'created_at'         => $now,
			'updated_at'         => $now,
			'revoked_by_user_id' => null,
			'revoked_at'         => null,
		);
		if ( false === $wpdb->insert( PromoterAuthoritySchema::memberships_table(), $membership ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic bootstrap write.
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_organization_bootstrap_failed', __( 'The promoter organization owner could not be established.', 'extrachill-events' ), $database_error );
		}
		if ( ! $this->audit( $promoter_term_id, 'organization_verified', $actor_user_id, $owner_user_id, 1, array( 'initial_owner' => true ) ) ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_authority_audit_failed', __( 'Promoter authority audit evidence could not be recorded.', 'extrachill-events' ), $database_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes atomic bootstrap.
			return $this->database_error( 'promoter_authority_commit_uncertain', __( 'The promoter authority transaction outcome could not be confirmed.', 'extrachill-events' ) );
		}
		return array(
			'organization' => $this->get_organization( $promoter_term_id ),
			'membership'   => $this->get_membership( $promoter_term_id, $owner_user_id ),
		);
	}

	/**
	 * Revoke an organization without deleting its members or audit history.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 * @param int $expected_version Optimistic organization version.
	 * @param int $actor_user_id    Administrator network user ID.
	 */
	public function revoke_organization( int $promoter_term_id, int $expected_version, int $actor_user_id ) {
		$valid = $this->validate_principals( $promoter_term_id, array( $actor_user_id ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! user_can( $actor_user_id, 'manage_options' ) ) {
			return $this->forbidden();
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialized organization revocation.
			return $this->database_error( 'promoter_authority_transaction_failed', __( 'The promoter authority transaction could not start.', 'extrachill-events' ) );
		}
		$locked  = $this->lock_rows( $promoter_term_id );
		$current = is_wp_error( $locked ) ? $locked : $locked['organization'];
		if ( is_wp_error( $current ) || ! is_array( $current ) ) {
			$this->rollback();
			return is_wp_error( $current ) ? $current : new \WP_Error( 'promoter_organization_not_found', __( 'The promoter organization was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( $current['version'] !== $expected_version ) {
			$this->rollback();
			return $this->version_conflict( 'promoter_organization_version_conflict', $current['version'] );
		}
		if ( self::STATUS_ACTIVE !== $current['status'] ) {
			$this->rollback();
			return new \WP_Error( 'promoter_organization_revoked', __( 'The promoter organization is already revoked.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$now    = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . PromoterAuthoritySchema::organizations_table() . ' SET status = %s, version = version + 1, updated_at = %s, revoked_by_user_id = %d, revoked_at = %s WHERE promoter_term_id = %d AND version = %d', self::STATUS_REVOKED, $now, $actor_user_id, $now, $promoter_term_id, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table and prepared values.
		if ( false === $result ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_organization_revoke_failed', __( 'The promoter organization could not be revoked.', 'extrachill-events' ), $database_error );
		}
		if ( 0 === $result ) {
			$this->rollback();
			$latest = $this->get_organization( $promoter_term_id );
			return is_array( $latest ) ? $this->version_conflict( 'promoter_organization_version_conflict', $latest['version'] ) : new \WP_Error( 'promoter_organization_not_found', __( 'The promoter organization was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( ! $this->audit( $promoter_term_id, 'organization_revoked', $actor_user_id, null, $expected_version + 1, array() ) ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_authority_audit_failed', __( 'Promoter authority audit evidence could not be recorded.', 'extrachill-events' ), $database_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes revocation.
			return $this->database_error( 'promoter_authority_commit_uncertain', __( 'The promoter authority transaction outcome could not be confirmed.', 'extrachill-events' ) );
		}
		return $this->get_organization( $promoter_term_id );
	}

	/**
	 * Add one active member; only a locked-current owner may act.
	 *
	 * @param int  $promoter_term_id Exact promoter term ID.
	 * @param int  $user_id          Target network user ID.
	 * @param bool $is_owner         Structural owner state.
	 * @param int  $actor_user_id    Acting owner network user ID.
	 */
	public function create_membership( int $promoter_term_id, int $user_id, bool $is_owner, int $actor_user_id ) {
		$valid = $this->validate_principals( $promoter_term_id, array( $user_id, $actor_user_id ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialized member creation.
			return $this->database_error( 'promoter_authority_transaction_failed', __( 'The promoter authority transaction could not start.', 'extrachill-events' ) );
		}
		$locked  = $this->lock_rows( $promoter_term_id );
		$allowed = is_wp_error( $locked ) ? $locked : $this->authorize_locked_owner( $actor_user_id, $locked );
		if ( true !== $allowed ) {
			$this->rollback();
			return $allowed;
		}
		foreach ( $locked['memberships'] as $membership ) {
			if ( $membership['user_id'] === $user_id ) {
				$this->rollback();
				return new \WP_Error(
					'promoter_membership_exists',
					__( 'A promoter membership already exists for this user.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $membership['version'],
					)
				);
			}
		}
		if ( count( $locked['memberships'] ) >= self::MAX_MEMBERS ) {
			$this->rollback();
			return new \WP_Error(
				'promoter_membership_limit_exceeded',
				__( 'This promoter organization has reached the supported membership limit.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => self::MAX_MEMBERS,
				)
			);
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'promoter_term_id'   => $promoter_term_id,
			'user_id'            => $user_id,
			'is_owner'           => $is_owner ? 1 : 0,
			'status'             => self::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => $actor_user_id,
			'created_at'         => $now,
			'updated_at'         => $now,
			'revoked_by_user_id' => null,
			'revoked_at'         => null,
		);
		if ( false === $wpdb->insert( PromoterAuthoritySchema::memberships_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private authority write.
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_membership_create_failed', __( 'The promoter membership could not be created.', 'extrachill-events' ), $database_error );
		}
		if ( ! $this->audit( $promoter_term_id, 'membership_created', $actor_user_id, $user_id, 1, array( 'is_owner' => $is_owner ) ) ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_authority_audit_failed', __( 'Promoter authority audit evidence could not be recorded.', 'extrachill-events' ), $database_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes creation.
			return $this->database_error( 'promoter_authority_commit_uncertain', __( 'The promoter authority transaction outcome could not be confirmed.', 'extrachill-events' ) );
		}
		return $this->get_membership( $promoter_term_id, $user_id );
	}

	/**
	 * Change structural owner state at an expected version.
	 *
	 * @param int  $promoter_term_id Exact promoter term ID.
	 * @param int  $user_id          Target network user ID.
	 * @param bool $is_owner         Structural owner state.
	 * @param int  $expected_version Optimistic membership version.
	 * @param int  $actor_user_id    Acting owner network user ID.
	 */
	public function update_owner( int $promoter_term_id, int $user_id, bool $is_owner, int $expected_version, int $actor_user_id ) {
		return $this->mutate_membership( $promoter_term_id, $user_id, $expected_version, $actor_user_id, $is_owner, false );
	}

	/**
	 * Revoke one membership at an expected version.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 * @param int $user_id          Target network user ID.
	 * @param int $expected_version Optimistic membership version.
	 * @param int $actor_user_id    Acting owner network user ID.
	 */
	public function revoke_membership( int $promoter_term_id, int $user_id, int $expected_version, int $actor_user_id ) {
		return $this->mutate_membership( $promoter_term_id, $user_id, $expected_version, $actor_user_id, null, true );
	}

	/**
	 * Read one organization by its exact promoter term.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 */
	public function get_organization( int $promoter_term_id ) {
		global $wpdb;
		$table = PromoterAuthoritySchema::organizations_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE promoter_term_id = %d LIMIT 1", $promoter_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		return '' !== (string) $wpdb->last_error ? $this->database_error( 'promoter_organization_read_failed', __( 'The promoter organization could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_organization( $row ) : null );
	}

	/**
	 * Read one membership by its promoter/user natural key.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 * @param int $user_id          Network user ID.
	 */
	public function get_membership( int $promoter_term_id, int $user_id ) {
		global $wpdb;
		$table = PromoterAuthoritySchema::memberships_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE promoter_term_id = %d AND user_id = %d LIMIT 1", $promoter_term_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
		return '' !== (string) $wpdb->last_error ? $this->database_error( 'promoter_membership_read_failed', __( 'The promoter membership could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_membership( $row ) : null );
	}

	/**
	 * Resolve only an active exact membership.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 * @param int $user_id          Network user ID.
	 */
	public function get_active_membership( int $promoter_term_id, int $user_id ) {
		$membership = $this->get_membership( $promoter_term_id, $user_id );
		return is_array( $membership ) && self::STATUS_ACTIVE !== $membership['status'] ? null : $membership;
	}

	/**
	 * List memberships deterministically within the hard organization bound.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 */
	public function list_memberships( int $promoter_term_id ) {
		global $wpdb;
		$table = PromoterAuthoritySchema::memberships_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE promoter_term_id = %d ORDER BY created_at ASC, id ASC LIMIT %d", $promoter_term_id, self::MAX_MEMBERS + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads one beyond the hard organization bound to fail explicitly.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->database_error( 'promoter_membership_list_failed', __( 'Promoter memberships could not be listed.', 'extrachill-events' ) );
		}
		if ( count( (array) $rows ) > self::MAX_MEMBERS ) {
			return new \WP_Error(
				'promoter_membership_limit_exceeded',
				__( 'This promoter organization exceeds the supported membership limit.', 'extrachill-events' ),
				array(
					'status'  => 409,
					'maximum' => self::MAX_MEMBERS,
				)
			);
		}
		$memberships = array();
		foreach ( (array) $rows as $row ) {
			$membership = $this->hydrate_membership( $row );
			if ( is_wp_error( $membership ) ) {
				return $membership;
			}
			$memberships[] = $membership;
		}
		return $memberships;
	}

	/**
	 * Hydrate an organization and reject corrupt status values.
	 *
	 * @param array $row Raw database row.
	 */
	public function hydrate_organization( array $row ) {
		if ( ! in_array( $row['status'] ?? '', self::statuses(), true ) ) {
			return new \WP_Error( 'promoter_organization_corrupt_status', __( 'The stored promoter organization status is invalid.', 'extrachill-events' ) );
		}
		foreach ( array( 'id', 'promoter_term_id', 'version', 'verified_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['revoked_by_user_id'] = empty( $row['revoked_by_user_id'] ) ? null : (int) $row['revoked_by_user_id'];
		$row['revoked_at']         = empty( $row['revoked_at'] ) ? null : (string) $row['revoked_at'];
		return $row;
	}

	/**
	 * Hydrate a membership and reject corrupt authority values.
	 *
	 * @param array $row Raw database row.
	 */
	public function hydrate_membership( array $row ) {
		if ( ! in_array( $row['status'] ?? '', self::statuses(), true ) ) {
			return new \WP_Error( 'promoter_membership_corrupt_status', __( 'The stored promoter membership status is invalid.', 'extrachill-events' ) );
		}
		if ( ! in_array( $row['is_owner'] ?? null, array( 0, 1, '0', '1' ), true ) ) {
			return new \WP_Error( 'promoter_membership_corrupt_owner', __( 'The stored promoter membership ownership value is invalid.', 'extrachill-events' ) );
		}
		foreach ( array( 'id', 'promoter_term_id', 'user_id', 'version', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['is_owner']           = (bool) (int) $row['is_owner'];
		$row['revoked_by_user_id'] = empty( $row['revoked_by_user_id'] ) ? null : (int) $row['revoked_by_user_id'];
		$row['revoked_at']         = empty( $row['revoked_at'] ) ? null : (string) $row['revoked_at'];
		return $row;
	}

	/**
	 * Execute one serialized membership mutation.
	 *
	 * @param int       $promoter_term_id Exact promoter term ID.
	 * @param int       $user_id          Target network user ID.
	 * @param int       $expected_version Optimistic membership version.
	 * @param int       $actor_user_id    Acting owner network user ID.
	 * @param bool|null $is_owner         New owner state, or null for revocation.
	 * @param bool      $revoke           Whether to revoke the membership.
	 */
	private function mutate_membership( int $promoter_term_id, int $user_id, int $expected_version, int $actor_user_id, ?bool $is_owner, bool $revoke ) {
		$valid = $this->validate_principals( $promoter_term_id, array( $user_id, $actor_user_id ) );
		if ( is_wp_error( $valid ) || $expected_version < 1 ) {
			return is_wp_error( $valid ) ? $valid : new \WP_Error( 'invalid_promoter_membership_version', __( 'A positive expected version is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for final-owner serialization.
			return $this->database_error( 'promoter_authority_transaction_failed', __( 'The promoter authority transaction could not start.', 'extrachill-events' ) );
		}
		$locked  = $this->lock_rows( $promoter_term_id );
		$allowed = is_wp_error( $locked ) ? $locked : $this->authorize_locked_owner( $actor_user_id, $locked );
		if ( true !== $allowed ) {
			$this->rollback();
			return $allowed;
		}
		$current       = null;
		$active_owners = 0;
		foreach ( $locked['memberships'] as $membership ) {
			$current        = $membership['user_id'] === $user_id ? $membership : $current;
			$active_owners += $membership['is_owner'] && self::STATUS_ACTIVE === $membership['status'] ? 1 : 0;
		}
		if ( ! $current ) {
			$this->rollback();
			return new \WP_Error( 'promoter_membership_not_found', __( 'The promoter membership was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( $current['version'] !== $expected_version ) {
			$this->rollback();
			return $this->version_conflict( 'promoter_membership_version_conflict', $current['version'] );
		}
		if ( self::STATUS_REVOKED === $current['status'] ) {
			$this->rollback();
			return new \WP_Error( 'promoter_membership_revoked', __( 'A revoked promoter membership cannot be changed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( $current['is_owner'] && ( $revoke || false === $is_owner ) && $active_owners < 2 ) {
			$this->rollback();
			return new \WP_Error( 'promoter_membership_last_owner', __( 'The final active promoter owner cannot be removed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$now     = gmdate( 'Y-m-d H:i:s' );
		$event   = 'membership_owner_updated';
		$payload = array( 'is_owner' => $is_owner );
		if ( $revoke ) {
			$event   = 'membership_revoked';
			$payload = array();
		}
		$table = PromoterAuthoritySchema::memberships_table();
		if ( $revoke ) {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET version = version + 1, updated_at = %s, status = %s, revoked_by_user_id = %d, revoked_at = %s WHERE promoter_term_id = %d AND user_id = %d AND version = %d", $now, self::STATUS_REVOKED, $actor_user_id, $now, $promoter_term_id, $user_id, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed query and prepared values.
		} else {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET version = version + 1, updated_at = %s, is_owner = %d WHERE promoter_term_id = %d AND user_id = %d AND version = %d", $now, $is_owner ? 1 : 0, $promoter_term_id, $user_id, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed query and prepared values.
		}
		if ( false === $result ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_membership_update_failed', __( 'The promoter membership could not be changed.', 'extrachill-events' ), $database_error );
		}
		if ( 0 === $result ) {
			$this->rollback();
			$latest = $this->get_membership( $promoter_term_id, $user_id );
			return is_array( $latest ) ? $this->version_conflict( 'promoter_membership_version_conflict', $latest['version'] ) : new \WP_Error( 'promoter_membership_not_found', __( 'The promoter membership was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( ! $this->audit( $promoter_term_id, $event, $actor_user_id, $user_id, $expected_version + 1, $payload ) ) {
			$database_error = (string) $wpdb->last_error;
			$this->rollback();
			return $this->database_error( 'promoter_authority_audit_failed', __( 'Promoter authority audit evidence could not be recorded.', 'extrachill-events' ), $database_error );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes mutation.
			return $this->database_error( 'promoter_authority_commit_uncertain', __( 'The promoter authority transaction outcome could not be confirmed.', 'extrachill-events' ) );
		}
		return $this->get_membership( $promoter_term_id, $user_id );
	}

	/**
	 * Lock the organization and every membership for one exact promoter.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 */
	private function lock_rows( int $promoter_term_id ) {
		global $wpdb;
		$organizations    = PromoterAuthoritySchema::organizations_table();
		$memberships      = PromoterAuthoritySchema::memberships_table();
		$organization_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$organizations} WHERE promoter_term_id = %d FOR UPDATE", $promoter_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes one promoter authority range.
		$membership_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$memberships} WHERE promoter_term_id = %d ORDER BY id ASC FOR UPDATE", $promoter_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks final-owner state.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->database_error( 'promoter_authority_read_failed', __( 'Promoter authority could not be locked.', 'extrachill-events' ) );
		}
		$organization = is_array( $organization_row ) ? $this->hydrate_organization( $organization_row ) : null;
		if ( is_wp_error( $organization ) ) {
			return $organization;
		}
		$hydrated = array();
		foreach ( (array) $membership_rows as $row ) {
			$membership = $this->hydrate_membership( $row );
			if ( is_wp_error( $membership ) ) {
				return $membership;
			}
			$hydrated[] = $membership;
		}
		return array(
			'organization' => $organization,
			'memberships'  => $hydrated,
		);
	}

	/**
	 * Reauthorize an active owner from lock-current rows.
	 *
	 * @param int   $actor_user_id Acting network user ID.
	 * @param array $locked        Hydrated lock-current rows.
	 */
	private function authorize_locked_owner( int $actor_user_id, array $locked ) {
		if ( ! is_array( $locked['organization'] ) || self::STATUS_ACTIVE !== $locked['organization']['status'] ) {
			return $this->forbidden();
		}
		foreach ( $locked['memberships'] as $membership ) {
			if ( $membership['user_id'] === $actor_user_id && $membership['is_owner'] && self::STATUS_ACTIVE === $membership['status'] ) {
				return true;
			}
		}
		return $this->forbidden();
	}

	/**
	 * Validate exact current-site term and network user principals.
	 *
	 * @param int   $promoter_term_id Exact promoter term ID.
	 * @param int[] $user_ids         Network user IDs.
	 */
	private function validate_principals( int $promoter_term_id, array $user_ids ) {
		$term = get_term( $promoter_term_id, 'promoter' );
		if ( $promoter_term_id < 1 || ! $term || is_wp_error( $term ) || 'promoter' !== $term->taxonomy ) {
			return new \WP_Error( 'invalid_promoter_authority_term', __( 'A valid current-site promoter term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		foreach ( $user_ids as $user_id ) {
			if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
				return new \WP_Error( 'invalid_promoter_authority_user', __( 'Promoter authority requires existing network users.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * Append privacy-safe evidence inside the mutation transaction.
	 *
	 * @param int      $promoter_term_id Exact promoter term ID.
	 * @param string   $event            Bounded mutation event.
	 * @param int      $actor_user_id    Acting network user ID.
	 * @param int|null $subject_user_id  Target network user ID.
	 * @param int      $result_version   Resulting entity version.
	 * @param array    $payload          Privacy-safe structural evidence.
	 */
	private function audit( int $promoter_term_id, string $event, int $actor_user_id, ?int $subject_user_id, int $result_version, array $payload ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only privacy-safe authority evidence.
		return false !== $wpdb->insert(
			PromoterAuthoritySchema::activity_table(),
			array(
				'promoter_term_id' => $promoter_term_id,
				'event'            => $event,
				'actor_user_id'    => $actor_user_id,
				'subject_user_id'  => $subject_user_id,
				'result_version'   => $result_version,
				'payload'          => wp_json_encode( $payload ),
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/** Roll back the current authority mutation. */
	private function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back failed authority mutation.
	}

	/**
	 * Build an optimistic concurrency conflict.
	 *
	 * @param string $code    Domain error code.
	 * @param int    $version Current stored version.
	 */
	private function version_conflict( string $code, int $version ): \WP_Error {
		return new \WP_Error(
			$code,
			__( 'Promoter authority changed since it was read.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => $version,
			)
		);
	}

	/** Build the shared non-enumerating denial. */
	private function forbidden(): \WP_Error {
		return new \WP_Error( 'promoter_authority_forbidden', __( 'You are not authorized to perform this promoter action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/**
	 * Build a database error while preserving pre-rollback diagnostics.
	 *
	 * @param string      $code           Domain error code.
	 * @param string      $message        Public error message.
	 * @param string|null $database_error Captured database diagnostic.
	 */
	private function database_error( string $code, string $message, ?string $database_error = null ): \WP_Error {
		global $wpdb;
		$this->report_database_error( $code, null === $database_error ? (string) $wpdb->last_error : $database_error );
		return new \WP_Error(
			$code,
			$message,
			array( 'status' => 500 )
		);
	}

	/**
	 * Emit private database diagnostics without exposing them to ability clients.
	 *
	 * @param string $code           Stable domain error code.
	 * @param string $database_error Raw server-side database diagnostic.
	 */
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
