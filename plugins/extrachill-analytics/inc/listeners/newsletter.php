<?php
/**
 * Newsletter Event Listener
 *
 * Tracks newsletter subscription events when fired by extrachill-newsletter.
 *
 * @package ExtraChill\Analytics
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Track newsletter subscription event.
 *
 * @param string $context    Form context (homepage, navigation, content, archive, registration).
 * @param string $list_id    Sendy list ID.
 * @param string $source_url URL of the page where the form was submitted.
 */
function extrachill_analytics_track_newsletter_signup( $context, $list_id, $source_url ) {
	wp_execute_ability(
		'extrachill/track-analytics-event',
		array(
			'event_type' => 'newsletter_signup',
			'event_data' => array(
				'context' => $context,
				'list_id' => $list_id,
			),
			'source_url' => $source_url,
		)
	);
}

add_action( 'extrachill_newsletter_subscribed', 'extrachill_analytics_track_newsletter_signup', 10, 3 );
