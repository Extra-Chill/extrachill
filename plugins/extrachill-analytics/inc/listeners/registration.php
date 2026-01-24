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
function extrachill_analytics_track_user_registration( $user_id, $registration_page, $registration_source, $registration_method ) {
	wp_execute_ability(
		'extrachill/track-analytics-event',
		array(
			'event_type' => 'user_registration',
			'event_data' => array(
				'user_id' => $user_id,
				'source'  => $registration_source,
				'method'  => $registration_method,
			),
			'source_url' => $registration_page,
		)
	);
}

add_action( 'extrachill_new_user_registered', 'extrachill_analytics_track_user_registration', 10, 4 );
