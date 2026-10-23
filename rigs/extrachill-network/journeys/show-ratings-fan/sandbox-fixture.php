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
		$log   = get_site_option( 'ec_show_ratings_observations', array() );
		$log[] = is_array( $body ) ? $body : array( 'raw' => 'unparseable' );
		update_site_option( 'ec_show_ratings_observations', array_slice( $log, -40 ) );
		status_header( 204 );
		exit;
	},
	0
);
