<?php
/**
 * Canonical venue workspace URLs.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

/** Build the canonical venue workspace URL with optional tab context. */
function ec_events_get_venue_settings_url( int $venue_term_id = 0, string $tab = '' ): string {
	$events_blog_id = (int) ec_get_blog_id( 'events' );
	$base_url       = $events_blog_id > 0 ? get_home_url( $events_blog_id, '/venue-settings/' ) : home_url( '/venue-settings/' );
	if ( $venue_term_id > 0 ) {
		$base_url = add_query_arg( array( 'venue_id' => $venue_term_id ), $base_url );
	}
	return $base_url . ( '' !== $tab ? '#tab-' . $tab : '' );
}

/** Build the canonical administrator claim-review URL. */
function ec_events_get_venue_claim_review_url( int $venue_term_id = 0 ): string {
	return ec_events_get_venue_settings_url( $venue_term_id, 'claims' );
}
