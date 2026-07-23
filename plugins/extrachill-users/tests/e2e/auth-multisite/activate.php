<?php

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugins = array(
	'00-auth-fuzz-fixture/auth-fuzz-fixture.php',
	'extrachill-network/extrachill-network.php',
	'extrachill-api/extrachill-api.php',
	'wp-native-auth/wp-native-auth.php',
	'extrachill-analytics/extrachill-analytics.php',
	'extrachill-users/extrachill-users.php',
);
foreach ( $plugins as $plugin ) {
	if ( ! is_plugin_active_for_network( $plugin ) ) {
		$result = activate_plugin( $plugin, '', true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $plugin . ': ' . $result->get_error_message() );
		}
	}
}
if ( function_exists( 'extrachill_analytics_events_create_table' ) ) {
	extrachill_analytics_events_create_table();
}
