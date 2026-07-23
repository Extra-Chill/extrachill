<?php
/**
 * Plugin Name: Extra Chill Auth Fuzz Fixture
 * Description: Test-only external-service seams for the disposable auth campaign.
 * Network: true
 */

add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
add_filter( 'pre_wp_mail', '__return_true' );

add_action(
	'init',
	static function () {
		if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			define( 'WP_ENVIRONMENT_TYPE', 'local' );
		}
	}
);
