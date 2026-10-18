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

	public function test_execute_keeps_the_upstream_error_code_visible_in_a_generic_transport_message(): void {
		NetworkStub::$affinity_handler = static function () {
			return 'events';
		};

		// extrachill-network's HTTP loopback path preserves the upstream code
		// but flattens every message to this generic one.
		NetworkStub::$cross_site_handler = static function () {
			return new WP_Error( 'ability_invalid_input', 'Cross-site request failed', array( 'status' => 400 ) );
		};

		$result = $this->call->execute( array( 'name' => 'extrachill/get-venue' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertStringContainsString( 'ability_invalid_input', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( 'events', $data['site'] );
		$this->assertSame( 400, $data['status'] );
	}

	public function test_execute_calls_a_remote_readonly_ability_with_empty_parameters(): void {
		NetworkStub::$affinity_handler = static function ( string $ability_name ) {
			return 'extrachill/community-list-forums' === $ability_name ? 'events' : null;
		};

		$captured = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$captured ) {
			$captured[] = array( $method, $args );

			if ( 'POST' === $method ) {
				return new WP_Error( 'rest_ability_invalid_method', 'Read-only abilities require GET method.' );
			}

			return array( 'forums' => array() );
		};

		$result = $this->call->execute(
			array(
				'name'       => 'extrachill/community-list-forums',
				'parameters' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'forums' => array() ), $result['result'] );

		// The GET retry must carry an input encoding that survives
		// http_build_query() — an empty array would vanish from the query
		// string and the target would reject the absent input.
		$this->assertSame( 'GET', $captured[1][0] );
		$this->assertSame( array( 'query' => array( 'input' => ',' ) ), $captured[1][1] );
	}

	public function test_execute_without_a_site_returns_the_candidate_list_for_an_ambiguous_owner(): void {
		NetworkStub::$blog_ids = array(
			'main'   => 1,
			'events' => 7,
			'wire'   => 9,
		);

		// Multi-owner, no override: ec_get_ability_site_affinity() answers null.
		NetworkStub::$affinity_handler = static function () {
			return null;
		};

		NetworkStub::$owner_index = array(
			'datamachine/instagram-read' => array(
				'events' => true,
				'wire'   => true,
			),
		);

		$result = $this->call->execute( array( 'name' => 'datamachine/instagram-read' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'extrachill_mcp_ability_call_ambiguous_owner', $result->get_error_code() );
		$this->assertStringContainsString( 'events, wire', $result->get_error_message() );
		$this->assertStringContainsString( 'site', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( array( 'events', 'wire' ), $data['candidate_sites'] );
	}

	public function test_execute_with_an_explicit_site_reaches_a_multi_owner_ability(): void {
		NetworkStub::$blog_ids = array(
			'main'   => 1,
			'events' => 7,
			'wire'   => 9,
		);

		NetworkStub::$affinity_handler = static function () {
			return null;
		};

		NetworkStub::$owner_index = array(
			'datamachine/instagram-read' => array(
				'events' => true,
				'wire'   => true,
			),
		);

		$captured = array();
		$forced   = null;

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$captured, &$forced ) {
			$captured[] = array( $site_key, $method, $path );
			$forced     = apply_filters( 'ec_cross_site_use_http_loopback', false, $site_key, $method, $path, $args );

			return array( 'posts' => array() );
		};

		$result = $this->call->execute(
			array(
				'name'       => 'datamachine/instagram-read',
				'site'       => 'wire',
				'parameters' => array( 'limit' => 3 ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'datamachine/instagram-read', $result['name'] );
		$this->assertSame( 'wire', $result['site'] );
		$this->assertSame( array( 'posts' => array() ), $result['result'] );
		$this->assertSame( 'wire', $captured[0][0] );
		$this->assertTrue( $forced, 'An explicitly-sited call must force the target site\'s own bootstrap over HTTP loopback.' );
	}

	public function test_execute_with_an_explicit_site_validates_against_recorded_owners_and_the_local_key(): void {
		NetworkStub::$blog_ids = array(
			'main'   => 1,
			'events' => 7,
			'wire'   => 9,
		);

		NetworkStub::$owner_index = array(
			'datamachine/instagram-read' => array(
				'events' => true,
				'wire'   => true,
			),
		);

		$reached_transport = false;

		NetworkStub::$cross_site_handler = static function () use ( &$reached_transport ) {
			$reached_transport = true;

			return array();
		};

		$result = $this->call->execute(
			array(
				'name' => 'datamachine/instagram-read',
				'site' => 'events',
			)
		);

		$this->assertIsArray( $result, 'A site among the recorded owners must dispatch.' );

		$result = $this->call->execute(
			array(
				'name' => 'extrachill/community-list-forums',
				'site' => 'events',
			)
		);

		$this->assertIsArray( $result, 'An ability unknown to the index must still dispatch to the chosen site.' );

		$reached_transport = false;
		$result            = $this->call->execute(
			array(
				'name' => 'datamachine/instagram-read',
				'site' => 'main',
			)
		);

		// 'main' is the local site in this stub topology, so the local hop is
		// attempted (and degrades honestly — no substrate in this isolated
		// environment) rather than a remote dispatch.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_not_found', $result->get_error_code() );
		$this->assertFalse( $reached_transport );
	}

	public function test_execute_rejects_an_explicit_remote_site_outside_the_recorded_owners(): void {
		NetworkStub::$blog_ids = array(
			'main'   => 1,
			'events' => 7,
			'wire'   => 9,
		);

		NetworkStub::$owner_index = array(
			'datamachine/instagram-read' => array(
				'wire' => true,
			),
		);

		$result = $this->call->execute(
			array(
				'name' => 'datamachine/instagram-read',
				'site' => 'events',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'extrachill_mcp_ability_call_site_mismatch', $result->get_error_code() );
		$this->assertStringContainsString( 'wire', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( array( 'wire' ), $data['candidate_sites'] );
	}

	public function test_execute_rejects_an_unknown_explicit_site_key(): void {
		$result = $this->call->execute(
			array(
				'name' => 'extrachill/get-venue',
				'site' => 'nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'extrachill_mcp_ability_call_unknown_site', $result->get_error_code() );
		$this->assertStringContainsString( 'events', $result->get_error_message() );
		$this->assertStringContainsString( 'main', $result->get_error_message() );
	}
}
