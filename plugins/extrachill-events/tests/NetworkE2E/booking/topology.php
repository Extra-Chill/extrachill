<?php
/**
 * Build the disposable Extra Chill multisite topology.
 *
 * @package ExtraChillEvents
 */

if ( ! is_multisite() ) {
	throw new RuntimeException( 'Booking network E2E requires multisite.' );
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.55';

$host  = wp_parse_url( network_home_url( '/' ), PHP_URL_HOST );
$sites = array( 'main' => get_main_site_id() );
foreach ( array(
	'community'         => 2,
	'shop'              => 3,
	'artist'            => 4,
	'placeholder-five'  => 5,
	'placeholder-six'   => 6,
	'events'            => 7,
	'placeholder-eight' => 8,
	'newsletter'        => 9,
	'docs'              => 10,
	'wire'              => 11,
	'studio'            => 12,
) as $key => $expected ) {
	$site_path = '/' . $key . '/';
	$existing  = get_sites(
		array(
			'domain' => $host,
			'path'   => $site_path,
			'number' => 1,
		)
	);
	$site_id   = $existing ? (int) $existing[0]->blog_id : wpmu_create_blog( $host, $site_path, 'Booking E2E ' . ucfirst( $key ), 1 );
	if ( is_wp_error( $site_id ) || (int) $site_id !== $expected ) {
		throw new RuntimeException( esc_html( sprintf( 'Expected %s blog ID %d.', $key, $expected ) ) );
	}
	if ( in_array( $key, array( 'events', 'studio' ), true ) ) {
		$sites[ $key ] = (int) $site_id;
	}
}
update_site_option( 'extrachill_booking_network_e2e_sites', $sites );

require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach ( array( 'extrachill-network/extrachill-network.php', 'extrachill-api/extrachill-api.php', 'extrachill-users/extrachill-users.php', 'data-machine/data-machine.php' ) as $plugin_file ) {
	if ( ! is_plugin_active_for_network( $plugin_file ) ) {
		$result = activate_plugin( $plugin_file, '', true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( esc_html( $plugin_file . ': ' . $result->get_error_message() ) );
		}
	}
}

switch_to_blog( (int) $sites['events'] );
foreach ( array( 'data-machine-events/data-machine-events.php', 'extrachill-events/extrachill-events.php' ) as $plugin_file ) {
	if ( ! is_plugin_active( $plugin_file ) ) {
		$result = activate_plugin( $plugin_file );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( esc_html( $plugin_file . ': ' . $result->get_error_message() ) );
		}
	}
}
if ( class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ) {
	\DataMachineEvents\Core\EventDatesTable::create_table();
}
restore_current_blog();

$companion = 'extrachill-events/extrachill-events-network-blocks.php';
if ( ! is_plugin_active_for_network( $companion ) ) {
	$result = activate_plugin( $companion, '', true );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( esc_html( $companion . ': ' . $result->get_error_message() ) );
	}
}
