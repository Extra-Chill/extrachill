<?php
/**
 * Plugin Name: Extra Chill Content Blocks
 * Plugin URI: https://extrachill.com
 * Description: Reusable content creation Gutenberg blocks for the Extra Chill platform. Provides editorial, interactive, and AI-powered blocks for any site that activates the plugin.
 * Version: 1.1.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Chris Huber
 * Author URI: https://extrachill.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-content-blocks
 * Domain Path: /languages
 * Network: false
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_CONTENT_BLOCKS_VERSION', '1.1.2' );
define( 'EXTRACHILL_CONTENT_BLOCKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_CONTENT_BLOCKS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load block business logic (PHP functions called by REST API routes).
 *
 * These index.php files contain the server-side logic for interactive blocks
 * (name generators, image voting). They are not auto-loaded by register_block_type()
 * since block.json only references render.php, JS, and CSS.
 */
function extrachill_content_blocks_load_business_logic() {
	$blocks_dir = file_exists( __DIR__ . '/build/blocks' ) ? 'build/blocks' : 'src/blocks';
	$base       = __DIR__ . '/' . $blocks_dir;

	$logic_files = array(
		'/band-name-generator/index.php',
		'/rapper-name-generator/index.php',
		'/image-voting/index.php',
	);

	foreach ( $logic_files as $file ) {
		if ( file_exists( $base . $file ) ) {
			require_once $base . $file;
		}
	}
}
extrachill_content_blocks_load_business_logic();

/**
 * Register the shared content blocks.
 *
 * In development: registers from src/blocks/
 * In production: registers from build/blocks/ (created by build process)
 */
function extrachill_content_blocks_register_blocks() {
	$blocks_dir = file_exists( __DIR__ . '/build/blocks' ) ? 'build/blocks' : 'src/blocks';

	register_block_type( __DIR__ . '/' . $blocks_dir . '/trivia' );
	register_block_type( __DIR__ . '/' . $blocks_dir . '/image-voting' );
	register_block_type( __DIR__ . '/' . $blocks_dir . '/band-name-generator' );
	register_block_type( __DIR__ . '/' . $blocks_dir . '/rapper-name-generator' );
	register_block_type( __DIR__ . '/' . $blocks_dir . '/ai-adventure' );
	register_block_type( __DIR__ . '/' . $blocks_dir . '/ai-adventure-path' );
	register_block_type( __DIR__ . '/' . $blocks_dir . '/ai-adventure-step' );
}
add_action( 'init', 'extrachill_content_blocks_register_blocks' );

/**
 * Register newsletter integration for image voting blocks.
 *
 * Admin configures Sendy list ID via Extra Chill Newsletter settings.
 *
 * @param array<string, array<string, string>> $integrations Registered integrations.
 * @return array<string, array<string, string>>
 */
function extrachill_content_blocks_register_newsletter_integration( $integrations ) {
	$integrations['image_voting'] = array(
		'label'       => __( 'Image Voting Block', 'extrachill-content-blocks' ),
		'description' => __( 'Newsletter subscription when users vote on images', 'extrachill-content-blocks' ),
		'list_id_key' => 'image_voting_list_id',
		'enable_key'  => 'enable_image_voting',
		'plugin'      => 'extrachill-content-blocks',
	);

	return $integrations;
}
add_filter( 'newsletter_form_integrations', 'extrachill_content_blocks_register_newsletter_integration' );
