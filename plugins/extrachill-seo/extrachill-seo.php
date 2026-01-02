<?php
/**
 * Extra Chill SEO Plugin
 *
 * Replaces Yoast SEO with a lean, code-first SEO system tailored to the Extra Chill Platform.
 * Manages meta tags, structured data, robots directives, and social sharing across the multisite network.
 *
 * @package ExtraChill\SEO
 * @version 0.5.0
 */

/**
 * Plugin Name: Extra Chill SEO
 * Plugin URI: https://extrachill.com
 * Description: Lean SEO plugin replacing Yoast with code-first meta tags, structured data, and robots directives
 * Version: 0.5.0
 * Author: Extra Chill
 * Author URI: https://extrachill.com
 * Network: true
 * Text Domain: extrachill-seo
 * Domain Path: /languages
 * Requires: 6.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace ExtraChill\SEO;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'EXTRACHILL_SEO_VERSION', '0.5.0' );
define( 'EXTRACHILL_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_SEO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Activation hook - verify multisite requirement
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! is_multisite() ) {
			wp_die(
				'Extra Chill SEO requires WordPress Multisite to be enabled.',
				'Extra Chill SEO Activation Error',
				array( 'response' => 403 )
			);
		}
	}
);

/**
 * Initialize plugin on wp_loaded
 *
 * Load core files that register hooks and filters
 */
add_action(
	'wp_loaded',
	function () {
		require_once EXTRACHILL_SEO_PATH . 'inc/core/settings.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/core/indexnow.php';

		// Load audit components (required for REST API endpoints)
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/audit-storage.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/audit-helpers.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/checks/check-excerpts.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/checks/check-alt-text.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/checks/check-featured.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/checks/check-broken-images.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/checks/check-broken-links.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/audit-runner.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/audit/audit-global.php';

		if ( is_admin() && is_network_admin() ) {
			require_once EXTRACHILL_SEO_PATH . 'inc/admin/network-settings.php';
		}

		// Load core SEO components
		require_once EXTRACHILL_SEO_PATH . 'inc/core/meta-tags.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/core/canonical.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/core/robots.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/core/open-graph.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/core/twitter-cards.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-output.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-website.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-organization.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-article.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-breadcrumb.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-webpage.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-artist.php';
		require_once EXTRACHILL_SEO_PATH . 'inc/schema/schema-link-page.php';
	}
);

/**
 * Load text domain for translations
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain(
			'extrachill-seo',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);
