<?php
/**
 * Plugin Name: Extra Chill Cache
 * Plugin URI: https://extrachill.com
 * Description: Lean, network-activated full-page HTML cache for the Extra Chill platform. Replaces Breeze with only the two features the platform actually uses — anonymous full-page cache and content-change purge — and fixes the Breeze logged-in-cookie serve bug by design.
 * Version: 0.1.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Network: true
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: extrachill-cache
 *
 * @package ExtraChillCache
 *
 * This plugin adopts ONLY the caching behavior the Extra Chill platform used
 * from Breeze (audit confirmed a single meaningful enabled feature: the
 * full-page HTML cache, plus its purge/invalidation). Everything else Breeze
 * shipped (gzip, browser-cache headers, minification, CDN integration, dead
 * Varnish purge, lazy-load, store-fonts/GA-locally) is deliberately dropped —
 * it is handled by nginx / Cloudflare / WP core, or was never enabled. See
 * README.md for the full adopted-vs-dropped audit.
 *
 * Breeze source referenced while building this plugin (read-only reference on
 * the production server at wp-content/plugins/breeze/):
 *   - inc/cache/execute-cache.php        (serve gate + buffer capture)
 *   - inc/cache/purge-cache.php          (purge hooks + flush)
 *   - inc/cache/class-purge-post-cache.php (per-post purge)
 *   - inc/functions.php                  (cache base path, role cookie logic)
 *   - wp-content/advanced-cache.php      (generated drop-in entry point)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_CACHE_VERSION', '0.1.0' );
define( 'EXTRACHILL_CACHE_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_CACHE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_CACHE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Base directory where cached page payloads live.
 *
 * Modeled on Breeze's `breeze_get_cache_base_path()` (inc/functions.php:33),
 * which stored cache under wp-content/cache/breeze/{blog_id}/. We use a
 * dedicated, clearly-named directory so this plugin never collides with a
 * leftover Breeze cache tree during/after cutover.
 */
if ( ! defined( 'EXTRACHILL_CACHE_DIR' ) ) {
	define( 'EXTRACHILL_CACHE_DIR', rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/extrachill-cache' );
}

/**
 * Cache TTL in seconds. Breeze defaulted to 1440 minutes (24h); we match it.
 */
if ( ! defined( 'EXTRACHILL_CACHE_TTL' ) ) {
	define( 'EXTRACHILL_CACHE_TTL', DAY_IN_SECONDS );
}

// Cache generation/store helpers — shared by the serve gate (drop-in layer)
// and the buffer-capture callback (WP layer). Loaded unconditionally so both
// entry points can reach them.
require_once EXTRACHILL_CACHE_PLUGIN_DIR . 'inc/cache-store.php';

// Buffer capture: starts output buffering on cacheable anonymous front-end
// requests and writes the payload to disk on shutdown. Runs inside WordPress.
require_once EXTRACHILL_CACHE_PLUGIN_DIR . 'inc/page-cache.php';

// Purge/invalidation: hooks content-change actions and clears the right blog's
// cache. Modeled on Breeze's purge-cache.php hook set.
require_once EXTRACHILL_CACHE_PLUGIN_DIR . 'inc/purge.php';

// Drop-in installer: writes/removes the advanced-cache.php drop-in that owns
// the early serve gate. Only wired to activation/deactivation hooks.
require_once EXTRACHILL_CACHE_PLUGIN_DIR . 'inc/dropin-installer.php';

register_activation_hook( __FILE__, 'extrachill_cache_activate' );
register_deactivation_hook( __FILE__, 'extrachill_cache_deactivate' );

/**
 * Activation: install the advanced-cache.php drop-in so the early serve gate
 * takes effect. Modeled on how Breeze writes its own advanced-cache.php.
 *
 * NOTE: In the initial PR this plugin is NOT activated on production. Activation
 * (and the drop-in write) is part of the owner-controlled cutover documented in
 * README.md.
 */
function extrachill_cache_activate() {
	extrachill_cache_install_dropin();
}

/**
 * Deactivation: remove our drop-in so no stale serve gate remains.
 */
function extrachill_cache_deactivate() {
	extrachill_cache_remove_dropin();
	// Clear all cached payloads on deactivation to avoid serving stale content
	// from a leftover drop-in of any origin.
	extrachill_cache_purge_all();
}
