<?php
/**
 * Plugin Name: Extra Chill Analytics
 * Description: Network-wide analytics tracking and reporting for the Extra Chill Platform.
 * Version: 0.28.5
 * Author: Chris Huber
 * Network: true
 * Text Domain: extrachill-analytics
 *
 * @package ExtraChill\Analytics
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_ANALYTICS_VERSION', '0.28.5' );
define( 'EXTRACHILL_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_ANALYTICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Database table management.
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/database/events-db.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/database/php-error-log-db.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/database/mediavine-revenue-db.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/database/class-extrachill-analytics-revenue-store.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/database/link-page-analytics-db.php';

// Core functionality (network-wide).
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/event-types.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/events.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/security-classifier.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/visitor-classifier.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/search-source-classifier.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/referrer-host-classifier.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/content-format-classifier.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/outbound-classifier.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/mediavine-csv-import.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/revenue-content-attribution.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/isbot-backfill.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/view-counts.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/link-page-analytics.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/assets.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/gtm.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/reports-permission.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-analytics-summary.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-analytics-meta.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-404-summary.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-404-top-urls.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-404-patterns.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/drill-404-category.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/list-404-events.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/purge-404-events.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-404-top-ips.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-attack-summary.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-search-gaps.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/track-page-view.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-link-page-analytics.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-bridge-ctr.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-outbound-clicks.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/retention-cohorts.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-retention-stats.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-surface-growth.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-demand-drill.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-conversion-map.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-crosslink-targets.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/revenue-ad-policy.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-surface-stickiness.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-activation-funnel.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-content-revenue.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-content-revenue-pages.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-content-revenue-diagnostics.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/ingest-revenue.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-bot-filter-impact.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/get-php-error-summary.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities/check-fatal-alarm.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/404-categorizer.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/404-tracking.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/email-tracking.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/php-error-log.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/php-fatal-alarm.php';

// Admin functionality.
if ( is_admin() ) {
	require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/admin/network-menu.php';
	require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/admin/network-tracking-settings.php';
}
