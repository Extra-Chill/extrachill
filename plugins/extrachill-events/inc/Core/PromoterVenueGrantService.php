<?php
/**
 * Promoter venue grant management boundary.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Reauthorizes grant management and delegates persistence. */
final class PromoterVenueGrantService {
	/**
	 * Grant persistence.
	 *
	 * @var PromoterVenueGrantRepository
	 */
	private $grants;

	/** Construct the grant service. */
	public function __construct( ?PromoterVenueGrantRepository $grants = null ) {
		$this->grants = $grants ? $grants : new PromoterVenueGrantRepository();
	}

	/** Create one grant. */
	public function create( int $actor_user_id, int $promoter_term_id, int $venue_term_id, string $action ) {
		return $this->grants->create( $promoter_term_id, $venue_term_id, $action, $actor_user_id );
	}

	/** Revoke one grant. */
	public function revoke( int $actor_user_id, int $promoter_term_id, int $venue_term_id, string $action, int $expected_version ) {
		return $this->grants->revoke( $promoter_term_id, $venue_term_id, $action, $expected_version, $actor_user_id );
	}

	/** Reactivate one grant. */
	public function reactivate( int $actor_user_id, int $promoter_term_id, int $venue_term_id, string $action, int $expected_version ) {
		return $this->grants->reactivate( $promoter_term_id, $venue_term_id, $action, $expected_version, $actor_user_id );
	}

	/** List grants for one exact pair. */
	public function list( int $actor_user_id, int $promoter_term_id, int $venue_term_id ) {
		$allowed = $this->can_manage( $actor_user_id, $promoter_term_id, $venue_term_id );
		return true === $allowed ? $this->grants->list_for_pair( $promoter_term_id, $venue_term_id ) : $allowed;
	}

	/** Authorize direct venue-owner issuance or reactivation. */
	public function can_issue( int $actor_user_id, int $promoter_term_id, int $venue_term_id ) {
		$valid = $this->validate_management_request( $actor_user_id, $promoter_term_id, $venue_term_id );
		if ( true !== $valid ) {
			return $valid;
		}
		$direct = ( new VenueMembershipRepository() )->get_active( $venue_term_id, $actor_user_id );
		if ( ! is_array( $direct ) || ! $direct['is_owner'] ) {
			return $this->forbidden();
		}
		$promoters    = new PromoterAuthorityRepository();
		$organization = $promoters->get_organization( $promoter_term_id );
		$memberships  = $promoters->list_memberships( $promoter_term_id );
		if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'] || ! is_array( $memberships ) ) {
			return $this->forbidden();
		}
		foreach ( $memberships as $membership ) {
			if ( PromoterAuthorityRepository::STATUS_ACTIVE === $membership['status'] ) {
				return true;
			}
		}
		return $this->forbidden();
	}

	/** Authorize listing without inheriting VenueAuthorization's admin override. */
	public function can_manage( int $actor_user_id, int $promoter_term_id, int $venue_term_id ) {
		$valid = $this->validate_management_request( $actor_user_id, $promoter_term_id, $venue_term_id );
		if ( true !== $valid ) {
			return $valid;
		}
		$direct = ( new VenueMembershipRepository() )->get_active( $venue_term_id, $actor_user_id );
		if ( is_array( $direct ) && $direct['is_owner'] ) {
			return true;
		}
		$organization = ( new PromoterAuthorityRepository() )->get_organization( $promoter_term_id );
		$membership   = ( new PromoterAuthorityRepository() )->get_active_membership( $promoter_term_id, $actor_user_id );
		return is_array( $organization ) && PromoterAuthorityRepository::STATUS_ACTIVE === $organization['status'] && is_array( $membership ) && $membership['is_owner'] ? true : $this->forbidden();
	}

	/** Validate exact terms and current feature access. */
	private function validate_management_request( int $actor_user_id, int $promoter_term_id, int $venue_term_id ) {
		if ( ! PromoterAuthoritySchema::is_ready() || ! BookingSchema::is_ready() ) {
			return new \WP_Error( 'promoter_venue_authority_schema_unavailable', __( 'Promoter venue authority storage is not ready.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( $actor_user_id < 1 || ! get_userdata( $actor_user_id ) || ! user_can( $actor_user_id, VenueAuthorization::ACCESS_CAPABILITY ) || ! function_exists( 'ec_feature_available' ) || ! ec_feature_available( VenueAuthorization::FEATURE, $actor_user_id ) ) {
			return $this->forbidden();
		}
		$venue    = get_term( $venue_term_id, 'venue' );
		$promoter = get_term( $promoter_term_id, 'promoter' );
		return $venue && ! is_wp_error( $venue ) && 'venue' === $venue->taxonomy && $promoter && ! is_wp_error( $promoter ) && 'promoter' === $promoter->taxonomy ? true : $this->forbidden();
	}

	/** Build the shared non-enumerating denial. */
	private function forbidden(): \WP_Error {
		return new \WP_Error( 'promoter_venue_action_forbidden', __( 'You are not authorized to manage this promoter venue grant.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
