<?php
/**
 * WordPress bridge for term-scoped team capabilities.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/TeamAuthorization.php';

/** Maps only events-owned meta capabilities; primitive checks pass through. */
final class TeamCapabilities {
	/** Every events-owned term capability, mapped to its team type and policy action. */
	public const CAPABILITIES = array(
		'ec_access_venue'            => array( 'venue', TeamAuthorization::ACTION_ACCESS_VENUE ),
		'ec_manage_venue_members'    => array( 'venue', TeamAuthorization::ACTION_MANAGE_MEMBERS ),
		'ec_manage_venue_finances'   => array( 'venue', TeamAuthorization::ACTION_MANAGE_FINANCES ),
		'ec_access_promoter'         => array( 'promoter', TeamAuthorization::ACTION_ACCESS_PROMOTER ),
		'ec_manage_promoter_members' => array( 'promoter', TeamAuthorization::ACTION_MANAGE_MEMBERS ),
		'ec_verify_promoter'         => array( 'promoter', TeamAuthorization::ACTION_VERIFY_ORGANIZATION ),
		'ec_revoke_promoter'         => array( 'promoter', TeamAuthorization::ACTION_REVOKE_ORGANIZATION ),
	);

	/** Register the single capability bridge. */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
	}

	/** Resolve an exact term capability without recursively mapping primitives. */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! isset( self::CAPABILITIES[ $cap ] ) ) {
			return $caps;
		}
		$term_id = (int) ( $args[0] ?? 0 );
		if ( $term_id < 1 ) {
			return array( 'do_not_allow' );
		}
		return true === self::authorize( $cap, $term_id, $user_id ) ? array( 'exist' ) : array( 'do_not_allow' );
	}

	/**
	 * Explain one term capability decision for callers that need a WP_Error.
	 *
	 * This is the same policy evaluation `map_meta_cap` performs; it returns the
	 * policy's own error so callers keep their established codes and statuses.
	 * Callers that already hold team membership rows under a transaction lock
	 * pass them as `$locked_memberships` so the decision uses those rows.
	 *
	 * @param string     $cap                Events-owned meta capability, such as `ec_access_venue`.
	 * @param int        $term_id            Exact venue or promoter term ID.
	 * @param int|null   $user_id            Network user ID; defaults to the current user.
	 * @param array|null $locked_memberships Membership rows read under the caller's lock.
	 * @return true|\WP_Error
	 */
	public static function authorize( string $cap, int $term_id, ?int $user_id = null, ?array $locked_memberships = null ) {
		if ( ! isset( self::CAPABILITIES[ $cap ] ) ) {
			return new \WP_Error( 'invalid_team_capability', __( 'The requested team capability is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		list( $team_type, $action ) = self::CAPABILITIES[ $cap ];
		$user_id                    = null === $user_id ? get_current_user_id() : $user_id;
		if ( null !== $locked_memberships ) {
			return ( new TeamAuthorization() )->authorize_locked( $user_id, $team_type, $term_id, $action, $locked_memberships );
		}
		return ( new TeamAuthorization() )->authorize( $user_id, $team_type, $term_id, $action );
	}
}
