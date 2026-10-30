<?php
/**
 * Transactional venue claim and invitation persistence.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns onboarding transitions and their privacy-safe audit trail. */
class VenueOnboardingRepository {

	public const CLAIM_PENDING   = 'pending';
	public const CLAIM_APPROVED  = 'approved';
	public const CLAIM_REJECTED  = 'rejected';
	public const CLAIM_CANCELLED = 'cancelled';

	public const INVITE_PENDING   = 'pending';
	public const INVITE_ACCEPTED  = 'accepted';
	public const INVITE_REJECTED  = 'rejected';
	public const INVITE_CANCELLED = 'cancelled';
	public const INVITE_EXPIRED   = 'expired';

	public const DELIVERY_QUEUED     = 'queued';
	public const DELIVERY_PROCESSING = 'processing';
	public const DELIVERY_SENT       = 'sent';
	public const DELIVERY_FAILED     = 'failed';

	/** Invitation token service. */
	private $tokens;

	/** Venue authorization service. */
	private $authorization;

	/** Build the transactional onboarding repository. */
	public function __construct( ?VenueInvitationToken $tokens = null, ?VenueAuthorization $authorization = null ) {
		$this->tokens        = $tokens ? $tokens : new VenueInvitationToken();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
	}

	/**
	 * Submit or idempotently return one user's claim for a venue.
	 *
	 * @param int $actor_user_id Actor user ID.
	 * @param int $venue_term_id Venue term ID.
	 */
	public function submit_claim( int $actor_user_id, int $venue_term_id ) {
		$valid = $this->validate_actor_venue( $actor_user_id, $venue_term_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		global $wpdb;
		$started = $this->begin_and_lock_venue( $venue_term_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}

		$members = $this->lock_memberships( $venue_term_id );
		if ( is_wp_error( $members ) ) {
			$this->rollback();
			return $members;
		}
		$invitations_table = BookingSchema::invitations_table();
		$invitation        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invitations_table} WHERE venue_term_id = %d AND user_id = %d FOR UPDATE", $venue_term_id, $actor_user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reciprocal onboarding exclusion under venue lock.
		if ( is_array( $invitation ) && in_array( $invitation['status'], array( self::INVITE_PENDING, self::INVITE_ACCEPTED ), true ) ) {
			$this->rollback();
			return new \WP_Error( 'venue_claim_invitation_exists', __( 'This user already has a venue invitation.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		foreach ( $members as $member ) {
			if ( (int) $member['user_id'] === $actor_user_id ) {
				$this->rollback();
				return new \WP_Error( 'venue_claim_membership_exists', __( 'This user already belongs to the venue.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
		}

		$table = BookingSchema::claims_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d AND claimant_user_id = %d FOR UPDATE", $venue_term_id, $actor_user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked private claim row.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback_error( 'venue_claim_read_failed', __( 'The venue claim could not be read.', 'extrachill-events' ) );
		}
		if ( is_array( $row ) && self::CLAIM_PENDING === $row['status'] ) {
			$this->commit();
			return $this->hydrate_claim( $row );
		}
		if ( is_array( $row ) && self::CLAIM_APPROVED === $row['status'] ) {
			$this->commit();
			return $this->hydrate_claim( $row );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		if ( is_array( $row ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked private claim update.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, version = version + 1, reviewed_by_user_id = NULL, updated_at = %s, resolved_at = NULL WHERE id = %d AND version = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted private table name.
					self::CLAIM_PENDING,
					$now,
					(int) $row['id'],
					(int) $row['version']
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked optimistic claim resubmission.
			$event  = 'claim_resubmitted';
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private claim write under venue lock.
			$result = $wpdb->insert(
				$table,
				array(
					'public_id'        => wp_generate_uuid4(),
					'venue_term_id'    => $venue_term_id,
					'claimant_user_id' => $actor_user_id,
					'status'           => self::CLAIM_PENDING,
					'version'          => 1,
					'created_at'       => $now,
					'updated_at'       => $now,
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private claim write under venue lock.
			$row    = array( 'id' => $wpdb->insert_id );
			$event  = 'claim_submitted';
		}
		if ( false === $result || 0 === $result ) {
			return $this->rollback_error( 'venue_claim_write_failed', __( 'The venue claim could not be saved.', 'extrachill-events' ) );
		}
		$claim = $this->get_claim_by_id( (int) $row['id'], true );
		if ( ! is_array( $claim ) || ! $this->audit( $venue_term_id, 'claim', (int) $row['id'], $event, $actor_user_id, $actor_user_id, array( 'status' => self::CLAIM_PENDING ) ) ) {
			return $this->rollback_error( 'venue_claim_audit_failed', __( 'The venue claim audit could not be recorded.', 'extrachill-events' ) );
		}
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $claim;
	}

	/**
	 * Approve or reject a claim while serializing first-owner creation.
	 *
	 * @param int    $actor_user_id   Operator user ID.
	 * @param int    $claim_id        Claim row ID.
	 * @param string $decision        Approved or rejected status.
	 * @param int    $expected_version Expected claim version.
	 */
	public function review_claim( int $actor_user_id, int $claim_id, string $decision, int $expected_version ) {
		if ( ! in_array( $decision, array( self::CLAIM_APPROVED, self::CLAIM_REJECTED ), true ) ) {
			return new \WP_Error( 'invalid_venue_claim_decision', __( 'The venue claim decision is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! user_can( $actor_user_id, 'manage_options' ) ) {
			return $this->forbidden();
		}
		$preview = $this->get_claim_by_id( $claim_id );
		if ( ! is_array( $preview ) ) {
			return is_wp_error( $preview ) ? $preview : $this->not_found( 'venue_claim_not_found' );
		}
		$started = $this->begin_and_lock_venue( $preview['venue_term_id'] );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$claim = $this->get_claim_by_id( $claim_id, true );
		if ( ! is_array( $claim ) ) {
			$this->rollback();
			return is_wp_error( $claim ) ? $claim : $this->not_found( 'venue_claim_not_found' );
		}
		if ( ! user_can( $actor_user_id, 'manage_options' ) ) {
			$this->rollback();
			return $this->forbidden();
		}
		if ( $decision === $claim['status'] ) {
			$this->commit();
			return $claim;
		}
		if ( self::CLAIM_PENDING !== $claim['status'] ) {
			$this->rollback();
			return $this->transition_conflict( 'venue_claim_transition_conflict', $claim );
		}
		if ( $expected_version !== $claim['version'] ) {
			$this->rollback();
			return $this->version_conflict( 'venue_claim_version_conflict', $claim );
		}

		global $wpdb;
		if ( self::CLAIM_APPROVED === $decision ) {
			$members = $this->lock_memberships( $claim['venue_term_id'] );
			if ( is_wp_error( $members ) ) {
				$this->rollback();
				return $members;
			}
			$membership = $this->grant_claim_owner( $claim, $actor_user_id, $members );
			if ( is_wp_error( $membership ) ) {
				$this->rollback();
				return $membership;
			}
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$table  = BookingSchema::claims_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, version = version + 1, reviewed_by_user_id = %d, updated_at = %s, resolved_at = %s WHERE id = %d AND version = %d", $decision, $actor_user_id, $now, $now, $claim_id, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked optimistic claim review.
		if ( 1 !== $result || ! $this->audit( $claim['venue_term_id'], 'claim', $claim_id, 'claim_' . $decision, $actor_user_id, $claim['claimant_user_id'], array( 'status' => $decision ) ) ) {
			return $this->rollback_error( 'venue_claim_review_failed', __( 'The venue claim decision could not be saved.', 'extrachill-events' ) );
		}
		$updated = $this->get_claim_by_id( $claim_id, true );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $updated;
	}

	/**
	 * Cancel a pending claim as its claimant or an administrator.
	 *
	 * @param int $actor_user_id   Actor user ID.
	 * @param int $claim_id        Claim row ID.
	 * @param int $expected_version Expected claim version.
	 */
	public function cancel_claim( int $actor_user_id, int $claim_id, int $expected_version ) {
		$claim = $this->get_claim_by_id( $claim_id );
		if ( ! is_array( $claim ) ) {
			return is_wp_error( $claim ) ? $claim : $this->not_found( 'venue_claim_not_found' );
		}
		if ( $claim['claimant_user_id'] !== $actor_user_id && ! user_can( $actor_user_id, 'manage_options' ) ) {
			return $this->forbidden();
		}
		return $this->resolve_claim( $actor_user_id, $claim, self::CLAIM_CANCELLED, $expected_version );
	}

	/**
	 * Create an invitation and canonical invited membership atomically.
	 *
	 * @param int    $actor_user_id Actor user ID.
	 * @param int    $venue_term_id Venue term ID.
	 * @param int    $user_id       Bound invitee user ID.
	 * @param bool   $is_owner      Structural owner grant.
	 * @param string $email_hash    Privacy-preserving email binding.
	 * @param bool   $account_created Whether this invitation created the account.
	 */
	public function create_invitation( int $actor_user_id, int $venue_term_id, int $user_id, bool $is_owner, string $email_hash, bool $account_created = false ) {
		global $wpdb;

		$valid = $this->validate_actor_venue( $user_id, $venue_term_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$started = $this->begin_and_lock_venue( $venue_term_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$members = $this->lock_memberships( $venue_term_id );
		if ( is_wp_error( $members ) ) {
			$this->rollback();
			return $members;
		}
		$allowed = $this->authorization->authorize_locked( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_MANAGE_MEMBERS, $members );
		if ( true !== $allowed ) {
			$this->rollback();
			return $allowed;
		}
		$claims_table = BookingSchema::claims_table();
		$claim        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$claims_table} WHERE venue_term_id = %d AND claimant_user_id = %d FOR UPDATE", $venue_term_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevents parallel claim and invitation authority paths.
		if ( is_array( $claim ) && in_array( $claim['status'], array( self::CLAIM_PENDING, self::CLAIM_APPROVED ), true ) ) {
			$this->rollback();
			return new \WP_Error( 'venue_invitation_claim_exists', __( 'This user already has a venue claim.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$current_member = null;
		foreach ( $members as $member ) {
			if ( (int) $member['user_id'] === $user_id ) {
				$current_member = $member;
				break;
			}
		}
		$invites_table  = BookingSchema::invitations_table();
		$current_invite = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$invites_table} WHERE venue_term_id = %d AND user_id = %d FOR UPDATE", $venue_term_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Determines whether a terminal onboarding row can be safely reactivated.
		$terminal       = array( self::INVITE_CANCELLED, self::INVITE_REJECTED, self::INVITE_EXPIRED );
		if ( is_array( $current_member ) || is_array( $current_invite ) ) {
			$can_reactivate = is_array( $current_member )
				&& VenueAuthorization::STATUS_REVOKED === $current_member['status']
				&& is_array( $current_invite )
				&& in_array( $current_invite['status'], $terminal, true );
			if ( ! $can_reactivate ) {
				$this->rollback();
				return new \WP_Error( 'venue_invitation_membership_exists', __( 'This user already has a venue membership or invitation.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
		}

		$now           = gmdate( 'Y-m-d H:i:s' );
		$public_id     = wp_generate_uuid4();
		$delivery_id   = wp_generate_uuid4();
		$member_row    = array(
			'venue_term_id'      => $venue_term_id,
			'user_id'            => $user_id,
			'is_owner'           => $is_owner ? 1 : 0,
			'status'             => VenueAuthorization::STATUS_INVITED,
			'version'            => 1,
			'created_by_user_id' => $actor_user_id,
			'created_at'         => $now,
			'updated_at'         => $now,
		);
		$members_table = BookingSchema::memberships_table();
		if ( is_array( $current_member ) ) {
			$member_result = $wpdb->query( $wpdb->prepare( "UPDATE {$members_table} SET is_owner = %d, status = %s, version = version + 1, created_by_user_id = %d, updated_at = %s, revoked_at = NULL WHERE venue_term_id = %d AND user_id = %d AND version = %d", $is_owner ? 1 : 0, VenueAuthorization::STATUS_INVITED, $actor_user_id, $now, $venue_term_id, $user_id, (int) $current_member['version'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reactivates only the exact terminal membership under venue lock.
			if ( 1 !== $member_result ) {
				return $this->rollback_error( 'venue_invitation_membership_failed', __( 'The terminal venue membership could not be reactivated.', 'extrachill-events' ) );
			}
		} elseif ( false === $wpdb->insert( $members_table, $member_row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical invited membership under venue lock.
			return $this->rollback_error( 'venue_invitation_membership_failed', __( 'The invited membership could not be created.', 'extrachill-events' ) );
		}
		$invite_row = array(
			'public_id'          => $public_id,
			'venue_term_id'      => $venue_term_id,
			'user_id'            => $user_id,
			'is_owner'           => $is_owner ? 1 : 0,
			'status'             => self::INVITE_PENDING,
			'token_hash'         => str_repeat( '0', 64 ),
			'email_hash'         => $email_hash,
			'account_created'    => $account_created ? 1 : 0,
			'delivery_id'        => $delivery_id,
			'delivery_status'    => self::DELIVERY_QUEUED,
			'delivery_attempts'  => 0,
			'version'            => 1,
			'invited_by_user_id' => $actor_user_id,
			'created_at'         => $now,
			'updated_at'         => $now,
			'expires_at'         => $now,
		);
		if ( is_array( $current_invite ) ) {
			$preserve_created = $account_created || ! empty( $current_invite['account_created'] );
			$result           = $wpdb->query( $wpdb->prepare( "UPDATE {$invites_table} SET public_id = %s, is_owner = %d, status = %s, token_hash = %s, email_hash = %s, account_created = %d, delivery_id = %s, delivery_status = %s, delivery_attempts = 0, version = version + 1, invited_by_user_id = %d, updated_at = %s, expires_at = %s, resolved_at = NULL, delivered_at = NULL WHERE id = %d AND version = %d", $public_id, $is_owner ? 1 : 0, self::INVITE_PENDING, str_repeat( '0', 64 ), $email_hash, $preserve_created ? 1 : 0, $delivery_id, self::DELIVERY_QUEUED, $actor_user_id, $now, $now, (int) $current_invite['id'], (int) $current_invite['version'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rotates every bearer binding while reactivating a terminal invitation.
			if ( 1 !== $result ) {
				return $this->rollback_error( 'venue_invitation_create_failed', __( 'The terminal venue invitation could not be reactivated.', 'extrachill-events' ) );
			}
			$invite_id = (int) $current_invite['id'];
			$event     = 'invitation_reinvited';
		} else {
			if ( false === $wpdb->insert( $invites_table, $invite_row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private invitation under venue lock.
				return $this->rollback_error( 'venue_invitation_create_failed', __( 'The venue invitation could not be created.', 'extrachill-events' ) );
			}
			$invite_id = (int) $wpdb->insert_id;
			$event     = 'invitation_created';
		}
		if ( ! $this->audit(
			$venue_term_id,
			'invitation',
			$invite_id,
			$event,
			$actor_user_id,
			$user_id,
			array(
				'status'   => self::INVITE_PENDING,
				'is_owner' => $is_owner,
			)
		) ) {
			return $this->rollback_error( 'venue_invitation_audit_failed', __( 'The invitation audit could not be recorded.', 'extrachill-events' ) );
		}
		$created = $this->get_invitation_by_id( $invite_id, true );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $created;
	}

	/**
	 * Rotate a pending invitation token under current owner authority.
	 *
	 * @param int    $actor_user_id   Actor user ID.
	 * @param int    $invitation_id   Invitation row ID.
	 * @param int    $expected_version Expected invitation version.
	 */
	public function resend_invitation( int $actor_user_id, int $invitation_id, int $expected_version ) {
		return $this->mutate_invitation( $actor_user_id, $invitation_id, $expected_version, 'resend' );
	}

	/**
	 * Cancel a pending invitation under current owner authority.
	 *
	 * @param int $actor_user_id   Actor user ID.
	 * @param int $invitation_id   Invitation row ID.
	 * @param int $expected_version Expected invitation version.
	 */
	public function cancel_invitation( int $actor_user_id, int $invitation_id, int $expected_version ) {
		return $this->mutate_invitation( $actor_user_id, $invitation_id, $expected_version, self::INVITE_CANCELLED );
	}

	/** Cancel an invitation whose post-persistence delivery setup failed. */
	public function abandon_invitation( array $invitation, int $actor_user_id, string $event ) {
		$started = $this->begin_and_lock_venue( (int) $invitation['venue_term_id'] );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$current = $this->get_invitation_by_id( (int) $invitation['id'], true );
		if ( ! is_array( $current ) || self::INVITE_PENDING !== $current['status'] || (int) $invitation['version'] !== $current['version'] ) {
			$this->rollback();
			return new \WP_Error( 'venue_invitation_compensation_conflict', __( 'The failed invitation changed before it could be compensated.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$membership = $this->set_invited_membership_status( $current, VenueAuthorization::STATUS_REVOKED );
		if ( is_wp_error( $membership ) ) {
			$this->rollback();
			return $membership;
		}

		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$table  = BookingSchema::invitations_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, token_hash = %s, version = version + 1, updated_at = %s, resolved_at = %s WHERE id = %d AND version = %d AND status = %s", self::INVITE_CANCELLED, str_repeat( '0', 64 ), $now, $now, $current['id'], $current['version'], self::INVITE_PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensates an unusable invitation under its venue lock.
		if ( 1 !== $result || ! $this->audit( $current['venue_term_id'], 'invitation', $current['id'], $event, $actor_user_id, $current['user_id'], array( 'version' => $current['version'] + 1 ) ) ) {
			return $this->rollback_error( 'venue_invitation_compensation_failed', __( 'The failed invitation could not be compensated.', 'extrachill-events' ) );
		}
		$updated = $this->get_invitation_by_id( $current['id'], true );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $updated;
	}

	/**
	 * Delete an invitation-created account only while all cross-venue ranges are locked.
	 *
	 * @param array    $invitation Internal invitation identity, or an initial account identity.
	 * @param callable $delete     Bounded account validation and deletion callback.
	 */
	public function delete_account_if_exclusive( array $invitation, callable $delete ): bool {
		global $wpdb;
		$user_id = (int) ( $invitation['user_id'] ?? 0 );
		if ( $user_id < 1 || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes account compensation.
			return false;
		}
		$user_table = $wpdb->users;
		$wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$user_table} WHERE ID = %d FOR UPDATE", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stabilizes account identity through compensation.
		if ( '' !== (string) $wpdb->last_error ) {
			$this->rollback();
			return false;
		}
		$members_table = BookingSchema::memberships_table();
		$members       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$members_table} WHERE user_id = %d ORDER BY venue_term_id ASC, id ASC FOR UPDATE", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- User-first index locks every membership range and insertion gap.
		if ( '' !== (string) $wpdb->last_error ) {
			$this->rollback();
			return false;
		}
		$invites_table = BookingSchema::invitations_table();
		$invitations   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$invites_table} WHERE user_id = %d ORDER BY venue_term_id ASC, id ASC FOR UPDATE", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- User-first index locks every invitation range and insertion gap.
		if ( '' !== (string) $wpdb->last_error ) {
			$this->rollback();
			return false;
		}
		$claims_table = BookingSchema::claims_table();
		$claims       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$claims_table} WHERE claimant_user_id = %d ORDER BY venue_term_id ASC, id ASC FOR UPDATE", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- User-first index locks every claim range and insertion gap.
		if ( '' !== (string) $wpdb->last_error ) {
			$this->rollback();
			return false;
		}

		$allowed_invitation_id = (int) ( $invitation['id'] ?? 0 );
		$allowed_venue_id      = (int) ( $invitation['venue_term_id'] ?? 0 );
		foreach ( (array) $members as $member ) {
			if ( (int) $member['venue_term_id'] !== $allowed_venue_id || VenueAuthorization::STATUS_REVOKED !== $member['status'] ) {
				$this->commit();
				return true;
			}
		}
		foreach ( (array) $invitations as $row ) {
			if ( (int) $row['id'] !== $allowed_invitation_id || ! in_array( $row['status'], array( self::INVITE_CANCELLED, self::INVITE_REJECTED, self::INVITE_EXPIRED ), true ) ) {
				$this->commit();
				return true;
			}
		}
		if ( ! empty( $claims ) ) {
			$this->commit();
			return true;
		}

		$deleted = (bool) $delete();
		if ( ! $deleted ) {
			$this->rollback();
			return false;
		}
		return $this->commit();
	}

	/** Mint one runtime-only token after claiming an opaque queued delivery. */
	public function prepare_delivery( string $delivery_id, int $ttl ) {
		$preview = $this->get_invitation_by_delivery_id( $delivery_id );
		if ( ! is_array( $preview ) ) {
			return $this->not_found( 'venue_invitation_delivery_not_found' );
		}
		$started = $this->begin_and_lock_venue( $preview['venue_term_id'] );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$members = $this->lock_memberships( $preview['venue_term_id'] );
		if ( is_wp_error( $members ) ) {
			$this->rollback();
			return $members;
		}
		$current = $this->get_invitation_by_delivery_id( $delivery_id, true );
		if ( ! is_array( $current ) || self::INVITE_PENDING !== $current['status'] || ! in_array( $current['delivery_status'], array( self::DELIVERY_QUEUED, self::DELIVERY_FAILED ), true ) ) {
			$this->rollback();
			return new \WP_Error( 'venue_invitation_delivery_unavailable', __( 'The invitation delivery is no longer available.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$allowed = $this->authorization->authorize_locked( $current['invited_by_user_id'], $current['venue_term_id'], VenueAuthorization::ACTION_MANAGE_MEMBERS, $members );
		if ( true !== $allowed ) {
			$this->rollback();
			return new \WP_Error( 'venue_invitation_inviter_revoked', __( 'The inviter no longer manages this venue.', 'extrachill-events' ), array( 'status' => 403 ) );
		}

		$token = $this->tokens->generate();
		$hash  = $this->tokens->hash( $token, $current );
		$now   = gmdate( 'Y-m-d H:i:s' );
		global $wpdb;
		$table  = BookingSchema::invitations_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET token_hash = %s, delivery_status = %s, delivery_attempts = delivery_attempts + 1, version = version + 1, updated_at = %s, expires_at = %s WHERE id = %d AND version = %d AND delivery_id = %s", $hash, self::DELIVERY_PROCESSING, $now, gmdate( 'Y-m-d H:i:s', time() + $ttl ), $current['id'], $current['version'], $delivery_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Mints the bearer only after the opaque worker record is claimed.
		if ( 1 !== $result ) {
			return $this->rollback_error( 'venue_invitation_delivery_claim_failed', __( 'The invitation delivery could not be claimed.', 'extrachill-events' ) );
		}
		$prepared = $this->get_invitation_by_delivery_id( $delivery_id, true );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		$prepared['_delivery_token'] = $token;
		return $prepared;
	}

	/** Mark a claimed delivery sent or failed, invalidating unsent bearer material. */
	public function finish_delivery( string $delivery_id, bool $sent ) {
		$preview = $this->get_invitation_by_delivery_id( $delivery_id );
		if ( ! is_array( $preview ) ) {
			return $this->not_found( 'venue_invitation_delivery_not_found' );
		}
		$started = $this->begin_and_lock_venue( $preview['venue_term_id'] );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$current = $this->get_invitation_by_delivery_id( $delivery_id, true );
		if ( ! is_array( $current ) || self::DELIVERY_PROCESSING !== $current['delivery_status'] ) {
			$this->rollback();
			return new \WP_Error( 'venue_invitation_delivery_state_changed', __( 'The invitation delivery state changed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table      = BookingSchema::invitations_table();
		$now        = gmdate( 'Y-m-d H:i:s' );
		$status     = $sent ? self::DELIVERY_SENT : self::DELIVERY_FAILED;
		$token_hash = $sent ? $current['_token_hash'] : str_repeat( '0', 64 );
		if ( $sent ) {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET delivery_status = %s, token_hash = %s, updated_at = %s, delivered_at = %s WHERE id = %d AND version = %d AND delivery_id = %s", $status, $token_hash, $now, $now, $current['id'], $current['version'], $delivery_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes only the claimed opaque delivery without invalidating its acceptance version.
		} else {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET delivery_status = %s, token_hash = %s, updated_at = %s, delivered_at = NULL WHERE id = %d AND version = %d AND delivery_id = %s", $status, $token_hash, $now, $current['id'], $current['version'], $delivery_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Invalidates unsent bearer material.
		}
		if ( 1 !== $result || ! $this->audit( $current['venue_term_id'], 'invitation', $current['id'], $sent ? 'invitation_delivery_sent' : 'invitation_delivery_failed', null, $current['user_id'], array( 'version' => $current['version'] ) ) ) {
			return $this->rollback_error( 'venue_invitation_delivery_finish_failed', __( 'The invitation delivery outcome could not be saved.', 'extrachill-events' ) );
		}
		$updated = $this->get_invitation_by_delivery_id( $delivery_id, true );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $updated;
	}

	/**
	 * Accept or reject an invitation as the exactly-bound user.
	 *
	 * @param int    $actor_user_id   Responding user ID.
	 * @param string $public_id       Public invitation ID.
	 * @param string $token           Raw invitation token.
	 * @param int    $venue_term_id   Bound venue term ID.
	 * @param bool   $is_owner        Bound structural authority.
	 * @param int    $expected_version Expected invitation version.
	 * @param string $decision        Accepted or rejected status.
	 */
	public function respond_to_invitation( int $actor_user_id, string $public_id, string $token, int $venue_term_id, bool $is_owner, int $expected_version, string $decision ) {
		if ( ! in_array( $decision, array( self::INVITE_ACCEPTED, self::INVITE_REJECTED ), true ) ) {
			return new \WP_Error( 'invalid_venue_invitation_decision', __( 'The invitation response is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$preview = $this->get_invitation_by_public_id( $public_id );
		if ( ! is_array( $preview ) ) {
			return is_wp_error( $preview ) ? $preview : $this->invalid_invitation();
		}
		$started = $this->begin_and_lock_venue( $preview['venue_term_id'] );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$members = $this->lock_memberships( $preview['venue_term_id'] );
		if ( is_wp_error( $members ) ) {
			$this->rollback();
			return $members;
		}
		$invite = $this->get_invitation_by_public_id( $public_id, true );
		if ( ! is_array( $invite ) || self::INVITE_PENDING !== $invite['status'] ) {
			$this->rollback();
			return $this->invalid_invitation();
		}
		$user            = get_userdata( $actor_user_id );
		$current_email   = $user instanceof \WP_User ? strtolower( sanitize_email( $user->user_email ) ) : '';
		$email_hash      = hash_hmac( 'sha256', $current_email, wp_salt( 'auth' ) );
		$binding_matches = $invite['user_id'] === $actor_user_id && $invite['venue_term_id'] === $venue_term_id && $invite['is_owner'] === $is_owner && '' !== $current_email && hash_equals( $invite['_email_hash'], $email_hash );
		if ( ! $binding_matches || ! $this->tokens->verify( $token, $invite, $invite['_token_hash'] ) ) {
			$this->rollback();
			return $this->invalid_invitation();
		}
		if ( $invite['version'] !== $expected_version ) {
			$this->rollback();
			return $this->version_conflict( 'venue_invitation_version_conflict', $invite );
		}
		$inviter_allowed = $this->authorization->authorize_locked( $invite['invited_by_user_id'], $venue_term_id, VenueAuthorization::ACTION_MANAGE_MEMBERS, $members );
		if ( true !== $inviter_allowed ) {
			$this->rollback();
			return new \WP_Error( 'venue_invitation_inviter_revoked', __( 'The inviter no longer manages this venue.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		if ( strtotime( $invite['expires_at'] . ' UTC' ) <= time() ) {
			$expired = $this->finish_invitation( $invite, self::INVITE_EXPIRED, $actor_user_id );
			if ( is_wp_error( $expired ) ) {
				return $expired;
			}
			return new \WP_Error(
				'venue_invitation_expired',
				__( 'The venue invitation has expired.', 'extrachill-events' ),
				array(
					'status'              => 410,
					'_cleanup_invitation' => $expired,
				)
			);
		}
		return $this->finish_invitation( $invite, $decision, $actor_user_id );
	}

	/**
	 * List claims for operators without exposing identity data beyond user IDs.
	 *
	 * @param int    $actor_user_id Operator user ID.
	 * @param string $status        Optional claim status.
	 */
	public function list_claims( int $actor_user_id, string $status = '' ) {
		if ( ! user_can( $actor_user_id, 'manage_options' ) ) {
			return $this->forbidden();
		}
		global $wpdb;
		$table  = BookingSchema::claims_table();
		$where  = '';
		$values = array();
		if ( '' !== $status ) {
			if ( ! in_array( $status, self::claim_statuses(), true ) ) {
				return new \WP_Error( 'invalid_venue_claim_status', __( 'The venue claim status is invalid.', 'extrachill-events' ) );
			}
			$where    = ' WHERE status = %s';
			$values[] = $status;
		}
		$query = "SELECT * FROM {$table}{$where} ORDER BY created_at ASC, id ASC LIMIT 100"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed clauses and private table.
		$rows  = $values ? $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A ) : $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are prepared when present.
		return array_map( array( $this, 'hydrate_claim' ), (array) $rows );
	}

	/**
	 * List invitations for one currently managed venue with all secrets redacted.
	 *
	 * @param int $actor_user_id Actor user ID.
	 * @param int $venue_term_id Venue term ID.
	 */
	public function list_invitations( int $actor_user_id, int $venue_term_id ) {
		global $wpdb;
		$started = $this->begin_and_lock_venue( $venue_term_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$members = $this->lock_memberships( $venue_term_id );
		if ( is_wp_error( $members ) ) {
			$this->rollback();
			return $members;
		}
		$allowed = $this->authorization->authorize_locked( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_MANAGE_MEMBERS, $members );
		if ( true !== $allowed ) {
			$this->rollback();
			return $allowed;
		}
		$table = BookingSchema::invitations_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY created_at ASC, id ASC LIMIT 100 FOR UPDATE", $venue_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private venue invitations under the same authorization transaction.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback_error( 'venue_invitation_read_failed', __( 'Venue invitations could not be read.', 'extrachill-events' ) );
		}
		$result = array_map( array( $this, 'hydrate_invitation' ), (array) $rows );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $result;
	}

	/**
	 * Record delivery disposition without token, email, or message content.
	 *
	 * @param array $invitation   Internal invitation row.
	 * @param int   $actor_user_id Actor user ID.
	 * @param bool  $queued        Whether delivery was queued.
	 */
	public function audit_delivery( array $invitation, int $actor_user_id, bool $queued ): bool {
		return $this->audit( $invitation['venue_term_id'], 'invitation', $invitation['id'], $queued ? 'invitation_delivery_queued' : 'invitation_delivery_failed', $actor_user_id, $invitation['user_id'], array( 'version' => $invitation['version'] ) );
	}

	/** Return bounded claim statuses. */
	public static function claim_statuses(): array {
		return array( self::CLAIM_PENDING, self::CLAIM_APPROVED, self::CLAIM_REJECTED, self::CLAIM_CANCELLED );
	}

	/** Return bounded invitation statuses. */
	public static function invitation_statuses(): array {
		return array( self::INVITE_PENDING, self::INVITE_ACCEPTED, self::INVITE_REJECTED, self::INVITE_CANCELLED, self::INVITE_EXPIRED );
	}

	/**
	 * Hydrate a claim's scalar public contract.
	 *
	 * @param array $row Stored claim row.
	 */
	public function hydrate_claim( array $row ): array {
		foreach ( array( 'id', 'venue_term_id', 'claimant_user_id', 'version' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['reviewed_by_user_id'] = empty( $row['reviewed_by_user_id'] ) ? null : (int) $row['reviewed_by_user_id'];
		$row['resolved_at']         = empty( $row['resolved_at'] ) ? null : (string) $row['resolved_at'];
		return $row;
	}

	/**
	 * Hydrate an invitation while retaining its hash only for internal verification.
	 *
	 * @param array $row Stored invitation row.
	 */
	public function hydrate_invitation( array $row ): array {
		foreach ( array( 'id', 'venue_term_id', 'user_id', 'version', 'invited_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['is_owner']          = (bool) (int) $row['is_owner'];
		$row['_account_created']  = ! empty( $row['account_created'] );
		$row['resolved_at']       = empty( $row['resolved_at'] ) ? null : (string) $row['resolved_at'];
		$row['_token_hash']       = (string) $row['token_hash'];
		$row['_email_hash']       = (string) $row['email_hash'];
		$row['_delivery_id']      = (string) $row['delivery_id'];
		$row['delivery_attempts'] = (int) $row['delivery_attempts'];
		$row['delivered_at']      = empty( $row['delivered_at'] ) ? null : (string) $row['delivered_at'];
		unset( $row['token_hash'], $row['email_hash'], $row['account_created'], $row['delivery_id'] );
		return $row;
	}

	/**
	 * Strip every internal secret before returning an invitation to callers.
	 *
	 * @param array $invitation Internal invitation row.
	 */
	public function public_invitation( array $invitation ): array {
		unset( $invitation['_token_hash'], $invitation['_email_hash'], $invitation['_account_created'], $invitation['_delivery_id'], $invitation['_delivery_token'] );
		return $invitation;
	}

	/** Resolve a pending claim to a terminal status. */
	private function resolve_claim( int $actor_user_id, array $claim, string $status, int $expected_version ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private transition arguments are self-describing.
		if ( $claim['status'] === $status ) {
			return $claim;
		}
		if ( self::CLAIM_PENDING !== $claim['status'] || $claim['version'] !== $expected_version ) {
			return $this->transition_conflict( 'venue_claim_transition_conflict', $claim );
		}
		$started = $this->begin_and_lock_venue( $claim['venue_term_id'] );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$current = $this->get_claim_by_id( $claim['id'], true );
		if ( ! is_array( $current ) || self::CLAIM_PENDING !== $current['status'] || $expected_version !== $current['version'] ) {
			$this->rollback();
			return is_array( $current ) ? $this->version_conflict( 'venue_claim_version_conflict', $current ) : $this->not_found( 'venue_claim_not_found' );
		}
		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$table  = BookingSchema::claims_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, version = version + 1, updated_at = %s, resolved_at = %s WHERE id = %d AND version = %d", $status, $now, $now, $claim['id'], $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked optimistic cancellation.
		if ( 1 !== $result || ! $this->audit( $claim['venue_term_id'], 'claim', $claim['id'], 'claim_' . $status, $actor_user_id, $claim['claimant_user_id'], array( 'status' => $status ) ) ) {
			return $this->rollback_error( 'venue_claim_cancel_failed', __( 'The venue claim could not be cancelled.', 'extrachill-events' ) );
		}
		$updated = $this->get_claim_by_id( $claim['id'], true );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $updated;
	}

	/** Mutate a pending invitation under lock-current owner authority. */
	private function mutate_invitation( int $actor_user_id, int $invitation_id, int $expected_version, string $action ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private transition arguments are self-describing.
		$preview = $this->get_invitation_by_id( $invitation_id );
		if ( ! is_array( $preview ) ) {
			return is_wp_error( $preview ) ? $preview : $this->not_found( 'venue_invitation_not_found' );
		}
		$started = $this->begin_and_lock_venue( $preview['venue_term_id'] );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$members = $this->lock_memberships( $preview['venue_term_id'] );
		if ( is_wp_error( $members ) ) {
			$this->rollback();
			return $members;
		}
		$allowed = $this->authorization->authorize_locked( $actor_user_id, $preview['venue_term_id'], VenueAuthorization::ACTION_MANAGE_MEMBERS, $members );
		if ( true !== $allowed ) {
			$this->rollback();
			return $allowed;
		}
		$current = $this->get_invitation_by_id( $invitation_id, true );
		if ( self::INVITE_CANCELLED === $action && is_array( $current ) && self::INVITE_CANCELLED === $current['status'] ) {
			if ( ! $this->commit() ) {
				return $this->commit_error();
			}
			return $current;
		}
		if ( ! is_array( $current ) || self::INVITE_PENDING !== $current['status'] ) {
			$this->rollback();
			return is_array( $current ) ? $this->transition_conflict( 'venue_invitation_transition_conflict', $current ) : $this->not_found( 'venue_invitation_not_found' );
		}
		if ( $expected_version !== $current['version'] ) {
			$this->rollback();
			return $this->version_conflict( 'venue_invitation_version_conflict', $current );
		}

		global $wpdb;
		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = BookingSchema::invitations_table();
		if ( 'resend' === $action ) {
			$delivery_id = wp_generate_uuid4();
			$result      = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET token_hash = %s, delivery_id = %s, delivery_status = %s, delivery_attempts = 0, version = version + 1, invited_by_user_id = %d, updated_at = %s, expires_at = %s, delivered_at = NULL WHERE id = %d AND version = %d", str_repeat( '0', 64 ), $delivery_id, self::DELIVERY_QUEUED, $actor_user_id, $now, $now, $invitation_id, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rotates the opaque delivery identity before a new worker is queued.
			$event       = 'invitation_resent';
		} else {
			$result     = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, token_hash = %s, version = version + 1, updated_at = %s, resolved_at = %s WHERE id = %d AND version = %d", self::INVITE_CANCELLED, str_repeat( '0', 64 ), $now, $now, $invitation_id, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked invitation cancellation.
			$membership = $this->set_invited_membership_status( $current, VenueAuthorization::STATUS_REVOKED );
			if ( is_wp_error( $membership ) ) {
				$this->rollback();
				return $membership;
			}
			$event = 'invitation_cancelled';
		}
		if ( 1 !== $result || ! $this->audit( $current['venue_term_id'], 'invitation', $invitation_id, $event, $actor_user_id, $current['user_id'], array( 'version' => $expected_version + 1 ) ) ) {
			return $this->rollback_error( 'venue_invitation_update_failed', __( 'The venue invitation could not be updated.', 'extrachill-events' ) );
		}
		$updated = $this->get_invitation_by_id( $invitation_id, true );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $updated;
	}

	/** Complete an invitation and its canonical membership transition. */
	private function finish_invitation( array $invite, string $status, int $actor_user_id ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private transition arguments are self-describing.
		global $wpdb;
		$membership_status = self::INVITE_ACCEPTED === $status ? VenueAuthorization::STATUS_ACTIVE : VenueAuthorization::STATUS_REVOKED;
		$membership        = $this->set_invited_membership_status( $invite, $membership_status );
		if ( is_wp_error( $membership ) ) {
			$this->rollback();
			return $membership;
		}
		$now    = gmdate( 'Y-m-d H:i:s' );
		$table  = BookingSchema::invitations_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, token_hash = %s, version = version + 1, updated_at = %s, resolved_at = %s WHERE id = %d AND version = %d AND status = %s", $status, str_repeat( '0', 64 ), $now, $now, $invite['id'], $invite['version'], self::INVITE_PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-use invitation transition.
		if ( 1 !== $result || ! $this->audit(
			$invite['venue_term_id'],
			'invitation',
			$invite['id'],
			'invitation_' . $status,
			$actor_user_id,
			$invite['user_id'],
			array(
				'status'   => $status,
				'is_owner' => $invite['is_owner'],
			)
		) ) {
			return $this->rollback_error( 'venue_invitation_response_failed', __( 'The invitation response could not be saved.', 'extrachill-events' ) );
		}
		$updated = $this->get_invitation_by_id( $invite['id'], true );
		if ( ! $this->commit() ) {
			return $this->commit_error();
		}
		return $updated;
	}

	/** Grant approved structural owner authority. */
	private function grant_claim_owner( array $claim, int $actor_user_id, array $members ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private transition arguments are self-describing.
		global $wpdb;
		$current = null;
		foreach ( $members as $member ) {
			if ( (int) $member['user_id'] === $claim['claimant_user_id'] ) {
				$current = $member;
				break;
			}
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( null === $current ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit owner bootstrap under venue lock.
			$result = $wpdb->insert(
				BookingSchema::memberships_table(),
				array(
					'venue_term_id'      => $claim['venue_term_id'],
					'user_id'            => $claim['claimant_user_id'],
					'is_owner'           => 1,
					'status'             => VenueAuthorization::STATUS_ACTIVE,
					'version'            => 1,
					'created_by_user_id' => $actor_user_id,
					'created_at'         => $now,
					'updated_at'         => $now,
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit operator-approved owner bootstrap under venue lock.
			return false === $result ? new \WP_Error( 'venue_claim_owner_create_failed', __( 'The approved venue owner could not be created.', 'extrachill-events' ) ) : true;
		}
		if ( VenueAuthorization::STATUS_REVOKED === $current['status'] ) {
			return new \WP_Error( 'venue_claim_membership_revoked', __( 'A revoked membership requires explicit operator repair.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$table  = BookingSchema::memberships_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_owner = 1, status = %s, version = version + 1, updated_at = %s, revoked_at = NULL WHERE venue_term_id = %d AND user_id = %d AND version = %d", VenueAuthorization::STATUS_ACTIVE, $now, $claim['venue_term_id'], $claim['claimant_user_id'], (int) $current['version'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Operator-approved promotion under lock.
		return 1 === $result ? true : new \WP_Error( 'venue_claim_owner_update_failed', __( 'The approved venue owner could not be updated.', 'extrachill-events' ) );
	}

	/** Transition only the matching canonical invited membership. */
	private function set_invited_membership_status( array $invite, string $status ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private transition arguments are self-describing.
		global $wpdb;
		$table   = BookingSchema::memberships_table();
		$row     = null;
		$members = $this->lock_memberships( $invite['venue_term_id'] );
		if ( is_wp_error( $members ) ) {
			return $members;
		}
		$has_active_owner = false;
		foreach ( $members as $member ) {
			if ( (int) $member['user_id'] === $invite['user_id'] ) {
				$row = $member;
			}
			if ( ! empty( $member['is_owner'] ) && VenueAuthorization::STATUS_ACTIVE === $member['status'] ) {
				$has_active_owner = true;
			}
		}
		if ( ! is_array( $row ) || VenueAuthorization::STATUS_INVITED !== $row['status'] || (bool) (int) $row['is_owner'] !== $invite['is_owner'] ) {
			return new \WP_Error( 'venue_invitation_membership_changed', __( 'The invited membership no longer matches this invitation.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( VenueAuthorization::STATUS_ACTIVE === $status && ! $invite['is_owner'] && ! $has_active_owner ) {
			return new \WP_Error( 'venue_membership_owner_required', __( 'The first active venue member must be an owner.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$revoked_at = VenueAuthorization::STATUS_REVOKED === $status ? gmdate( 'Y-m-d H:i:s' ) : null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked canonical membership transition.
		$result = $wpdb->update(
			$table,
			array(
				'status'     => $status,
				'version'    => (int) $row['version'] + 1,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				'revoked_at' => $revoked_at,
			),
			array(
				'venue_term_id' => $invite['venue_term_id'],
				'user_id'       => $invite['user_id'],
				'version'       => (int) $row['version'],
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked canonical membership transition.
		return 1 === $result ? true : new \WP_Error( 'venue_invitation_membership_update_failed', __( 'The invited membership could not be updated.', 'extrachill-events' ) );
	}

	/** Start a transaction and acquire the stable venue-term lock. */
	private function begin_and_lock_venue( int $venue_term_id ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private lock helper.
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes onboarding transitions.
			return new \WP_Error( 'venue_onboarding_transaction_failed', __( 'The venue onboarding transaction could not start.', 'extrachill-events' ) );
		}
		$term_id = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $venue_term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stable lock even before venue-owned rows exist.
		if ( (int) $term_id !== $venue_term_id || '' !== (string) $wpdb->last_error ) {
			$this->rollback();
			return new \WP_Error( 'invalid_venue_membership_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/** Read lock-current membership authority for a venue. */
	private function lock_memberships( int $venue_term_id ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private lock helper.
		global $wpdb;
		$table = BookingSchema::memberships_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock-current authority rows.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_read_failed', __( 'Venue memberships could not be locked.', 'extrachill-events' ) );
		}
		return (array) $rows;
	}

	/** Read a claim, optionally under a row lock. */
	private function get_claim_by_id( int $claim_id, bool $locked = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private read helper.
		global $wpdb;
		$table = BookingSchema::claims_table();
		$lock  = $locked ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d{$lock}", $claim_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private claim read.
		return is_array( $row ) ? $this->hydrate_claim( $row ) : null;
	}

	/** Read an invitation by internal ID. */
	private function get_invitation_by_id( int $invitation_id, bool $locked = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private read helper.
		global $wpdb;
		$table = BookingSchema::invitations_table();
		$lock  = $locked ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d{$lock}", $invitation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private invitation read.
		return is_array( $row ) ? $this->hydrate_invitation( $row ) : null;
	}

	/** Read an invitation by opaque public ID. */
	private function get_invitation_by_public_id( string $public_id, bool $locked = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private read helper.
		global $wpdb;
		$table = BookingSchema::invitations_table();
		$lock  = $locked ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s{$lock}", $public_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Opaque invitation lookup.
		return is_array( $row ) ? $this->hydrate_invitation( $row ) : null;
	}

	/** Read an invitation by opaque delivery ID. */
	private function get_invitation_by_delivery_id( string $delivery_id, bool $locked = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private read helper.
		global $wpdb;
		$table = BookingSchema::invitations_table();
		$lock  = $locked ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE delivery_id = %s{$lock}", $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Opaque delivery lookup.
		return is_array( $row ) ? $this->hydrate_invitation( $row ) : null;
	}

	/** Append a privacy-safe onboarding audit event. */
	private function audit( int $venue_term_id, string $entity_type, int $entity_id, string $event, ?int $actor_user_id, ?int $subject_user_id, array $payload ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private audit helper.
		global $wpdb;
		$encoded = wp_json_encode( $payload );
		if ( false === $encoded || preg_match( '/(?:token|email)/i', implode( '|', array_keys( $payload ) ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only private audit write.
		$result = $wpdb->insert(
			BookingSchema::onboarding_audit_table(),
			array(
				'venue_term_id'   => $venue_term_id,
				'entity_type'     => $entity_type,
				'entity_id'       => $entity_id,
				'event'           => $event,
				'actor_user_id'   => $actor_user_id,
				'subject_user_id' => $subject_user_id,
				'payload'         => $encoded,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only private audit write.
		return false !== $result;
	}

	/** Validate schema, venue, and network-user existence. */
	private function validate_actor_venue( int $user_id, int $venue_term_id ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private validation helper.
		if ( ! BookingSchema::is_ready() ) {
			return new \WP_Error( 'venue_membership_schema_unavailable', __( 'Venue onboarding storage is not ready.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$venue = get_term( $venue_term_id, 'venue' );
		if ( $venue_term_id < 1 || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_venue_membership_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/** Commit the current transaction. */
	private function commit(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes onboarding transaction.
	}

	/** Roll back the current transaction. */
	private function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back onboarding transaction.
	}

	/** Roll back and return a database error. */
	private function rollback_error( string $code, string $message ): \WP_Error { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private error helper.
		global $wpdb;
		$database_error = (string) $wpdb->last_error;
		$this->rollback();
		return new \WP_Error( $code, $message, array( 'database_error' => $database_error ) );
	}

	/** Return the uncertain-commit error. */
	private function commit_error(): \WP_Error {
		return new \WP_Error( 'venue_onboarding_commit_uncertain', __( 'The onboarding transaction outcome could not be confirmed.', 'extrachill-events' ) );
	}

	/** Return a non-enumerating invitation error. */
	private function invalid_invitation(): \WP_Error {
		return new \WP_Error( 'invalid_venue_invitation', __( 'The venue invitation is invalid or no longer available.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/** Return a standard authorization error. */
	private function forbidden(): \WP_Error {
		return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/** Return a standard not-found error. */
	private function not_found( string $code ): \WP_Error { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private error helper.
		return new \WP_Error( $code, __( 'The venue onboarding record was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	/** Return an optimistic version conflict. */
	private function version_conflict( string $code, array $current ): \WP_Error { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private error helper.
		return new \WP_Error(
			$code,
			__( 'The venue onboarding record changed since it was read.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => $current['version'],
			)
		);
	}

	/** Return a terminal transition conflict. */
	private function transition_conflict( string $code, array $current ): \WP_Error { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag -- Private error helper.
		return new \WP_Error(
			$code,
			__( 'The venue onboarding transition is no longer valid.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_status'  => $current['status'],
				'current_version' => $current['version'],
			)
		);
	}
}
