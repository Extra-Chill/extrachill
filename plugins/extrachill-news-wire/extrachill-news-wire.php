<?php
/**
 * Plugin Name: ExtraChill News Wire
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

/**
 * Define plugin constants
 */
define( 'FESTIVAL_WIRE_INCLUDE_DIR', plugin_dir_path(__FILE__) . 'includes/' );

// Include modularized files
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-post-type.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-ajax.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-query-filters.php';

/**
 * Enqueue Festival Wire assets conditionally
 *
 * Loads CSS and JavaScript only on Festival Wire archive and single pages.
 * Includes AJAX localization for load-more functionality and tip form.
 *
 * @since 1.0.0
 */
function enqueue_festival_wire_assets() {
	global $wp_query; // Make sure global $wp_query is available

	// Archive pages: Load CSS, JS, and AJAX functionality
	if ( is_post_type_archive( 'festival_wire' ) ) {

		// Enqueue badge colors CSS first
		$badge_colors_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $badge_colors_path ) ) {
			wp_enqueue_style(
				'badge-colors',
				plugin_dir_url(__FILE__) . 'assets/festival-wire.css',
				array(),
				filemtime( $badge_colors_path )
			);
		}

		// Main Festival Wire CSS
		$css_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		$css_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'extrachill-festival-wire',
				$css_file_uri,
				array( 'badge-colors' ), // Make it dependent on badge-colors
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
				array( 'jquery' ), // Add dependencies if any
				filemtime( $js_file_path ),
				true // Load in footer
			);

			// AJAX localization for load-more and tip submission
			$localize_params = array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'tip_nonce'       => wp_create_nonce( 'festival_wire_tip_nonce' ),
				'load_more_nonce' => wp_create_nonce( 'festival_wire_load_more_nonce' ),
				'query_vars'      => json_encode( $wp_query->query_vars ),
				'max_pages'       => $wp_query->max_num_pages
			);

			// Localize script for AJAX functionality
			wp_localize_script(
				'extrachill-festival-wire',
				'festivalWireParams',
				$localize_params
			);
		}
	} elseif ( is_singular( 'festival_wire' ) ) {
		// Single pages: Load CSS, JS, and tip form functionality
		// Enqueue badge colors CSS first
		$badge_colors_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $badge_colors_path ) ) {
			wp_enqueue_style(
				'badge-colors',
				plugin_dir_url(__FILE__) . 'assets/festival-wire.css',
				array(),
				filemtime( $badge_colors_path )
			);
		}
		
		// Main Festival Wire CSS for single pages
		$css_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		$css_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'extrachill-festival-wire',
				$css_file_uri,
				array( 'badge-colors' ), // Make it dependent on badge-colors
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

/**
 * Display Festival Wire ticker on homepage
 *
 * Hooks into theme's homepage after-hero location to display Festival Wire ticker.
 * Uses plugin template for consistent rendering and data management.
 *
 * @since 1.0.0
 */
function display_festival_wire_ticker() {
	// Load ticker template from plugin
	include plugin_dir_path( __FILE__ ) . 'templates/homepage-ticker.php';
}
add_action( 'extrachill_homepage_after_hero', 'display_festival_wire_ticker' );

/**
 * Template loader for Festival Wire post type
 *
 * Overrides WordPress template hierarchy to use plugin templates
 * for Festival Wire archive and single pages. Ensures consistent
 * display regardless of active theme.
 *
 * @since 1.0.0
 * @param string $template Current template path
 * @return string Modified template path
 */
function festival_wire_template_loader( $template ) {
	// Override templates for Festival Wire post type
	if ( is_post_type_archive( 'festival_wire' ) ) {
		$plugin_template = locate_festival_wire_template( 'archive-festival_wire.php' );
		if ( $plugin_template ) {
			return $plugin_template;
		}
	} elseif ( is_singular( 'festival_wire' ) ) {
		$plugin_template = locate_festival_wire_template( 'single-festival_wire.php' );
		if ( $plugin_template ) {
			return $plugin_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'festival_wire_template_loader' );

/**
 * Locate Festival Wire template file
 *
 * Searches for template files in the plugin's templates directory.
 * Used by template loader to override theme templates.
 *
 * @since 1.0.0
 * @param string $template_name Template filename to locate
 * @return string|false Path to template file, or false if not found
 */
function locate_festival_wire_template( $template_name ) {
	$plugin_template_path = plugin_dir_path( __FILE__ ) . 'templates/' . $template_name;

	if ( file_exists( $plugin_template_path ) ) {
		return $plugin_template_path;
	}

	return false;
}



/**
 * Register Festival Wire newsletter integration
 *
 * Registers Festival Wire tip form with the Newsletter plugin's integration system.
 * Only registers if Newsletter plugin is active.
 *
 * @since 2.0.0
 * @param array $integrations Existing registered integrations
 * @return array Updated integrations with Festival Wire integration
 */
function festival_wire_register_newsletter_integration($integrations) {
	// Only register if Newsletter plugin functions are available
	if (!function_exists('subscribe_via_integration')) {
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

/**
 * Plugin Architecture
 *
 * Core functionality is modularized across separate include files:
 *
 * - festival-wire-post-type.php: Custom post type and taxonomy registration
 * - festival-wire-ajax.php: AJAX handlers for load-more and tip submission
 * - festival-wire-query-filters.php: Query modifications and custom variables
 *
 * Templates are located in /templates/ directory and override theme templates.
 * Assets are enqueued conditionally based on post type context.
 */