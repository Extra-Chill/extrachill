<?php
/**
 * Plugin Name: Extra Chill Studio
 * Plugin URI: https://extrachill.com
 * Description: Internal studio workspace for the Extra Chill team with a team-gated block shell for publishing and AI-assisted workflows.
 * Version: 0.2.32
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Requires Plugins: extrachill-users
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-studio
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Network: false
 *
 * @package ExtraChillStudio
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_STUDIO_VERSION', '0.2.32' );
define( 'EXTRACHILL_STUDIO_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_STUDIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_STUDIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_STUDIO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once EXTRACHILL_STUDIO_PLUGIN_DIR . 'inc/assets.php';
require_once EXTRACHILL_STUDIO_PLUGIN_DIR . 'inc/breadcrumbs.php';
require_once EXTRACHILL_STUDIO_PLUGIN_DIR . 'inc/social-drafts.php';
require_once EXTRACHILL_STUDIO_PLUGIN_DIR . 'inc/post-transfer.php';
require_once EXTRACHILL_STUDIO_PLUGIN_DIR . 'inc/publish-router.php';
require_once EXTRACHILL_STUDIO_PLUGIN_DIR . 'inc/compose-editor.php';

register_activation_hook( __FILE__, 'extrachill_studio_activate' );
register_deactivation_hook( __FILE__, 'extrachill_studio_deactivate' );

/**
 * Activation hook.
 *
 * @return void
 */
function extrachill_studio_activate() {
	flush_rewrite_rules();
}

/**
 * Deactivation hook.
 *
 * @return void
 */
function extrachill_studio_deactivate() {
	flush_rewrite_rules();
}

/**
 * Load translations.
 *
 * @return void
 */
function extrachill_studio_load_textdomain() {
	load_plugin_textdomain(
		'extrachill-studio',
		false,
		dirname( EXTRACHILL_STUDIO_PLUGIN_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'extrachill_studio_load_textdomain' );

/**
 * Register Studio block.
 *
 * @return void
 */
function extrachill_studio_register_blocks() {
	$blocks_dir = file_exists( EXTRACHILL_STUDIO_PLUGIN_DIR . 'build/blocks/studio' )
		? 'build/blocks'
		: 'src/blocks';

	register_block_type( EXTRACHILL_STUDIO_PLUGIN_DIR . $blocks_dir . '/studio' );
}
add_action( 'init', 'extrachill_studio_register_blocks' );

/**
 * Render homepage content for studio.extrachill.com.
 *
 * @return void
 */
function extrachill_studio_render_homepage() {
	include EXTRACHILL_STUDIO_PLUGIN_DIR . 'inc/templates/studio-homepage.php';
}
add_action( 'extrachill_homepage_content', 'extrachill_studio_render_homepage' );
