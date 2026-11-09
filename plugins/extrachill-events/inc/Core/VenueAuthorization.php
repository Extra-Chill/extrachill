<?php
/**
 * Venue-scoped membership and booking authorization.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/TeamAuthorization.php';

/** Composes WordPress feature access with an exact venue relationship. */
class VenueAuthorization {

	public const STATUS_ACTIVE  = 'active';
	public const STATUS_INVITED = 'invited';
	public const STATUS_REVOKED = 'revoked';

	public const ACTION_ACCESS_VENUE    = 'access_venue';
	public const ACTION_MANAGE_MEMBERS  = 'manage_members';
	public const ACTION_MANAGE_FINANCES = 'manage_finances';

	public const ACCESS_CAPABILITY = 'access_events_admin';
	public const FEATURE           = 'venue_booking';

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
