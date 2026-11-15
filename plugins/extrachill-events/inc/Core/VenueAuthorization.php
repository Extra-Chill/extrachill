<?php
/**
 * Deprecated venue authorization facade kept only for extrachill-booking.
 *
 * Events code authorizes through `current_user_can( 'ec_*', $term_id )` or
 * TeamCapabilities::authorize(). This facade is removed in #1425 PR 2, once
 * extrachill-booking migrates to the same capabilities.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/TeamAuthorization.php';

/**
 * Compatibility facade over TeamAuthorization for extrachill-booking only.
 *
 * Removed in #1425 PR 2; Events code uses TeamCapabilities and TeamAuthorization.
 */
class VenueAuthorization {

	public const STATUS_ACTIVE  = TeamAuthorization::STATUS_ACTIVE;
	public const STATUS_INVITED = TeamAuthorization::STATUS_INVITED;
	public const STATUS_REVOKED = TeamAuthorization::STATUS_REVOKED;

	public const ACTION_ACCESS_VENUE    = TeamAuthorization::ACTION_ACCESS_VENUE;
	public const ACTION_MANAGE_MEMBERS  = TeamAuthorization::ACTION_MANAGE_MEMBERS;
	public const ACTION_MANAGE_FINANCES = TeamAuthorization::ACTION_MANAGE_FINANCES;

	public const ACCESS_CAPABILITY = TeamAuthorization::ACCESS_CAPABILITY;
	public const FEATURE           = TeamAuthorization::FEATURE;

	/** @var TeamAuthorization */
	private $policy;

	public function __construct( ?VenueMembershipRepository $memberships = null ) {
		$this->policy = new TeamAuthorization( $memberships );
	}

	public static function statuses(): array {
		return TeamAuthorization::statuses();
	}

	public static function actions(): array {
		return TeamAuthorization::actions( 'venue' );
	}

	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		return $this->policy->authorize( $user_id, 'venue', $venue_term_id, $action );
	}

	public function authorize_locked( int $user_id, int $venue_term_id, string $action, array $locked_memberships ) {
		return $this->policy->authorize_locked( $user_id, 'venue', $venue_term_id, $action, $locked_memberships );
	}

	public function can( int $user_id, int $venue_term_id, string $action ): bool {
		return $this->policy->can( $user_id, 'venue', $venue_term_id, $action );
	}

	public function active_membership( int $user_id, int $venue_term_id ) {
		return $this->policy->active_membership( $user_id, $venue_term_id );
	}

	public function is_administrator( int $user_id ): bool {
		return $this->policy->is_administrator( $user_id );
	}

	public function has_feature_access( int $user_id ): bool {
		return $this->policy->has_feature_access( $user_id );
	}
}
