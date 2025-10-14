<?php
/**
 * Plugin Name: Extra Chill News Wire
 * Description: Festival Wire custom post type and functionality for music festival coverage. Provides fast-loading archive with AJAX pagination, community tip submission system, and template overrides.
 * Version: 1.0
 * Author: Chris Huber
 * Text Domain: extrachill
 * Domain Path: /languages
 *
 * @package ExtraChillNewsWire
 * @since 1.0.0
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

	// Homepage: Load ticker CSS only
	if ( is_front_page() || is_home() ) {
		$ticker_css_path = plugin_dir_path(__FILE__) . 'assets/festival-wire-home-ticker.css';
		$ticker_css_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire-home-ticker.css';
		if ( file_exists( $ticker_css_path ) ) {
			wp_enqueue_style(
				'extrachill-festival-wire-home-ticker',
				$ticker_css_uri,
				array(),
				filemtime( $ticker_css_path )
			);
		}
	}

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
				array( 'jquery' ),
				filemtime( $js_file_path ),
				true
			);

			// AJAX localization for load-more and tip submission
			$localize_params = array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'tip_nonce'       => wp_create_nonce( 'festival_wire_tip_nonce' ),
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
		// Single pages: Load CSS, JS, and tip form functionality
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
		// Festival Wire JavaScript for single pages
		$js_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.js';
		$js_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.js';
		if ( file_exists( $js_file_path ) ) {
			wp_enqueue_script(
				'extrachill-festival-wire',
				$js_file_uri,
				array( 'jquery' ),
				filemtime( $js_file_path ),
				true
			);

			// AJAX localization for tip submission on single pages
			$localize_params = array(
				'ajaxurl'   => admin_url( 'admin-ajax.php' ),
				'tip_nonce' => wp_create_nonce( 'festival_wire_tip_nonce' ),
			);
			wp_localize_script(
				'extrachill-festival-wire',
				'festivalWireParams',
				$localize_params
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'enqueue_festival_wire_assets' );

function display_festival_wire_ticker() {
	include plugin_dir_path( __FILE__ ) . 'templates/homepage-ticker.php';
}
add_action( 'extrachill_homepage_after_hero', 'display_festival_wire_ticker' );

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



function festival_wire_register_newsletter_integration($integrations) {
	if (!function_exists('extrachill_multisite_subscribe')) {
		return $integrations;
	}

	$integrations['festival_wire_tip'] = array(
		'label' => __('Festival Wire Tip Form', 'extrachill'),
		'description' => __('Newsletter subscription for festival tip submitters', 'extrachill'),
		'list_id_key' => 'festival_wire_list_id',
		'enable_key' => 'enable_festival_wire_tip',
		'plugin' => 'extrachill-news-wire'
	);

	return $integrations;
}
add_filter('newsletter_form_integrations', 'festival_wire_register_newsletter_integration');