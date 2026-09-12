<?php
/**
 * MCP server registration via wordpress/mcp-adapter.
 *
 * @package ExtraChillMcp
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Extra Chill MCP server with mcp-adapter.
 *
 * Public endpoint: /wp-json/extrachill-mcp/v1/mcp
 *
 * The server advertises exactly two tools, both owned by the Agents API
 * substrate: `agents/ability-search` for discovery and `agents/ability-call`
 * for dispatch. Every other capability on the network is reached through those
 * two, so the advertised tool count stays at two no matter how large the
 * ability registry grows.
 *
 * This plugin deliberately owns no tool implementations. Domain behavior lives
 * in the feature plugin that registers the ability; this is transport.
 */
final class McpServer {

	/**
	 * Canonical Agents API meta-abilities.
	 */
	private const TOOLS = array(
		'agents/ability-search',
		'agents/ability-call',
	);

	public function register(): void {
		add_action( 'mcp_adapter_init', array( $this, 'on_mcp_adapter_init' ) );
	}

	/**
	 * @param \WP\MCP\Core\McpAdapter $adapter
	 */
	public function on_mcp_adapter_init( $adapter ): void {
		/**
		 * Filters the tools advertised on the Extra Chill MCP server.
		 *
		 * Adding entries here re-introduces flat tool growth, which the
		 * search/call indirection exists to avoid. Prefer registering a
		 * WordPress ability — it becomes reachable through `agents/ability-call`
		 * with no change to this server.
		 *
		 * @param string[] $tools Ability names advertised as MCP tools.
		 */
		/** @var mixed $tools */
		$tools = apply_filters( 'extrachill_mcp_tools', self::TOOLS );

		$adapter->create_server(
			'extrachill-mcp',
			'extrachill-mcp/v1',
			'mcp',
			__( 'Extra Chill', 'extrachill-mcp' ),
			__( 'Search and invoke Extra Chill platform abilities — editorial, artists, events, venues and bookings, community, shop, and newsletter. Start with ability-search to discover what is available, then ability-call to run it. Every call is authorized against the connected account.', 'extrachill-mcp' ),
			EXTRACHILL_MCP_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			// The docblock above documents the contract a well-behaved filter
			// should honor; it is not a guarantee. array_values() throws a
			// TypeError on a non-array, so fall back to the canonical tool list
			// rather than letting an arbitrary filter fatal a public endpoint.
			is_array( $tools ) ? array_values( $tools ) : self::TOOLS,
			array(), // resources
			array()  // prompts
		);
	}
}
