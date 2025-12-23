<?php
/**
 * Plugin Name: Extra Chill News Wire
 * Description: Festival Wire custom post type and functionality for music festival coverage with fast-loading archives and template overrides.
 * Version: 0.3.5
 * Author: Chris Huber
 * Text Domain: extrachill
 * Domain Path: /languages
 *
 * @package ExtraChillNewsWire
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FESTIVAL_WIRE_PLUGIN_DIR', plugin_dir_path(__FILE__) );
define( 'FESTIVAL_WIRE_INCLUDE_DIR', FESTIVAL_WIRE_PLUGIN_DIR . 'includes/' );
define( 'FESTIVAL_WIRE_TEMPLATE_DIR', FESTIVAL_WIRE_PLUGIN_DIR . 'templates/' );

require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-post-type.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-query-filters.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'core/breadcrumbs.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'core/post-meta.php';

function enqueue_festival_wire_assets() {
	global $wp_query;

	$wire_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'wire' ) : null;

	// Archive pages: Load CSS and JS
	if ( is_post_type_archive( 'festival_wire' ) || ( $wire_site_url && ( is_front_page() || is_home() ) && untrailingslashit( home_url() ) === untrailingslashit( $wire_site_url ) ) ) {

		// Main Festival Wire CSS
		$css_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		$css_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'extrachill-festival-wire',
				$css_file_uri,
				array(),
				filemtime( $css_file_path )
			);
		}

		// Festival Wire JavaScript (filters and FAQ accordion)
		$js_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.js';
		$js_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.js';
		if ( file_exists( $js_file_path ) ) {
			wp_enqueue_script(
				'extrachill-festival-wire',
				$js_file_uri,
				array(),
				filemtime( $js_file_path ),
				true
			);
		}
	} elseif ( is_singular( 'festival_wire' ) ) {
		// Single pages: Load CSS only
		$css_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		$css_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'extrachill-festival-wire',
				$css_file_uri,
				array(),
				filemtime( $css_file_path )
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'enqueue_festival_wire_assets' );

add_filter( 'extrachill_template_archive', 'ec_news_wire_override_archive_template' );
add_filter( 'extrachill_template_single_post', 'ec_news_wire_override_single_template' );
add_action( 'extrachill_homepage_content', 'ec_news_wire_render_wire_hub_homepage' );

/**
 * Override archive template for festival_wire post type
 *
 * @param string $template Default template path from theme
 * @return string Template path to use
 */
function ec_news_wire_override_archive_template( $template ) {
	if ( is_post_type_archive( 'festival_wire' ) ) {
		return FESTIVAL_WIRE_TEMPLATE_DIR . 'archive-festival_wire.php';
	}
	return $template;
}

/**
 * Override single template for festival_wire post type
 *
 * @param string $template Default template path from theme
 * @return string Template path to use
 */
function ec_news_wire_override_single_template( $template ) {
	if ( is_singular( 'festival_wire' ) ) {
		return FESTIVAL_WIRE_TEMPLATE_DIR . 'single-festival_wire.php';
	}
	return $template;
}

function ec_news_wire_render_wire_hub_homepage() {
	if ( ! is_front_page() && ! is_home() ) {
		return;
	}

	if ( is_admin() ) {
		return;
	}

	if ( ! function_exists( 'ec_get_site_url' ) ) {
		return;
	}

	$wire_site_url = ec_get_site_url( 'wire' );
	if ( ! $wire_site_url ) {
		return;
	}

	if ( untrailingslashit( home_url() ) !== untrailingslashit( $wire_site_url ) ) {
		return;
	}

	require FESTIVAL_WIRE_TEMPLATE_DIR . 'home-wire.php';
}



