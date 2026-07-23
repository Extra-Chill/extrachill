<?php
/**
 * Venue-scoped booking authorization.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Composes WordPress feature access with an exact venue relationship. */
class VenueAuthorization {

	public const STATUS_ACTIVE  = 'active';
	public const STATUS_INVITED = 'invited';
	public const STATUS_REVOKED = 'revoked';

	public const ACTION_ACCESS_VENUE   = 'access_venue';
	public const ACTION_MANAGE_MEMBERS = 'manage_members';

	public const ACCESS_CAPABILITY = 'access_events_admin';
	public const FEATURE           = 'venue_booking';

	/** @var VenueMembershipRepository */
	private $memberships;

	public function __construct( ?VenueMembershipRepository $memberships = null ) {
		$this->memberships = $memberships ? $memberships : new VenueMembershipRepository();
	}

	/** Return every supported membership status. */
	public static function statuses(): array {
		return array( self::STATUS_ACTIVE, self::STATUS_INVITED, self::STATUS_REVOKED );
	}

	/** Return only the concrete authorization checks currently implemented. */
	public static function actions(): array {
		return array( self::ACTION_ACCESS_VENUE, self::ACTION_MANAGE_MEMBERS );
	}

	/** Authorize one network user for one action at one Events-site venue. */
	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		$valid = $this->validate_request( $user_id, $venue_term_id, $action );
		if ( true !== $valid ) {
			return $valid;
		}
		if ( self::ACTION_MANAGE_MEMBERS === $action && $this->is_administrator( $user_id ) ) {
			return true;
		}
		if ( ! $this->has_feature_access( $user_id ) ) {
			return $this->denied();
		}

		$membership = $this->active_membership( $user_id, $venue_term_id );
		return $this->authorize_membership( $membership, $action );
	}

	/** Authorize from lock-current membership rows without a consistent-snapshot reread. */
	public function authorize_locked( int $user_id, int $venue_term_id, string $action, array $locked_memberships ) {
		$valid = $this->validate_request( $user_id, $venue_term_id, $action );
		if ( true !== $valid ) {
			return $valid;
		}
		if ( self::ACTION_MANAGE_MEMBERS === $action && $this->is_administrator( $user_id ) ) {
			return true;
		}
		if ( ! $this->has_feature_access( $user_id ) ) {
			return $this->denied();
		}

		$membership = null;
		foreach ( $locked_memberships as $row ) {
			if ( (int) ( $row['venue_term_id'] ?? 0 ) !== $venue_term_id || (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$membership = $this->memberships->hydrate( $row );
			break;
		}
		return $this->authorize_membership( $membership, $action );
	}

	/** Validate the non-membership portion of one authorization request. */
	private function validate_request( int $user_id, int $venue_term_id, string $action ) {
		if ( ! in_array( $action, self::actions(), true ) ) {
			return new \WP_Error(
				'invalid_venue_action',
				__( 'The requested venue action is not supported.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}
		if ( ! BookingSchema::is_ready() ) {
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
			return $this->denied();
		}

		return true;
	}

	/** Apply action policy to a resolved membership. */
	private function authorize_membership( $membership, string $action ) {
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}
		if ( ! is_array( $membership ) ) {
			return $this->denied();
		}
		if ( self::STATUS_ACTIVE !== ( $membership['status'] ?? '' ) ) {
			return $this->denied();
		}
		if ( self::ACTION_MANAGE_MEMBERS === $action && ! $membership['is_owner'] ) {
			return $this->denied();
		}

		return true;
	}

	/** Boolean convenience wrapper for template and UI checks. */
	public function can( int $user_id, int $venue_term_id, string $action ): bool {
		return true === $this->authorize( $user_id, $venue_term_id, $action );
	}

	/** Resolve only an active relationship for this exact venue and user. */
	public function active_membership( int $user_id, int $venue_term_id ) {
		return $this->memberships->get_active( $venue_term_id, $user_id );
	}

	/** Administrators override venue membership without receiving synthetic rows. */
	public function is_administrator( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}

	/** Check the established WordPress capability and rollout primitives. */
	public function has_feature_access( int $user_id ): bool {
		if ( $user_id < 1 || ! user_can( $user_id, self::ACCESS_CAPABILITY ) ) {
			return false;
		}
		return function_exists( 'ec_feature_available' ) && ec_feature_available( self::FEATURE, $user_id );
	}

	/** Build a non-enumerating authorization denial. */
	private function denied(): \WP_Error {
		return new \WP_Error(
			'venue_action_forbidden',
			__( 'You are not authorized to perform this venue action.', 'extrachill-events' ),
			array( 'status' => 403 )
		);
	}
}
