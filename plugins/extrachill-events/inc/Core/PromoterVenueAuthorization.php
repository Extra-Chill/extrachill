<?php
/**
 * Effective promoter venue delegated action authorization.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Resolves only the narrow promoter grant formula. */
final class PromoterVenueAuthorization {
	/**
	 * Promoter organization persistence.
	 *
	 * @var PromoterAuthorityRepository
	 */
	private $promoters;
	/**
	 * Venue grant persistence.
	 *
	 * @var PromoterVenueGrantRepository
	 */
	private $grants;

	/** Construct the effective authorization boundary. */
	public function __construct( ?PromoterAuthorityRepository $promoters = null, ?PromoterVenueGrantRepository $grants = null ) {
		$this->promoters = $promoters ? $promoters : new PromoterAuthorityRepository();
		$this->grants    = $grants ? $grants : new PromoterVenueGrantRepository();
	}

	/** Authorize one explicit member for one exact promoter, venue, and action. */
	public function authorize( int $user_id, int $promoter_term_id, int $venue_term_id, string $action ) {
		$valid = $this->validate_request( $user_id, $promoter_term_id, $venue_term_id, $action );
		if ( true !== $valid ) {
			return $valid;
		}
		$organization = $this->promoters->get_organization( $promoter_term_id );
		if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'] ) {
			return is_wp_error( $organization ) ? $organization : $this->denied();
		}
		$membership = $this->promoters->get_active_membership( $promoter_term_id, $user_id );
		if ( ! is_array( $membership ) ) {
			return is_wp_error( $membership ) ? $membership : $this->denied();
		}
		$grant = $this->grants->get( $promoter_term_id, $venue_term_id, $action );
		if ( ! is_array( $grant ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $grant['status'] ) {
			return is_wp_error( $grant ) ? $grant : $this->denied();
		}
		return true;
	}

	/** Return effective venue IDs after validating organization and membership. */
	public function effective_venue_ids( int $user_id, int $promoter_term_id, string $action ) {
		$valid = $this->validate_request( $user_id, $promoter_term_id, 1, $action, false );
		if ( true !== $valid ) {
			return $valid;
		}
		$organization = $this->promoters->get_organization( $promoter_term_id );
		$membership   = $this->promoters->get_active_membership( $promoter_term_id, $user_id );
		if ( is_wp_error( $organization ) ) {
			return $organization;
		}
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}
		if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'] || ! is_array( $membership ) ) {
			return $this->denied();
		}
		$venue_ids = $this->grants->list_active_venue_ids( $promoter_term_id, $action );
		if ( is_wp_error( $venue_ids ) ) {
			return $venue_ids;
		}
		return array_values(
			array_filter(
				$venue_ids,
				static function ( int $venue_term_id ): bool {
					$venue = get_term( $venue_term_id, 'venue' );
					return $venue && ! is_wp_error( $venue ) && 'venue' === $venue->taxonomy;
				}
			)
		);
	}

	/** Validate every non-persisted term in the effective-access formula. */
	private function validate_request( int $user_id, int $promoter_term_id, int $venue_term_id, string $action, bool $validate_venue = true ) {
		if ( ! PromoterAuthoritySchema::is_ready() || ! BookingSchema::is_ready() ) {
			return new \WP_Error( 'promoter_venue_authority_schema_unavailable', __( 'Promoter venue authority storage is not ready.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$promoter = get_term( $promoter_term_id, 'promoter' );
		if ( $promoter_term_id < 1 || ! $promoter || is_wp_error( $promoter ) || 'promoter' !== $promoter->taxonomy ) {
			return new \WP_Error( 'invalid_promoter_authority_term', __( 'A valid current-site promoter term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( $validate_venue ) {
			$venue = get_term( $venue_term_id, 'venue' );
			if ( $venue_term_id < 1 || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
				return new \WP_Error( 'invalid_promoter_venue_grant_venue', __( 'A valid current-site venue term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
		}
		if ( ! in_array( $action, PromoterVenueGrantRepository::actions(), true ) ) {
			return new \WP_Error( 'invalid_promoter_venue_grant_action', __( 'The delegated venue action is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( $user_id < 1 || ! get_userdata( $user_id ) || ! user_can( $user_id, VenueAuthorization::ACCESS_CAPABILITY ) || ! function_exists( 'ec_feature_available' ) || ! ec_feature_available( VenueAuthorization::FEATURE, $user_id ) ) {
			return $this->denied();
		}
		return true;
	}

	/** Build the shared non-enumerating denial. */
	private function denied(): \WP_Error {
		return new \WP_Error( 'promoter_venue_action_forbidden', __( 'You are not authorized for this delegated promoter venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
