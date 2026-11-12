<?php
/**
 * Events-owned recipient lookup for commerce notifications.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;
use ExtraChillEvents\Core\PromoterAuthorityRepository;

/** Internal server-side helper; recipient addresses are not exposed through REST. */
class CommerceSellerNotifications {
	/**
	 * Resolve explicit finance participants on the Events site, independent of the checkout actor.
	 *
	 * @return array{name: string, recipients: string[]}
	 */
	public static function get_data( string $type, int $id, ?VenueMembershipRepository $memberships = null, ?VenueAuthorization $venues = null, ?PromoterAuthorityRepository $promoters = null ): array {
		$result  = array(
			'name'       => '',
			'recipients' => array(),
		);
		$blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
		if ( ! $blog_id || $id < 1 || ! in_array( $type, array( 'venue', 'promoter' ), true ) ) {
			return $result;
		}
		$switched = get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}
		try {
			$term = get_term( $id, $type );
			if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $type ) {
				return $result;
			}
			$result['name'] = (string) $term->name;
			$rows           = array();
			if ( 'venue' === $type ) {
				$memberships = $memberships ?? new VenueMembershipRepository();
				$venues      = $venues ?? new VenueAuthorization( $memberships );
				// Enumerate only explicit members, never global administrators. Read every page.
				for ( $offset = 0; ; $offset += 100 ) {
					$page = $memberships->list_for_venue( $id, array(
						'status'   => 'active',
						'is_owner' => true,
						'limit'    => 100,
						'offset'   => $offset,
					) );
					if ( is_wp_error( $page ) ) {
						return $result;
					}
					$rows = array_merge( $rows, $page );
					if ( count( $page ) < 100 ) {
						break;
					}
				}
			} else {
				$promoters    = $promoters ?? new PromoterAuthorityRepository();
				$organization = $promoters->get_organization( $id );
				if ( ! is_array( $organization ) || 'active' !== ( $organization['status'] ?? '' ) ) {
					return $result;
				}
				$rows = $promoters->list_memberships( $id );
				if ( is_wp_error( $rows ) ) {
					return $result;
				}
			}
			foreach ( $rows as $row ) {
				if ( 'active' !== ( $row['status'] ?? '' ) || empty( $row['is_owner'] ) ) {
					continue;
				}
				$user_id = (int) $row['user_id'];
				if ( 'venue' === $type && ! $venues->can( $user_id, $id, VenueAuthorization::ACTION_MANAGE_FINANCES ) ) {
					continue;
				}
				$user = get_userdata( $user_id );
				if ( $user && ! empty( $user->user_email ) ) {
					$result['recipients'][] = $user->user_email;
				}
			}
			$result['recipients'] = array_values( array_unique( $result['recipients'] ) );
			return $result;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
