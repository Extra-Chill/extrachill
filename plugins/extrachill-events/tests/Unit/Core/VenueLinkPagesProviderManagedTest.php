<?php
/**
 * Managed WordPress coverage for the optional Link Pages runtime boundary.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

use ExtraChillEvents\Providers\VenueLinkPagesProvider;

/** Verifies missing optional runtime states fail soft without partial registration. */
final class VenueLinkPagesProviderManagedTest extends WP_UnitTestCase {

	/** @var array<int,string> */
	private $active_plugins;

	/** @var array<string,int> */
	private $network_plugins;

	protected function setUp(): void {
		parent::setUp();
		$this->active_plugins  = (array) get_option( 'active_plugins', array() );
		$this->network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $GLOBALS['extrachill_events_venue_link_pages_error'] );
		$this->set_runtime_active( false );
	}

	protected function tearDown(): void {
		update_option( 'active_plugins', $this->active_plugins );
		update_site_option( 'active_sitewide_plugins', $this->network_plugins );
		unset( $GLOBALS['extrachill_events_venue_link_pages_error'] );
		parent::tearDown();
	}

	/** An absent or installed-but-inactive runtime remains an observable soft failure. */
	public function test_absent_and_inactive_runtime_fail_soft(): void {
		foreach ( array( 'absent', 'inactive' ) as $state ) {
			$result = VenueLinkPagesProvider::validate_runtime();
			$this->assertWPError( $result, $state );
			$this->assertSame( 'venue_link_pages_runtime_not_configured', $result->get_error_code(), $state );
		}
	}

	/** Configured activation without the complete API-v3 symbols fails before registration. */
	public function test_configured_incomplete_runtime_registers_nothing(): void {
		$this->set_runtime_active( true );
		$result = VenueLinkPagesProvider::initialize();

		$this->assertWPError( $result );
		$this->assertSame( 'venue_link_pages_runtime_incomplete', $result->get_error_code() );
		$this->assertSame( $result, $GLOBALS['extrachill_events_venue_link_pages_error'] );
		$this->assertFalse( class_exists( '\\ExtraChillEvents\\Core\\VenueLinkPages', false ) );
		foreach ( array( 'provision-venue-link-page', 'get-venue-link-page', 'save-venue-link-page-links', 'save-venue-link-page-styles', 'save-venue-link-page-settings', 'refresh-venue-link-page-snapshot', 'get-venue-link-page-analytics' ) as $ability ) {
			$this->assertFalse( wp_has_ability( 'extrachill/' . $ability ) );
		}
	}

	/** Toggle only the configured activation signal; never load standalone code. */
	private function set_runtime_active( bool $active ): void {
		$plugins = array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( 'extrachill-link-pages/extrachill-link-pages.php' ) ) );
		if ( $active ) {
			$plugins[] = 'extrachill-link-pages/extrachill-link-pages.php';
		}
		update_option( 'active_plugins', $plugins );
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $network['extrachill-link-pages/extrachill-link-pages.php'] );
		update_site_option( 'active_sitewide_plugins', $network );
	}
}
