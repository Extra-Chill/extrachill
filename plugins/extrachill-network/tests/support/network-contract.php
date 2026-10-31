<?php
/**
 * Shared network-contract test support (#446).
 *
 * The single place host-PHP test suites get the Extra Chill network contract —
 * the EC_BLOG_ID_* constants and the ec_get_blog_id() / ec_get_site_url() /
 * ec_get_blog_ids() / ec_get_domain_map() / ... helpers. Instead of hand-
 * rolling a copy of those functions (which silently drifts from production
 * when a site is added or renumbered), this file loads the REAL
 * inc/core/blog-ids.php from this plugin, after providing only the handful of
 * WordPress primitives that file touches at load time.
 *
 * It lives inside extrachill-network, the owner of the contract, so it ships
 * wherever blog-ids.php ships: in this plugin's own isolated test runs, in a
 * declared validation-dependency checkout, and in the monorepo.
 *
 * Usage — inside extrachill-network:
 *
 *     require_once __DIR__ . '/support/network-contract.php';
 *
 * Usage — from another component (resolve the network plugin first; Homeboy
 * exports EXTRACHILL_NETWORK_PATH to standalone smokes when the component
 * declares extrachill-network as a validation dependency, and the monorepo
 * sibling covers CI / local checkouts):
 *
 *     $ec_network_root = getenv( 'EXTRACHILL_NETWORK_PATH' ) ?: dirname( __DIR__, 2 ) . '/extrachill-network';
 *     require_once $ec_network_root . '/tests/support/network-contract.php';
 *
 * Require it AFTER the suite's own WordPress stubs: every primitive below is
 * guarded with function_exists(), so a suite that already records filters or
 * stores options keeps its own implementation.
 *
 * Fixture state:
 *   - $GLOBALS['ec_test_network_site_options'] backs the fallback
 *     get_site_option() (e.g. set 'ec_link_pages_site_cutover' => 1).
 *   - Any suite-defined apply_filters() keeps working, so the production
 *     'ec_site_url_override' filter remains the way to point URLs elsewhere.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! isset( $GLOBALS['ec_test_network_site_options'] ) || ! is_array( $GLOBALS['ec_test_network_site_options'] ) ) {
	$GLOBALS['ec_test_network_site_options'] = array();
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Minimal network-option read backed by $GLOBALS['ec_test_network_site_options'].
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Fallback value.
	 * @return mixed
	 */
	function get_site_option( $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['ec_test_network_site_options'] )
			? $GLOBALS['ec_test_network_site_options'][ $option ]
			: $default_value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * No-op hook registration; blog-ids.php registers filters at load time.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return bool
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook_name, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Identity filter: returns the unfiltered value.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value.
	 * @param mixed  ...$args   Extra args.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		unset( $hook_name, $args );
		return $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

$ec_test_network_blog_ids_file = dirname( __DIR__, 2 ) . '/inc/core/blog-ids.php';
if ( ! is_file( $ec_test_network_blog_ids_file ) ) {
	fwrite( STDERR, "network-contract test support: missing {$ec_test_network_blog_ids_file}\n" );
	exit( 1 );
}
require_once $ec_test_network_blog_ids_file;
unset( $ec_test_network_blog_ids_file );
