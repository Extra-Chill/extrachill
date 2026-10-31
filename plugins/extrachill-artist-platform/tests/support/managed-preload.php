<?php
/**
 * Managed-harness topology seed for the Extra Chill Artist Platform suite.
 *
 * Required during the WP Codebox managed PHPUnit bootstrap via the
 * `wp_codebox_phpunit_preload_files` homeboy setting. The sandbox boots a
 * multisite WordPress whose blogs table is empty, while every cross-site code
 * path in the plugin resolves canonical blog IDs through the real
 * extrachill-network primitives (EC_BLOG_ID_ARTIST = 4, EC_BLOG_ID_EVENTS = 7).
 * This seed creates those exact sites so switch_to_blog() operates on real
 * per-site tables instead of simulation.
 *
 * Runs once per PHPUnit process, before any test executes. Site rows created
 * here survive the per-test transaction rollback because they precede the
 * first transaction.
 *
 * @package ExtraChillArtistPlatform
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/topology-seed.php';

ec_artist_platform_test_seed_topology();

// Public Link Page redirects end the request with exit; inside PHPUnit that
// would end the whole suite. Link Pages exposes this seam for tests.
add_filter( 'ec_link_page_terminate_request', '__return_false' );

// The WordPress PHPUnit bootstrap unhooks core's ability categories
// (_unhook_core_ability_categories_registration removes the priority-10
// callback). Link Pages registers abilities in the core `site` category, which
// always exists in production, so restore the core categories after the unhook.
if ( function_exists( 'wp_register_core_ability_categories' ) ) {
	add_action( 'wp_abilities_api_categories_init', 'wp_register_core_ability_categories', 11 );
}
