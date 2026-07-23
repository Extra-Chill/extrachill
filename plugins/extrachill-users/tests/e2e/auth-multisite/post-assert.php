<?php

$plan = get_site_option( 'extrachill_auth_fuzz_plan', array() );
$sites = get_site_option( 'extrachill_auth_fuzz_sites', array() );
$browser_user = get_user_by( 'email', $plan['browser_email'] ?? '' );
if ( ! $browser_user ) {
	throw new RuntimeException( 'Browser registration did not create its network user.' );
}
if ( $browser_user->user_login !== ( $plan['browser_username'] ?? '' ) ) {
	throw new RuntimeException( 'Browser onboarding did not persist the generated username.' );
}
if ( '1' !== (string) get_user_meta( $browser_user->ID, 'onboarding_completed', true ) ) {
	throw new RuntimeException( 'Browser onboarding did not reach its completed state.' );
}
if ( ! is_user_member_of_blog( $browser_user->ID, (int) $sites['community'] ) ) {
	throw new RuntimeException( 'Browser-created user lacks Community membership.' );
}
$emitted = get_site_option( 'extrachill_auth_fuzz_emitted_cookie', array() );
$observations = get_site_option( 'extrachill_auth_fuzz_browser_observations', array() );
if ( (int) ( $emitted['valid'] ?? 0 ) !== (int) $browser_user->ID ) {
	throw new RuntimeException( 'The refreshed onboarding cookie does not validate for the renamed user.' );
}
foreach ( array( 'artist', 'events' ) as $surface ) {
	$observation = $observations[ $surface ] ?? array();
	if ( (int) ( $observation['user_id'] ?? 0 ) !== (int) $browser_user->ID ) {
		throw new RuntimeException(
			sprintf(
				'Browser session did not survive on %s (cookie_match=%s).',
				$surface,
				hash_equals( (string) ( $emitted['hash'] ?? '' ), (string) ( $observation['cookie_hash'] ?? '' ) ) ? 'yes' : 'no'
			)
		);
	}
}
global $wpdb;
$table = function_exists( 'extrachill_analytics_events_table' ) ? extrachill_analytics_events_table() : $wpdb->base_prefix . 'extrachill_analytics_events';
$registrations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND user_id = %d", 'user_registration', $browser_user->ID ) );
$completions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND user_id = %d", 'onboarding_completed', $browser_user->ID ) );
if ( 1 !== $registrations || 1 !== $completions ) {
	throw new RuntimeException( sprintf( 'Expected one registration and completion event; got %d/%d.', $registrations, $completions ) );
}
printf( "Auth fuzz browser mutation passed for user %d.\n", $browser_user->ID );
