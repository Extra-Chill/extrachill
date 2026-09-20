<?php
/**
 * Tests for `extrachill/ability-call`'s local-vs-remote routing.
 *
 * The local hop reuses the Agents API substrate's own `agents/ability-call`
 * (via `NetworkDispatch::run_local_ability()`), which is not registered in
 * this plugin's isolated test environment. That is not a limitation of
 * these tests — it is the real, honest behavior a network hop guard should
 * degrade into, and it is exactly what proves the local branch was actually
 * attempted rather than silently short-circuited. The remote branch is
 * fully exercised through `NetworkStub`'s cross-site transport.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Abilities\NetworkAbilityCall;
use ExtraChillMcp\Tests\Support\NetworkStub;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group network
 */
class NetworkAbilityCallTest extends WP_UnitTestCase {

	private NetworkAbilityCall $call;

	public function set_up(): void {
		parent::set_up();

		if ( ! NetworkStub::install() ) {
			$this->markTestSkipped( 'A real extrachill-network registry owns these globals; the stub must not shadow it.' );
		}

		NetworkStub::reset();

		$this->call = new NetworkAbilityCall( new \ExtraChillMcp\Access() );
	}

	public function tear_down(): void {
		NetworkStub::reset();
		parent::tear_down();
	}

	public function test_execute_requires_a_name(): void {
		$result = $this->call->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'extrachill_mcp_ability_call_missing_name', $result->get_error_code() );
	}

	public function test_execute_rejects_recursive_self_call(): void {
		$result = $this->call->execute( array( 'name' => 'extrachill/ability-call' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'extrachill_mcp_ability_call_recursion', $result->get_error_code() );
	}

	public function test_execute_attempts_the_local_hop_when_the_owner_is_unresolved(): void {
		NetworkStub::$affinity_handler = static function ( string $ability_name ) {
			return null;
		};

		$result = $this->call->execute( array( 'name' => 'extrachill/get-venue' ) );

		// No agents/ability-call is registered in this isolated test
		// environment, so the local hop genuinely degrades to
		// ability_not_found — proving the local branch, not the remote one,
		// was attempted.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_not_found', $result->get_error_code() );
	}

	public function test_execute_routes_to_the_remote_hop_when_the_owner_resolves(): void {
		NetworkStub::$affinity_handler = static function ( string $ability_name ) {
			return 'extrachill/get-venue' === $ability_name ? 'events' : null;
		};

		$captured = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$captured ) {
			$captured[] = array( $site_key, $method, $path, $args );

			return array( 'venue_id' => 4, 'name' => 'The Royal American' );
		};

		$result = $this->call->execute(
			array(
				'name'       => 'extrachill/get-venue',
				'parameters' => array( 'venue_id' => 4 ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'extrachill/get-venue', $result['name'] );
		$this->assertSame( 'events', $result['site'] );
		$this->assertSame( array( 'venue_id' => 4, 'name' => 'The Royal American' ), $result['result'] );
		$this->assertCount( 1, $captured, 'The remote hop must reach the cross-site transport exactly once.' );
		$this->assertSame( 'events', $captured[0][0] );
	}

	public function test_execute_surfaces_a_remote_transport_error(): void {
		NetworkStub::$affinity_handler = static function () {
			return 'events';
		};

		NetworkStub::$cross_site_handler = static function () {
			return new WP_Error( 'http_request_failed', 'timeout' );
		};

		$result = $this->call->execute( array( 'name' => 'extrachill/get-venue' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}
}
