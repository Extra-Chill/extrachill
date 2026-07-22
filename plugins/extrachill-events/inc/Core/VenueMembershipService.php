<?php
/**
 * Venue membership management policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reauthorizes and executes venue membership operations. */
class VenueMembershipService {

	/** @var VenueMembershipRepository */
	private $memberships;

	/** @var VenueAuthorization */
	private $authorization;

	public function __construct( ?VenueMembershipRepository $memberships = null, ?VenueAuthorization $authorization = null ) {
		$this->memberships   = $memberships ? $memberships : new VenueMembershipRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization( $this->memberships );
	}

	/** Add an active membership for an existing network user. */
	public function create( int $actor_user_id, int $venue_term_id, int $target_user_id, string $role ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_MANAGE_MEMBERS );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return $this->memberships->create(
			array(
				'venue_term_id'      => $venue_term_id,
				'user_id'            => $target_user_id,
				'role'               => $role,
				'status'             => VenueAuthorization::STATUS_ACTIVE,
				'created_by_user_id' => $actor_user_id,
			)
		);
	}

	/** Change one membership role. */
	public function update_role( int $actor_user_id, int $venue_term_id, int $target_user_id, string $role, int $expected_version ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_MANAGE_MEMBERS );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return $this->memberships->update_role( $venue_term_id, $target_user_id, $role, $expected_version, $actor_user_id );
	}

	/** Revoke one membership without deleting its audit identity. */
	public function revoke( int $actor_user_id, int $venue_term_id, int $target_user_id, int $expected_version ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_MANAGE_MEMBERS );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return $this->memberships->revoke( $venue_term_id, $target_user_id, $expected_version, $actor_user_id );
	}

	/** List memberships for one authorized venue. */
	public function list( int $actor_user_id, int $venue_term_id, array $filters = array() ) {
		$allowed = $this->authorization->authorize( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_MANAGE_MEMBERS );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return $this->memberships->list_for_venue( $venue_term_id, $filters, $actor_user_id );
	}
}
