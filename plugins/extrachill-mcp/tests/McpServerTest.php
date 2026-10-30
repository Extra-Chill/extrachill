<?php
/**
 * Tests for the Extra Chill MCP server registration.
 *
 * McpServer::on_mcp_adapter_init() advertises the tool list to mcp-adapter.
 * The regression tests below exist because a prior PHPStan pass deleted the
 * `is_array()` guard on the `extrachill_mcp_tools` filter's return value, on
 * the theory that the filter's own docblock (`@param string[] $tools`) makes
 * the type check redundant. A docblock documents the contract a well-behaved
 * filter should honor; it does not enforce it. Any third-party plugin can
 * hook `extrachill_mcp_tools` and return anything, and `array_values()`
 * throws a TypeError on a non-array. Without the guard, a misbehaving
 * filter fatals server registration instead of falling back to the
 * canonical tool list.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Minimal stand-in for \WP\MCP\Core\McpAdapter.
 *
 * McpServer::on_mcp_adapter_init() takes an untyped `$adapter` parameter and
 * calls `create_server()` on it, so a duck-typed stub is sufficient to
 * capture the arguments without booting the real mcp-adapter server.
 */
class ExtraChillMcp_Fake_Mcp_Adapter {

	/** @var array<int, mixed>|null */
	public $captured_args = null;

	/**
	 * @param mixed ...$args
	 */
	public function create_server( ...$args ): void {
		$this->captured_args = $args;
	}
}

/**
 * @group extrachill-mcp
 * @group mcp-server
 */
class McpServerTest extends WP_UnitTestCase {

	/**
	 * Positional index of the `$tools` argument in create_server()'s
	 * signature: id, namespace, route, name, description, version,
	 * transports, error handler, observability handler, tools, resources, prompts.
	 */
	private const TOOLS_ARG_INDEX = 9;

	private \ExtraChillMcp\Server\McpServer $server;

	public function set_up(): void {
		parent::set_up();
		$this->server = new \ExtraChillMcp\Server\McpServer();
	}

	public function tear_down(): void {
		remove_all_filters( 'extrachill_mcp_tools' );
		parent::tear_down();
	}

	public function test_advertises_exactly_the_two_network_meta_abilities_by_default(): void {
		$adapter = new ExtraChillMcp_Fake_Mcp_Adapter();

		$this->server->on_mcp_adapter_init( $adapter );

		$this->assertIsArray( $adapter->captured_args );
		$this->assertSame(
			array( 'extrachill/ability-search', 'extrachill/ability-call' ),
			$adapter->captured_args[ self::TOOLS_ARG_INDEX ]
		);
	}

	public function test_extrachill_mcp_tools_filter_is_honoured(): void {
		add_filter(
			'extrachill_mcp_tools',
			static function () {
				return array( 'custom/tool-one', 'custom/tool-two' );
			}
		);

		$adapter = new ExtraChillMcp_Fake_Mcp_Adapter();
		$this->server->on_mcp_adapter_init( $adapter );

		$this->assertSame(
			array( 'custom/tool-one', 'custom/tool-two' ),
			$adapter->captured_args[ self::TOOLS_ARG_INDEX ]
		);
	}

	/**
	 * @dataProvider malformed_tools_filter_provider
	 *
	 * @param mixed $malformed_tools Value a misbehaving filter might return.
	 */
	public function test_malformed_tools_filter_falls_back_to_canonical_list_without_throwing( $malformed_tools ): void {
		add_filter(
			'extrachill_mcp_tools',
			static function () use ( $malformed_tools ) {
				return $malformed_tools;
			}
		);

		$adapter = new ExtraChillMcp_Fake_Mcp_Adapter();
		$this->server->on_mcp_adapter_init( $adapter );

		$this->assertSame(
			array( 'extrachill/ability-search', 'extrachill/ability-call' ),
			$adapter->captured_args[ self::TOOLS_ARG_INDEX ]
		);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function malformed_tools_filter_provider(): array {
		return array(
			'null'   => array( null ),
			'string' => array( 'not-an-array' ),
			'false'  => array( false ),
		);
	}
}
