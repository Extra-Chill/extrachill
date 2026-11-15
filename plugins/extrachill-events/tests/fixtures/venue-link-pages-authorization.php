<?php

namespace ExtraChillEvents\Core;

final class TeamCapabilities {
	public static function authorize( string $cap, int $term_id, ?int $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;
		$key     = $user_id . ':' . $term_id;
		if ( 'ec_access_promoter' === $cap ) {
			$organization = $GLOBALS['promoter_link_page_fixture']['organizations'][ $term_id ] ?? null;
			$allowed      = ! empty( $organization )
				&& PromoterAuthorityRepository::STATUS_ACTIVE === $organization['status']
				&& ! empty( $GLOBALS['promoter_link_page_fixture']['memberships'][ $key ] )
				&& ! empty( $GLOBALS['promoter_link_page_fixture']['feature'][ $user_id ] );
			return $allowed ? true : new \WP_Error( 'promoter_authority_forbidden', 'Forbidden.', array( 'status' => 403 ) );
		}
		return 'ec_access_venue' === $cap && ! empty( $GLOBALS['venue_link_page_fixture']['memberships'][ $key ] )
			? true
			: new \WP_Error( 'venue_action_forbidden', 'Forbidden.', array( 'status' => 403 ) );
	}
}
