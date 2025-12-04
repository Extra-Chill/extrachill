<?php
/**
 * Plugin Name: Extra Chill News Wire
 * Description: Festival Wire custom post type and functionality for music festival coverage with fast-loading archives and template overrides.
 * Version: 0.2.0
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
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-ajax.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-query-filters.php';

function enqueue_festival_wire_assets() {
	global $wp_query;

	// Archive pages: Load CSS, JS, and AJAX functionality
	if ( is_post_type_archive( 'festival_wire' ) ) {

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

		// Festival Wire JavaScript with AJAX support
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

		// AJAX localization for load-more
		$localize_params = array(
			'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'load_more_nonce' => wp_create_nonce( 'festival_wire_load_more_nonce' ),
				'query_vars'      => json_encode( $wp_query->query_vars ),
				'max_pages'       => $wp_query->max_num_pages
			);

			wp_localize_script(
				'extrachill-festival-wire',
				'festivalWireParams',
				$localize_params
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



