<?php
/**
 * Plugin Name: Extra Chill Artist Platform Harness Topology
 * Description: Test-only must-use plugin. Seeds the canonical network sites before WP Codebox activates plugins.
 *
 * @package ExtraChillArtistPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ec_harness_seed = WP_PLUGIN_DIR . '/extrachill-artist-platform/tests/support/topology-seed.php';
if ( ! is_file( $ec_harness_seed ) ) {
	$ec_harness_seed = dirname( __DIR__ ) . '/topology-seed.php';
}
require_once $ec_harness_seed;

/**
 * Whether the network tables are installed.
 *
 * The test network may not have a `blogs` row for the primary site yet (the
 * seed adds it), so this checks the network row instead of get_site( 1 ).
 *
 * @return bool
 */
function ec_artist_harness_network_installed() {
	global $wpdb;
	$suppress = $wpdb->suppress_errors();
	$network  = $wpdb->get_var( "SELECT id FROM {$wpdb->site} LIMIT 1" );
	$wpdb->suppress_errors( $suppress );
	return ! empty( $network );
}

/**
 * Seed once, as soon as the network tables exist.
 *
 * Two loaders reach this file. Recipe setup steps load it as a normal
 * must-use plugin, before the templating constants install_blog() needs, so
 * it seeds on `init`. The managed PHPUnit bootstrap loads it while it installs
 * the test network (before any tables exist) and then fires each plugin's
 * `activate_{plugin}` hook directly without `init`, so it seeds on the first
 * activation hook.
 *
 * @return void
 */
function ec_artist_harness_maybe_seed_topology() {
	static $seeded = false;
	if ( $seeded || ! is_multisite() || ! defined( 'WP_DEFAULT_THEME' ) || ! ec_artist_harness_network_installed() ) {
		return;
	}
	$seeded = true;
	remove_action( 'all', 'ec_artist_harness_seed_before_activation' );
	ec_artist_platform_test_seed_topology();
}

/**
 * Seed before the first plugin activation callback runs.
 *
 * @param string $hook_name Current hook.
 * @return void
 */
function ec_artist_harness_seed_before_activation( $hook_name ) {
	if ( is_string( $hook_name ) && 0 === strpos( $hook_name, 'activate_' ) ) {
		ec_artist_harness_maybe_seed_topology();
	}
}

ec_artist_harness_maybe_seed_topology();
add_action( 'init', 'ec_artist_harness_maybe_seed_topology', -1000 );
add_action( 'all', 'ec_artist_harness_seed_before_activation' );
