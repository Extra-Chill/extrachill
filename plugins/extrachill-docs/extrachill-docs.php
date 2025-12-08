<?php
/**
 * Plugin Name: Extra Chill Docs
 * Plugin URI: https://docs.extrachill.com
 * Description: User-facing documentation for the Extra Chill platform.
 * Version: 0.2.1
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-docs
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 *
 * Deployed on docs.extrachill.com (Blog ID 10). Uses ec_doc custom post type
 * with ec_doc_platform taxonomy for clean /platform-slug/doc-slug/ URLs.
 * Homepage displays dynamic platform cards for documentation navigation.
 *
 * @package ExtraChillDocs
 * @since 0.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_DOCS_VERSION', '0.2.1' );
define( 'EXTRACHILL_DOCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_DOCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Post types and taxonomies.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/post-types.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/taxonomies.php';

// Assets and templates.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/assets.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/home/homepage-cards.php';

register_activation_hook( __FILE__, 'extrachill_docs_activate' );

/**
 * Seeds default platform terms on plugin activation.
 *
 * @since 0.2.0
 * @return void
 */
function extrachill_docs_activate() {
	// Register post type and taxonomy first.
	extrachill_docs_register_post_type();
	extrachill_docs_seed_platforms();

	// Flush rewrite rules for clean URLs.
	flush_rewrite_rules();
}
