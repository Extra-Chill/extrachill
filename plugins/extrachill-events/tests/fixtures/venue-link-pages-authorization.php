<?php

namespace ExtraChillEvents\Core;

final class VenueAuthorization {
	public const ACTION_ACCESS_VENUE = 'access_venue';

	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		$key = $user_id . ':' . $venue_term_id;
		return self::ACTION_ACCESS_VENUE === $action && ! empty( $GLOBALS['venue_link_page_fixture']['memberships'][ $key ] )
			? true
			: new \WP_Error( 'venue_action_forbidden', 'Forbidden.', array( 'status' => 403 ) );
	}
}
