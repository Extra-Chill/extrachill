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

/** Applies the bounded venue booking role matrix. */
class VenueAuthorization {

	public const ROLE_OWNER           = 'owner';
	public const ROLE_BOOKING_MANAGER = 'booking_manager';
	public const ROLE_MARKETING       = 'marketing';
	public const ROLE_FINANCE         = 'finance';
	public const ROLE_VIEWER          = 'viewer';

	public const STATUS_ACTIVE  = 'active';
	public const STATUS_INVITED = 'invited';
	public const STATUS_REVOKED = 'revoked';

	public const ACTION_VIEW_BOOKINGS        = 'view_bookings';
	public const ACTION_MANAGE_INQUIRIES     = 'manage_inquiries';
	public const ACTION_MANAGE_HOLDS         = 'manage_holds';
	public const ACTION_SEND_COMMUNICATION   = 'send_communication';
	public const ACTION_MANAGE_MARKETING     = 'manage_marketing';
	public const ACTION_VIEW_SALES           = 'view_sales';
	public const ACTION_FINALIZE_SETTLEMENTS = 'finalize_settlements';
	public const ACTION_MANAGE_MEMBERS       = 'manage_members';

	/** @var VenueMembershipRepository */
	private $memberships;

	public function __construct( ?VenueMembershipRepository $memberships = null ) {
		$this->memberships = $memberships ? $memberships : new VenueMembershipRepository();
	}

	/** Return every supported venue role. */
	public static function roles(): array {
		return array_keys( self::matrix() );
	}

	/** Return every supported membership status. */
	public static function statuses(): array {
		return array( self::STATUS_ACTIVE, self::STATUS_INVITED, self::STATUS_REVOKED );
	}

	/** Return every supported booking-domain action. */
	public static function actions(): array {
		$actions = array();
		foreach ( self::matrix() as $allowed ) {
			$actions = array_merge( $actions, $allowed );
		}
		return array_values( array_unique( $actions ) );
	}

	/** Authorize one network user for one action at one Events-site venue. */
	public function authorize( int $user_id, int $venue_term_id, string $action ) {
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

		if ( $this->is_administrator( $user_id ) ) {
			return true;
		}

		$membership = $this->active_membership( $user_id, $venue_term_id );
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}
		if ( ! is_array( $membership ) || ! $this->role_allows( $membership['role'], $action ) ) {
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

	/** Check one role/action pair against the explicit domain matrix. */
	public function role_allows( string $role, string $action ): bool {
		$matrix = self::matrix();
		return isset( $matrix[ $role ] ) && in_array( $action, $matrix[ $role ], true );
	}

	/** Administrators override venue membership without receiving synthetic rows. */
	public function is_administrator( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}

	/** Return the explicit role/action policy. */
	private static function matrix(): array {
		return array(
			self::ROLE_OWNER           => array(
				self::ACTION_VIEW_BOOKINGS,
				self::ACTION_MANAGE_INQUIRIES,
				self::ACTION_MANAGE_HOLDS,
				self::ACTION_SEND_COMMUNICATION,
				self::ACTION_MANAGE_MARKETING,
				self::ACTION_VIEW_SALES,
				self::ACTION_FINALIZE_SETTLEMENTS,
				self::ACTION_MANAGE_MEMBERS,
			),
			self::ROLE_BOOKING_MANAGER => array(
				self::ACTION_VIEW_BOOKINGS,
				self::ACTION_MANAGE_INQUIRIES,
				self::ACTION_MANAGE_HOLDS,
				self::ACTION_SEND_COMMUNICATION,
			),
			self::ROLE_MARKETING       => array(
				self::ACTION_VIEW_BOOKINGS,
				self::ACTION_MANAGE_MARKETING,
			),
			self::ROLE_FINANCE         => array(
				self::ACTION_VIEW_BOOKINGS,
				self::ACTION_VIEW_SALES,
				self::ACTION_FINALIZE_SETTLEMENTS,
			),
			self::ROLE_VIEWER          => array( self::ACTION_VIEW_BOOKINGS ),
		);
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
