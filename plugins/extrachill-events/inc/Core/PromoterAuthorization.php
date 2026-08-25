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
	 * Whether capability checks must compose the active execution principal.
	 *
	 * @var bool
	 */
	private $use_execution_principal;

	/**
	 * Construct against the canonical repository.
	 *
	 * @param PromoterAuthorityRepository|null $repository              Repository override.
	 * @param bool                             $use_execution_principal Whether to apply an active agent principal.
	 */
	public function __construct( ?PromoterAuthorityRepository $repository = null, bool $use_execution_principal = true ) {
		$this->repository              = $repository ? $repository : new PromoterAuthorityRepository();
		$this->use_execution_principal = $use_execution_principal;
	}

	/** Resolve the active execution principal, or null for a normal WordPress request. */
	public static function execution_principal() {
		$principal_class = '\\AgentsAPI\\AI\\WP_Agent_Execution_Principal';
		if ( ! class_exists( $principal_class ) ) {
			return null;
		}
		try {
			$principal = $principal_class::resolve();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
		return $principal instanceof $principal_class ? $principal : null;
	}

	/** Resolve the WordPress user represented by the active execution envelope. */
	public static function effective_user_id(): int {
		$principal = self::execution_principal();
		if ( false === $principal ) {
			return 0;
		}
		return $principal ? max( 0, (int) $principal->acting_user_id ) : get_current_user_id();
	}

	/**
	 * Compose an active principal ceiling with a represented user's capability.
	 *
	 * @param int    $user_id                 Represented WordPress user ID.
	 * @param string $capability              Required WordPress capability.
	 * @param bool   $use_execution_principal Whether to apply an active agent principal.
	 */
	public static function user_can( int $user_id, string $capability, bool $use_execution_principal = true ): bool {
		if ( ! $use_execution_principal ) {
			return user_can( $user_id, $capability );
		}
		$principal = self::execution_principal();
		if ( null === $principal ) {
			return user_can( $user_id, $capability );
		}
		if ( false === $principal || (int) $principal->acting_user_id !== $user_id || ! class_exists( '\\WP_Agent_WordPress_Authorization_Policy' ) ) {
			return false;
		}
		try {
			$policy = new \WP_Agent_WordPress_Authorization_Policy();
			return $policy->can( $principal, $capability );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Apply a capability only as an active execution-principal ceiling.
	 *
	 * Normal WordPress requests retain their established domain-only policy.
	 *
	 * @param int    $user_id    Represented WordPress user ID.
	 * @param string $capability Capability ceiling to enforce for a principal.
	 */
	public static function principal_allows( int $user_id, string $capability ): bool {
		$principal = self::execution_principal();
		if ( null === $principal ) {
			return true;
		}
		return false !== $principal && self::user_can( $user_id, $capability );
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
			return self::user_can( $user_id, 'manage_options', $this->use_execution_principal ) ? true : $this->denied();
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
		if ( ! is_array( $membership ) ) {
			return $this->denied();
		}
		if ( self::ACTION_ACCESS_PROMOTER === $action ) {
			$has_feature = self::user_can( $user_id, VenueAuthorization::ACCESS_CAPABILITY, $this->use_execution_principal ) && function_exists( 'ec_feature_available' ) && ec_feature_available( VenueAuthorization::FEATURE, $user_id );
			return $has_feature ? true : $this->denied();
		}
		if ( ! $membership['is_owner'] ) {
			return $this->denied();
		}
		return ! $this->use_execution_principal || self::principal_allows( $user_id, VenueAuthorization::ACCESS_CAPABILITY ) ? true : $this->denied();
	}

	/** Build the shared non-enumerating denial. */
	private function denied(): \WP_Error {
		return new \WP_Error( 'promoter_authority_forbidden', __( 'You are not authorized to perform this promoter action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
