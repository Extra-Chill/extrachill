<?php
/**
 * Festival Wire CPT and related functionality.
 * Main hub file including modularized components and asset enqueuing.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define path to the current directory for includes
define( 'FESTIVAL_WIRE_INCLUDE_DIR', get_stylesheet_directory() . '/festival-wire/' );

// Include modularized files
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-post-type.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-ajax.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-query-filters.php';

/**
 * Enqueue scripts and styles for Festival Wire pages.
 * Kept in the main file as requested.
 */
function enqueue_festival_wire_assets() {
	global $wp_query; // Make sure global $wp_query is available

	// Only enqueue on festival_wire CPT archive pages.
	if ( is_post_type_archive( 'festival_wire' ) ) {

		// Enqueue CSS
		$css_file_path = get_stylesheet_directory() . '/css/festival-wire.css';
		$css_file_uri  = get_stylesheet_directory_uri() . '/css/festival-wire.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'colormag-pro-festival-wire',
				$css_file_uri,
				array(), // Add theme main style as dependency if needed: array('colormag_style')
				filemtime( $css_file_path )
			);
		}

		// Enqueue JS
		$js_file_path = get_stylesheet_directory() . '/js/festival-wire.js';
		$js_file_uri  = get_stylesheet_directory_uri() . '/js/festival-wire.js';
		if ( file_exists( $js_file_path ) ) {
			wp_enqueue_script(
				'colormag-pro-festival-wire',
				$js_file_uri,
				array( 'jquery' ), // Add dependencies if any
				filemtime( $js_file_path ),
				true // Load in footer
			);

			// Prepare data for localization
			// Note: Ensure $wp_query is the main query for the archive page here.
			$localize_params = array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'tip_nonce'       => wp_create_nonce( 'festival_wire_tip_nonce' ), // Nonce for the tip form
				'load_more_nonce' => wp_create_nonce( 'festival_wire_load_more_nonce' ), // Nonce for load more
				'query_vars'      => json_encode( $wp_query->query_vars ), // Pass current query variables
				'max_pages'       => $wp_query->max_num_pages // Pass max pages
			);

			// Add localized script data for AJAX
			wp_localize_script(
				'colormag-pro-festival-wire',
				'festivalWireParams',
				$localize_params
			);
		}
	} elseif ( is_singular( 'festival_wire' ) ) {
		// Enqueue CSS on single pages
		$css_file_path = get_stylesheet_directory() . '/css/festival-wire.css';
		$css_file_uri  = get_stylesheet_directory_uri() . '/css/festival-wire.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'colormag-pro-festival-wire',
				$css_file_uri,
				array(),
				filemtime( $css_file_path )
			);
		}
		// Enqueue JS on single pages
		$js_file_path = get_stylesheet_directory() . '/js/festival-wire.js';
		$js_file_uri  = get_stylesheet_directory_uri() . '/js/festival-wire.js';
		if ( file_exists( $js_file_path ) ) {
			wp_enqueue_script(
				'colormag-pro-festival-wire',
				$js_file_uri,
				array( 'jquery' ),
				filemtime( $js_file_path ),
				true
			);

			// Localize script for AJAX
			$localize_params = array(
				'ajaxurl'   => admin_url( 'admin-ajax.php' ),
				'tip_nonce' => wp_create_nonce( 'festival_wire_tip_nonce' ),
			);
			wp_localize_script(
				'colormag-pro-festival-wire',
				'festivalWireParams',
				$localize_params
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'enqueue_festival_wire_assets' );

// ... existing code ... 
// The following functions and their hooks have been moved to separate files:
// register_festival_wire_cpt() -> festival-wire-post-type.php
// add_location_to_festival_wire() -> festival-wire-post-type.php
// festival_wire_load_more_handler() -> festival-wire-ajax.php
// process_festival_wire_tip_submission() -> festival-wire-ajax.php
// verify_turnstile_response() -> festival-wire-ajax.php
// festival_wire_add_query_vars() -> festival-wire-query-filters.php
// festival_wire_modify_query() -> festival-wire-query-filters.php

