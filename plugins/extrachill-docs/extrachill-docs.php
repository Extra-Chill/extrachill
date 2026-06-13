<?php
/**
 * Plugin Name: Extra Chill Docs
 * Plugin URI: https://docs.extrachill.com
 * Description: User-facing documentation for the Extra Chill platform.
 * Version: 0.5.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-docs
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Network: false
 *
 * Deployed on docs.extrachill.com (Blog ID 10). Uses ec_doc custom post type
 * with ec_doc_platform taxonomy for clean /platform-slug/doc-slug/ URLs.
 * Homepage displays dynamic platform cards for documentation navigation.
 *
 * @package ExtraChillDocs
 * @since 0.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_DOCS_VERSION', '0.5.0' );
define( 'EXTRACHILL_DOCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_DOCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Post types and taxonomies.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/post-types.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/register-platform-taxonomy.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/taxonomy-seed.php';

// Assets and templates.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/assets.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/breadcrumbs.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/homepage.php';

// Theme integration filters.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/filters.php';

// Sidebar integration.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/sidebar.php';

// Custom rewrite rules for /{platform}/{doc}/ URL structure.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/rewrite-rules.php';

// Docs agent execution mode — registers the `docs` mode with Data Machine
// and provides its editorial guidance (writing rules) for any AI step
// configured with agent_modes: ['docs']. See runner-configs/README.md.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/docs-agent/docs-mode.php';

// Sync infrastructure — upsert-doc-page ability, scheduled cron, WP-CLI
// command, edit lockdown on synced pages. See inc/sync/*.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/abilities/upsert-doc-page.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/sync/synced-page-guard.php';
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/sync/sync-orchestrator.php';

// One-shot ec_doc → page migration. Registers the WP-CLI command
// `wp extrachill docs migrate-ec-docs`. Runs once before #39 removes
// the ec_doc CPT. See inc/migration/.
require_once EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/migration/ec-doc-to-page-migration.php';

register_activation_hook( __FILE__, 'extrachill_docs_activate' );
register_deactivation_hook( __FILE__, 'extrachill_docs_deactivate' );

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

	// Schedule the docs sync cron event. Safe to call repeatedly — no-op if
	// already scheduled. See inc/sync/sync-orchestrator.php.
	extrachill_docs_schedule_sync_cron();

	// Flush rewrite rules for clean URLs.
	flush_rewrite_rules();
}

/**
 * Tear down scheduled events on deactivation.
 *
 * Leaves all CPT / taxonomy / page data intact — deactivation should not
 * destroy content. Only cleans up the recurring cron event so it doesn't
 * fire against a deactivated plugin.
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_deactivate() {
	extrachill_docs_unschedule_sync_cron();
}
