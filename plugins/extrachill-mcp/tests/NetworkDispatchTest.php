<?php
/**
 * Tests for NetworkDispatch, the sole adapter over extrachill-network's
 * ownership index and cross-site dispatcher, through `NetworkStub`.
 *
 * `NetworkAbilityAbsenceTest` must run before this file and already covers
 * the genuinely-absent path; the tests below cover the same methods once
 * the stub is installed.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Network\NetworkDispatch;
use ExtraChillMcp\Tests\Support\NetworkStub;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group network
 */
class NetworkDispatchTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! NetworkStub::install() ) {
			$this->markTestSkipped( 'A real extrachill-network registry owns these globals; the stub must not shadow it.' );
		}

		NetworkStub::reset();
	}

	public function tear_down(): void {
		NetworkStub::reset();
		parent::tear_down();
	}

	public function test_available_is_true_once_the_stub_installs(): void {
		$this->assertTrue( NetworkDispatch::available() );
	}

	public function test_local_site_key_resolves_the_current_blog(): void {
		// NetworkStub maps blog id 1 to 'main'; a single-site test run's
		// get_current_blog_id() is 1.
		$this->assertSame( 'main', NetworkDispatch::local_site_key() );
	}

	public function test_remote_sites_excludes_the_current_blog(): void {
		$this->assertSame( array( 'events' ), NetworkDispatch::remote_sites() );
	}

	public function test_resolve_owner_returns_null_when_the_affinity_handler_says_local(): void {
		NetworkStub::$affinity_handler = static function ( string $ability_name ) {
			return null;
		};

		$this->assertNull( NetworkDispatch::resolve_owner( 'extrachill/get-venue' ) );
	}

	public function test_resolve_owner_returns_the_owning_site_key(): void {
		NetworkStub::$affinity_handler = static function ( string $ability_name ) {
			return 'extrachill/get-venue' === $ability_name ? 'events' : null;
		};

		$this->assertSame( 'events', NetworkDispatch::resolve_owner( 'extrachill/get-venue' ) );
	}

	public function test_run_ability_posts_to_the_targets_run_route_by_default(): void {
		$captured = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$captured ) {
			$captured[] = array( $site_key, $method, $path, $args );

			return array( 'ok' => true );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/get-venue', array( 'venue_id' => 4 ) );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'events', $captured[0][0] );
		$this->assertSame( 'POST', $captured[0][1] );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/get-venue/run', $captured[0][2] );
		$this->assertSame( array( 'body' => array( 'input' => array( 'venue_id' => 4 ) ) ), $captured[0][3] );
	}

	public function test_run_ability_retries_with_get_when_the_route_rejects_post(): void {
		$attempts = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$attempts ) {
			$attempts[] = $method;

			if ( 'POST' === $method ) {
				return new WP_Error( 'rest_ability_invalid_method', 'wrong method' );
			}

			return array( 'ok' => true );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/get-venue', array() );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( array( 'POST', 'GET' ), $attempts );
	}

	public function test_run_ability_does_not_retry_on_a_different_error(): void {
		$attempts = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$attempts ) {
			$attempts[] = $method;

			return new WP_Error( 'http_request_failed', 'nope' );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/get-venue', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertSame( array( 'POST' ), $attempts );
	}
}
