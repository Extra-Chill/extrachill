<?php
/**
 * Online Users Widget Display
 *
 * Hooks online users stats widget into theme footer on community and artist sites.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ec_users_display_online_stats_widget() {
	if ( function_exists( 'ec_display_online_users_stats' ) ) {
		ec_display_online_users_stats();
	}
}
add_action( 'extrachill_before_footer', 'ec_users_display_online_stats_widget' );
