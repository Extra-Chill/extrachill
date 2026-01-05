<?php
/**
 * User Registration Event Listener
 *
 * Tracks user registration events when fired by extrachill-users.
 *
 * @package ExtraChill\Analytics
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Track user registration event.
 *
 * @param int    $user_id             The new user's ID.
 * @param string $registration_page   URL of the page where user registered.
 * @param string $registration_source Source identifier (web, extrachill-app, etc.).
 * @param string $registration_method Method used (form, google, etc.).
 */
function ec_analytics_track_user_registration( $user_id, $registration_page, $registration_source, $registration_method ) {
	if ( ! function_exists( 'ec_track_event' ) ) {
		return;
	}

	ec_track_event(
		'user_registration',
		array(
			'user_id' => $user_id,
			'source'  => $registration_source,
			'method'  => $registration_method,
		),
		$registration_page
	);
}

add_action( 'extrachill_new_user_registered', 'ec_analytics_track_user_registration', 10, 4 );
