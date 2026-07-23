<?php
/**
 * Plugin Name: Extra Chill Auth Fuzz Fixture
 * Description: Test-only external-service seams for the disposable auth campaign.
 * Network: true
 */

add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
add_filter( 'pre_wp_mail', '__return_true' );

add_action(
	'plugins_loaded',
	static function () {
		remove_action( 'extrachill_new_user_registered', 'extrachill_notify_admin_new_user', 10 );
		remove_action( 'wp_abilities_api_init', 'extrachill_users_register_welcome_email_ability' );
	},
	PHP_INT_MAX
);

add_action(
	'init',
	static function () {
		if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			define( 'WP_ENVIRONMENT_TYPE', 'local' );
		}
	}
);
