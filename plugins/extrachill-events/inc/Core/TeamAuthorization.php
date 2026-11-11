<?php
/**
 * Canonical venue and promoter team policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Preserves each team's established ordering and execution-principal boundary. */
final class TeamAuthorization {
	public const STATUS_ACTIVE  = \ExtraChill\Users\Teams\TeamMembershipRepository::STATUS_ACTIVE;
	public const STATUS_INVITED = \ExtraChill\Users\Teams\TeamMembershipRepository::STATUS_INVITED;
	public const STATUS_REVOKED = \ExtraChill\Users\Teams\TeamMembershipRepository::STATUS_REVOKED;

	public const ACTION_ACCESS_VENUE    = 'access_venue';
	public const ACTION_MANAGE_MEMBERS  = 'manage_members';
	public const ACTION_MANAGE_FINANCES = 'manage_finances';

	public const ACCESS_CAPABILITY = 'access_events_admin';
	public const FEATURE           = 'venue_booking';

	public const ACTION_VERIFY_ORGANIZATION = 'verify_organization';
	public const ACTION_REVOKE_ORGANIZATION = 'revoke_organization';
	public const ACTION_ACCESS_PROMOTER     = 'access_promoter';

	/** @var VenueMembershipRepository|null */
	private $memberships;
	/** @var PromoterAuthorityRepository|null */
	private $promoters;
	/** @var bool */
	private $use_execution_principal;

	public function __construct( ?VenueMembershipRepository $memberships = null, ?PromoterAuthorityRepository $promoters = null, bool $use_execution_principal = true ) {
		$this->memberships             = $memberships;
		$this->promoters               = $promoters;
		$this->use_execution_principal = $use_execution_principal;
	}

	public static function statuses(): array {
		return array( self::STATUS_ACTIVE, self::STATUS_INVITED, self::STATUS_REVOKED );
	}

	public static function actions( string $team_type ): array {
		$actions = array(
			'venue'    => array( self::ACTION_ACCESS_VENUE, self::ACTION_MANAGE_MEMBERS, self::ACTION_MANAGE_FINANCES ),
			'promoter' => array( self::ACTION_VERIFY_ORGANIZATION, self::ACTION_REVOKE_ORGANIZATION, self::ACTION_MANAGE_MEMBERS, self::ACTION_ACCESS_PROMOTER ),
		);
		return $actions[ $team_type ] ?? array();
	}

	public function authorize( int $user_id, string $team_type, int $team_term_id, string $action ) {
		return $this->authorize_team( $user_id, $team_type, $team_term_id, $action, null );
	}

	public function authorize_locked( int $user_id, string $team_type, int $team_term_id, string $action, array $locked_memberships ) {
		return $this->authorize_team( $user_id, $team_type, $team_term_id, $action, $locked_memberships );
	}

	public function can( int $user_id, string $team_type, int $team_term_id, string $action ): bool {
		return true === $this->authorize( $user_id, $team_type, $team_term_id, $action );
	}

	private function authorize_team( int $user_id, string $team_type, int $team_term_id, string $action, ?array $locked_memberships ) {
		if ( 'promoter' === $team_type ) {
			$this->promoters = $this->promoters ?? new PromoterAuthorityRepository();
			return $this->authorize_promoter( $user_id, $team_term_id, $action, $locked_memberships );
		}
		if ( 'venue' !== $team_type ) {
			return new \WP_Error( 'invalid_team_type', __( 'The requested team type is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$this->memberships = $this->memberships ?? new VenueMembershipRepository();
		$valid             = $this->validate_venue_request( $user_id, $team_term_id, $action );
		if ( true !== $valid ) {
			return $valid;
		}
		if ( self::ACTION_MANAGE_MEMBERS === $action && $this->is_administrator( $user_id ) ) {
			return true;
		}
		// Preserve the venue feature gate before resolving membership.
		if ( in_array( $action, array( self::ACTION_ACCESS_VENUE, self::ACTION_MANAGE_FINANCES ), true ) && ! $this->has_feature_access( $user_id ) ) {
			return $this->denied( 'venue' );
		}
		return $this->authorize_venue_membership( $user_id, $this->resolve_membership( $user_id, $team_type, $team_term_id, $locked_memberships ), $action );
	}

	private function resolve_membership( int $user_id, string $team_type, int $team_term_id, ?array $locked_memberships ) {
		if ( null === $locked_memberships ) {
			return $this->active_team_membership( $user_id, $team_type, $team_term_id );
		}
		$key = $team_type . '_term_id';
		foreach ( $locked_memberships as $row ) {
			if ( isset( $row['team_type'] ) && $team_type !== $row['team_type'] ) {
				continue;
			}
			if ( (int) ( $row[ $key ] ?? $row['team_term_id'] ?? 0 ) !== $team_term_id || (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$row = 'venue' === $team_type ? $this->memberships->hydrate( $row ) : $this->promoters->hydrate_membership( $row );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			return self::STATUS_ACTIVE === ( $row['status'] ?? '' ) ? $row : null;
		}
		return null;
	}

	/** Read a promoter organization through the policy boundary. */
	public function promoter_organization( int $promoter_term_id ) {
		$this->promoters = $this->promoters ?? new PromoterAuthorityRepository();
		return $this->promoters->get_organization( $promoter_term_id );
	}

	public function active_team_membership( int $user_id, string $team_type, int $team_term_id ) {
		if ( 'promoter' === $team_type ) {
			$this->promoters = $this->promoters ?? new PromoterAuthorityRepository();
			return $this->promoters->get_active_membership( $team_term_id, $user_id );
		}
		return $this->active_membership( $user_id, $team_term_id );
	}

	private function denied( string $team_type ): \WP_Error {
		if ( 'promoter' === $team_type ) {
			return new \WP_Error( 'promoter_authority_forbidden', __( 'You are not authorized to perform this promoter action.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
	/** Validate the non-membership portion of one authorization request. */
	private function validate_venue_request( int $user_id, int $venue_term_id, string $action ) {
		if ( ! in_array( $action, self::actions( 'venue' ), true ) ) {
			return new \WP_Error(
				'invalid_venue_action',
				__( 'The requested venue action is not supported.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}
		if ( ! class_exists( \ExtraChill\Users\Teams\TeamMembershipRepository::class ) ) {
			return new \WP_Error(
				'venue_membership_schema_unavailable',
				__( 'Venue membership storage is not ready.', 'extrachill-events' ),
				array( 'status' => 503 )
			);
		}

		$venue = get_term( $venue_term_id, 'venue' );
		if ( $venue_term_id < 1 || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error(
				'invalid_venue_membership_venue',
				__( 'A valid Events venue term is required.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return $this->denied( 'venue' );
		}

		return true;
	}

	/** Apply action policy to a resolved membership. */
	private function authorize_venue_membership( int $user_id, $membership, string $action ) {
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}
		if ( ! is_array( $membership ) ) {
			return $this->denied( 'venue' );
		}
		if ( self::STATUS_ACTIVE !== ( $membership['status'] ?? '' ) ) {
			return $this->denied( 'venue' );
		}
		if ( in_array( $action, array( self::ACTION_MANAGE_MEMBERS, self::ACTION_MANAGE_FINANCES ), true ) ) {
			if ( ! $membership['is_owner'] ) {
				return $this->denied( 'venue' );
			}
			return ! $this->use_execution_principal || self::principal_allows( $user_id, self::ACCESS_CAPABILITY ) ? true : $this->denied( 'venue' );
		}

		return true;
	}

	/** Resolve only an active relationship for this exact venue and user. */
	public function active_membership( int $user_id, int $venue_term_id ) {
		$this->memberships = $this->memberships ?? new VenueMembershipRepository();
		return $this->memberships->get_active( $venue_term_id, $user_id );
	}

	/** Administrators override venue membership without receiving synthetic rows. */
	public function is_administrator( int $user_id ): bool {
		return $user_id > 0 && self::is_site_admin( $user_id, $this->use_execution_principal );
	}

	/** Check the established booking capability and rollout primitives. */
	public function has_feature_access( int $user_id ): bool {
		if ( $user_id < 1 || ! self::user_can( $user_id, self::ACCESS_CAPABILITY, $this->use_execution_principal ) ) {
			return false;
		}
		return function_exists( 'ec_feature_available' ) && ec_feature_available( self::FEATURE, $user_id );
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
	 * Whether a represented user holds site-administrator authority over promoter organizations.
	 *
	 * This is the single administrator check for verify, revoke, and membership
	 * management. It composes the active execution-principal ceiling, so an agent
	 * acting for an administrator without `manage_options` in its ceiling is denied.
	 *
	 * @param int  $user_id                 Represented WordPress user ID.
	 * @param bool $use_execution_principal Whether to apply an active agent principal.
	 */
	public static function is_site_admin( int $user_id, bool $use_execution_principal = true ): bool {
		return self::user_can( $user_id, 'manage_options', $use_execution_principal );
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

	private function authorize_promoter( int $user_id, int $promoter_term_id, string $action, ?array $locked_memberships = null ) {
		if ( ! in_array( $action, self::actions( 'promoter' ), true ) ) {
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
			return $this->denied( 'promoter' );
		}
		if ( in_array( $action, array( self::ACTION_VERIFY_ORGANIZATION, self::ACTION_REVOKE_ORGANIZATION ), true ) ) {
			return self::is_site_admin( $user_id, $this->use_execution_principal ) ? true : $this->denied( 'promoter' );
		}

		$organization = $this->promoters->get_organization( $promoter_term_id );
		if ( is_wp_error( $organization ) ) {
			return $organization;
		}
		if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'] ) {
			return $this->denied( 'promoter' );
		}
		if ( self::ACTION_MANAGE_MEMBERS === $action && self::is_site_admin( $user_id, $this->use_execution_principal ) ) {
			return true;
		}
		$membership = $this->resolve_membership( $user_id, 'promoter', $promoter_term_id, $locked_memberships );
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}
		if ( ! is_array( $membership ) ) {
			return $this->denied( 'promoter' );
		}
		if ( self::ACTION_ACCESS_PROMOTER === $action ) {
			$has_feature = self::user_can( $user_id, VenueAuthorization::ACCESS_CAPABILITY, $this->use_execution_principal ) && function_exists( 'ec_feature_available' ) && ec_feature_available( VenueAuthorization::FEATURE, $user_id );
			return $has_feature ? true : $this->denied( 'promoter' );
		}
		if ( ! $membership['is_owner'] ) {
			return $this->denied( 'promoter' );
		}
		return ! $this->use_execution_principal || self::principal_allows( $user_id, VenueAuthorization::ACCESS_CAPABILITY ) ? true : $this->denied( 'promoter' );
	}
}
