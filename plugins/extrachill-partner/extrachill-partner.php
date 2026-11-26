<?php
/**
 * Plugin Name: Extra Chill Partner
 * Description: Server-rendered venue events and hosted booking for partner websites.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: extrachill-partner
 * License: GPL-2.0-or-later
 */

namespace ExtraChill\Partner;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/inc/Client.php';
require_once __DIR__ . '/inc/Renderer.php';
require_once __DIR__ . '/inc/Settings.php';

add_action( 'init', function () {
	wp_register_style( 'extrachill-partner', plugins_url( 'assets/style.css', __FILE__ ), array(), '0.1.0' );
	wp_register_script( 'extrachill-partner-editor', plugins_url( 'assets/editor.js', __FILE__ ), array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), '0.1.0', true );
	wp_set_script_translations( 'extrachill-partner-editor', 'extrachill-partner' );
	register_block_type( __DIR__ . '/blocks/upcoming-events' );
	register_block_type( __DIR__ . '/blocks/booking-embed' );
	add_shortcode( 'extrachill_events', function ( $attributes ) {
		return Renderer::events( shortcode_atts( array( 'limit' => 10 ), $attributes ) );
	} );
} );
Settings::register();
