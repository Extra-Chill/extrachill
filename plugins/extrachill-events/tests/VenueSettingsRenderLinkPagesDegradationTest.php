<?php
/**
 * Regression coverage for #899: /venue-settings/ fatalled for every venue
 * team member whenever `ec_link_page_editor_is_available()` was true but
 * `VenueLinkPagesProvider::validate_runtime()` had failed (leaving
 * `\ExtraChillEvents\Core\VenueLinkPages` unloaded), because
 * `blocks/venue-settings/render.php` referenced that class unconditionally.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

final class VenueSettingsRenderLinkPagesDegradationTest extends TestCase {
	/** A failed Link Pages provider must degrade the tab, never fatal the console. */
	public function test_render_degrades_link_page_status_instead_of_fatalling(): void {
		$output  = array();
		$status  = 0;
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/venue-settings-render-degrades-without-link-pages.php' );
		exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated PHP render fixture.
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );

		$this->assertSame( 1, $result['venue_count'] );
		$this->assertSame( 'unavailable', $result['link_page_status'] );
	}
}
