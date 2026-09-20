<?php
/**
 * Locks down the genuinely-absent degraded path `NetworkDispatch` takes
 * before extrachill-network (or its test stub) is ever loaded.
 *
 * This file's name is deliberate: it must sort, and therefore run, before
 * `NetworkAbilityCallTest.php`, `NetworkAbilitySearchTest.php`, and
 * `NetworkDispatchTest.php`, none of which may call
 * `ExtraChillMcp\Tests\Support\NetworkStub::install()` before this file's
 * tests execute — see that class's docblock for why. Once any of those
 * install the stub, the four global functions persist for the rest of the
 * PHP process and this "genuinely absent" assertion would silently start
 * passing against the stub instead of reality.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Network\NetworkDispatch;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group network
 */
class NetworkAbilityAbsenceTest extends WP_UnitTestCase {

	public function test_the_four_network_globals_are_genuinely_absent(): void {
		$this->assertFalse( function_exists( 'ec_get_blog_ids' ) );
		$this->assertFalse( function_exists( 'ec_get_blog_slug_by_id' ) );
		$this->assertFalse( function_exists( 'ec_get_ability_site_affinity' ) );
		$this->assertFalse( function_exists( 'ec_cross_site_rest_request' ) );
	}

	public function test_available_is_false(): void {
		$this->assertFalse( NetworkDispatch::available() );
	}

	public function test_local_site_key_is_null(): void {
		$this->assertNull( NetworkDispatch::local_site_key() );
	}

	public function test_remote_sites_is_empty(): void {
		$this->assertSame( array(), NetworkDispatch::remote_sites() );
	}

	public function test_resolve_owner_degrades_to_local(): void {
		$this->assertNull( NetworkDispatch::resolve_owner( 'extrachill/get-venue' ) );
	}

	public function test_run_ability_reports_network_unavailable(): void {
		$result = NetworkDispatch::run_ability( 'events', 'extrachill/get-venue', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'extrachill_mcp_network_unavailable', $result->get_error_code() );
	}
}
