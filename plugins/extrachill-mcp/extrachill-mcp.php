<?php
/**
 * Plugin Name:       Extra Chill MCP
 * Plugin URI:        https://github.com/Extra-Chill/extrachill-mcp
 * Description:       MCP context server for the Extra Chill platform. Exposes the Extra Chill multisite network and GitHub org to MCP clients via the load-provider / execute-tool meta-tool pattern.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Extra Chill
 * Author URI:        https://extrachill.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       extrachill-mcp
 *
 * @package ExtraChillMcp
 */

declare( strict_types = 1 );

namespace ExtraChillMcp;

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_MCP_VERSION', '0.1.0' );
define( 'EXTRACHILL_MCP_FILE', __FILE__ );
define( 'EXTRACHILL_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_MCP_URL', plugin_dir_url( __FILE__ ) );

// Composer autoload (mcp-adapter + any other deps).
$autoload = EXTRACHILL_MCP_DIR . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

/*
 * Suppress mcp-adapter's default server.
 *
 * The adapter ships a default server at /wp-json/mcp/mcp-adapter-default-server
 * exposing three generic tools — discover-abilities, get-ability-info, and
 * execute-ability — over every ability whose effective exposure resolves true
 * (`meta.mcp.public`, falling back to `meta.public`). That is a second,
 * unmediated MCP surface with a generic ability executor on it.
 *
 * Extra Chill's MCP surface is deliberately mediated through the two
 * meta-tools (load-provider / execute-tool) on our own server, so the default
 * server is off. Re-enable at a later priority if you genuinely want it.
 *
 * Registered at file scope, not on `plugins_loaded`: the adapter builds the
 * default server during `McpAdapter::instance()`, which runs inline from the
 * `require_once` below. A filter added inside a `plugins_loaded` callback
 * would land after that decision — and later still if a standalone adapter
 * plugin booted first.
 */
add_filter( 'mcp_adapter_create_default_server', '__return_false' );

// PSR-4 autoload for src/ (fallback if composer not installed during dev).
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = EXTRACHILL_MCP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>Extra Chill MCP</strong> requires the WordPress Abilities API (WP 6.9+).</p></div>';
				}
			);
			return;
		}

		// Boot the composer-bundled mcp-adapter unless a standalone copy is active.
		if ( ! defined( 'WP_MCP_VERSION' ) ) {
			$adapter_main = EXTRACHILL_MCP_DIR . 'vendor/wordpress/mcp-adapter/mcp-adapter.php';
			if ( file_exists( $adapter_main ) ) {
				require_once $adapter_main;
			}
		}

		if ( ! class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>Extra Chill MCP</strong> requires <code>wordpress/mcp-adapter</code>. Run <code>composer install</code> in the plugin directory.</p></div>';
				}
			);
			return;
		}

		// Agents API owns the two meta-abilities this server advertises. Without
		// it there is nothing to expose, so fail loudly rather than serving an
		// MCP endpoint with no tools on it.
		if ( ! defined( 'AGENTS_API_LOADED' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>Extra Chill MCP</strong> requires the <strong>Agents API</strong> plugin, which provides the <code>agents/ability-search</code> and <code>agents/ability-call</code> abilities.</p></div>';
				}
			);
			return;
		}

		Plugin::boot();
	},
	20
);
