<?php
/**
 * Main plugin bootstrap.
 *
 * @package ExtraChillMcp
 */

declare( strict_types = 1 );

namespace ExtraChillMcp;

use ExtraChillMcp\Abilities\CategoryRegistration;
use ExtraChillMcp\Abilities\NetworkAbilityCall;
use ExtraChillMcp\Abilities\NetworkAbilitySearch;
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

		// Host decision: who may reach the network meta-abilities, and (via
		// the substrate permission filters it also registers) the local hop
		// each of them makes into the Agents API substrate abilities.
		$access = new Access();
		$access->register();

		// Domain: the two network-wide meta-abilities themselves. Registered
		// as real WordPress abilities (not server-specific tool code) so
		// they are reachable through REST, WP-CLI, and any other ability
		// consumer, not just this MCP server.
		( new CategoryRegistration() )->register();
		( new NetworkAbilitySearch( $access ) )->register();
		( new NetworkAbilityCall( $access ) )->register();

		// Transport: advertise those meta-abilities as an MCP server.
		( new McpServer() )->register();
	}
}
