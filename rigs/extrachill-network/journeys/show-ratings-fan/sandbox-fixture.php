<?php
/**
 * Sandbox fixture for show-ratings-fan: stop mail and record browser
 * observations posted by the journey steps.
 *
 * @package ExtraChillNetwork
 */

add_filter( 'pre_wp_mail', '__return_true' );

add_action(
	'init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sandbox-only evidence sink.
		if ( ! isset( $_GET['ec_show_ratings_observe'] ) ) {
			return;
		}
		$body  = json_decode( (string) file_get_contents( 'php://input' ), true );
		// Capture real storage at each browser checkpoint, not just final state.
		$fixture = get_site_option( 'ec_rig_journey_fixture_show_ratings_fan', array() );
		if ( is_array( $body ) ) {
			$body['viewer_id'] = get_current_user_id();
			$previous_user = get_current_user_id();
			wp_set_current_user( (int) ( $fixture['fan_id'] ?? 0 ) );
			$truth = wp_get_ability( 'extrachill/get-my-show-ratings' )->execute( array(
				'event_ids' => array( (int) $fixture['recent_id'], (int) $fixture['older_id'] ),
				'blog_id' => (int) $fixture['events_blog_id'],
			) );
			wp_set_current_user( $previous_user );
			$body['server'] = is_wp_error( $truth ) ? array( 'error' => $truth->get_error_message() ) : $truth;
		}
		$log   = get_site_option( 'ec_show_ratings_observations', array() );
		$log[] = is_array( $body ) ? $body : array( 'raw' => 'unparseable' );
		update_site_option( 'ec_show_ratings_observations', array_slice( $log, -40 ) );
		status_header( 204 );
		exit;
	},
	0
);
