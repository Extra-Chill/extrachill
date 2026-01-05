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
 * @param string $context    Form context (homepage, navigation, content, archive).
 * @param string $list_id    Sendy list ID.
 * @param string $source_url URL of the page where the form was submitted.
 */
function ec_analytics_track_newsletter_signup( $context, $list_id, $source_url ) {
	if ( ! function_exists( 'ec_track_event' ) ) {
		return;
	}

	ec_track_event(
		'newsletter_signup',
		array(
			'context' => $context,
			'list_id' => $list_id,
		),
		$source_url
	);
}

add_action( 'extrachill_newsletter_subscribed', 'ec_analytics_track_newsletter_signup', 10, 3 );
