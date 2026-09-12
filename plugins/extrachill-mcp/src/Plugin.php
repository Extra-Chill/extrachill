<?php
/**
 * Main plugin bootstrap.
 *
 * @package ExtraChillMcp
 */

declare( strict_types = 1 );

namespace ExtraChillMcp;

use ExtraChillMcp\Server\McpServer;

defined( 'ABSPATH' ) || exit;

/**
 * Boot class — single entry point.
 */
final class Plugin {

	private static bool $booted = false;

	/**
	 * Boot the plugin once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Host decision: who may reach the Agents API ability meta-tools.
		( new Access() )->register();

		// Transport: advertise those meta-tools as an MCP server.
		( new McpServer() )->register();
	}
}
