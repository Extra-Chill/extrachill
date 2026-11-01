<?php
/**
 * Plugin Name: Extra Chill Docs
 * Plugin URI: https://docs.extrachill.com
 * Description: User-facing documentation for the Extra Chill platform.
 * Version: 0.10.2
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-docs
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Network: false
 *
 * Deployed on docs.extrachill.com (Blog ID 10). Uses hierarchical pages for documentation URLs.
 * Homepage displays dynamic platform cards for documentation navigation.
 *
 * @package ExtraChillDocs
 * @since 0.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_DOCS_VERSION', '0.10.2' );
define( 'EXTRACHILL_DOCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_DOCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Assets and templates.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/assets.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/breadcrumbs.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/homepage.php';

// Theme integration filters.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/filters.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/feedback.php';

// Team-only pages use WordPress core private-page visibility.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/access/team-private-docs.php';

// Sidebar integration.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/sidebar.php';

// Custom rewrite rules for /{platform}/{doc}/ URL structure.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/rewrite-rules.php';

// Monorepo component-to-parent map.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/platform-map.php';

// Docs agent execution mode — registers the `docs` mode with Data Machine
// and provides its editorial guidance (writing rules) for any AI step
// configured with agent_modes: ['docs']. See runner-configs/README.md.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/docs-agent/docs-mode.php';

// Docs write path — the `extrachill-docs/upsert-doc-page` ability that
// converts markdown into hierarchical pages. The ability category must be
// registered before any ability assigns itself to it, so load it first.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/abilities/category.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/abilities/upsert-doc-page.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/abilities/publish-plugin-docs.php';

// One-shot cleanup of the removed repo→docs sync subsystem (leftover cron
// event + per-page sync meta). Runs once after upgrade; see
// inc/core/sync-cleanup.php.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/sync-cleanup.php';

require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/abilities/migrate-source-keys.php';

register_activation_hook( __FILE__, 'extrachill_docs_activate' );

/**
 * Refresh routing on plugin activation.
 *
 * @since 0.2.0
 * @return void
 */
function extrachill_docs_activate() {
	// Flush rewrite rules for clean URLs.
	flush_rewrite_rules();
}
