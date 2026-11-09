<?php
/**
 * WordPress bridge for term-scoped team capabilities.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Maps only events-owned meta capabilities; primitive checks pass through. */
final class TeamCapabilities {
	/** Register the single capability bridge. */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
	}

	/** Resolve an exact term capability without recursively mapping primitives. */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		$map = array(
			'ec_access_venue'            => array( 'venue', 'access_venue' ),
			'ec_manage_venue_members'    => array( 'venue', 'manage_members' ),
			'ec_manage_venue_finances'   => array( 'venue', 'manage_finances' ),
			'ec_access_promoter'         => array( 'promoter', 'access_promoter' ),
			'ec_manage_promoter_members' => array( 'promoter', 'manage_members' ),
		);
		if ( ! isset( $map[ $cap ] ) ) {
			return $caps;
		}
		$term_id = (int) ( $args[0] ?? 0 );
		if ( $term_id < 1 ) {
			return array( 'do_not_allow' );
		}
		return ( new TeamAuthorization() )->can( $user_id, $map[ $cap ][0], $term_id, $map[ $cap ][1] ) ? array( 'exist' ) : array( 'do_not_allow' );
	}
}
