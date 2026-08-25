<?php
/**
 * Promoter organization authorization.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Authorizes administrators for bootstrap/revocation and owners for membership management. */
final class PromoterAuthorization {
	public const ACTION_VERIFY_ORGANIZATION = 'verify_organization';
	public const ACTION_REVOKE_ORGANIZATION = 'revoke_organization';
	public const ACTION_MANAGE_MEMBERS      = 'manage_members';
	public const ACTION_ACCESS_PROMOTER     = 'access_promoter';

	/**
	 * Promoter authority persistence.
	 *
	 * @var PromoterAuthorityRepository
	 */
	private $repository;

	/**
	 * Construct against the canonical repository.
	 *
	 * @param PromoterAuthorityRepository|null $repository Repository override.
	 */
	public function __construct( ?PromoterAuthorityRepository $repository = null ) {
		$this->repository = $repository ? $repository : new PromoterAuthorityRepository();
	}

	/**
	 * Authorize one user for one action on one exact promoter term.
	 *
	 * @param int    $user_id          Acting network user ID.
	 * @param int    $promoter_term_id Exact promoter term ID.
	 * @param string $action           Supported authority action.
	 */
	public function authorize( int $user_id, int $promoter_term_id, string $action ) {
		if ( ! in_array( $action, array( self::ACTION_VERIFY_ORGANIZATION, self::ACTION_REVOKE_ORGANIZATION, self::ACTION_MANAGE_MEMBERS, self::ACTION_ACCESS_PROMOTER ), true ) ) {
			return new \WP_Error( 'invalid_promoter_authority_action', __( 'The requested promoter authority action is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! PromoterAuthoritySchema::is_ready() ) {
			return new \WP_Error( 'promoter_authority_schema_unavailable', __( 'Promoter authority storage is not ready.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$term = get_term( $promoter_term_id, 'promoter' );
		if ( $promoter_term_id < 1 || ! $term || is_wp_error( $term ) || 'promoter' !== $term->taxonomy ) {
			return new \WP_Error( 'invalid_promoter_authority_term', __( 'A valid current-site promoter term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return $this->denied();
		}
		if ( in_array( $action, array( self::ACTION_VERIFY_ORGANIZATION, self::ACTION_REVOKE_ORGANIZATION ), true ) ) {
			return user_can( $user_id, 'manage_options' ) ? true : $this->denied();
		}

		$organization = $this->repository->get_organization( $promoter_term_id );
		if ( is_wp_error( $organization ) ) {
			return $organization;
		}
		if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'] ) {
			return $this->denied();
		}
		$membership = $this->repository->get_active_membership( $promoter_term_id, $user_id );
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}
		if ( self::ACTION_ACCESS_PROMOTER === $action ) {
			$has_feature = user_can( $user_id, VenueAuthorization::ACCESS_CAPABILITY ) && function_exists( 'ec_feature_available' ) && ec_feature_available( VenueAuthorization::FEATURE, $user_id );
			return is_array( $membership ) && $has_feature ? true : $this->denied();
		}
		return is_array( $membership ) && $membership['is_owner'] ? true : $this->denied();
	}

	/** Build the shared non-enumerating denial. */
	private function denied(): \WP_Error {
		return new \WP_Error( 'promoter_authority_forbidden', __( 'You are not authorized to perform this promoter action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
