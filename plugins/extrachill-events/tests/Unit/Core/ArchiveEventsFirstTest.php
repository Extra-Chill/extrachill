<?php
/**
 * Events-first archive layout contract (#847).
 *
 * The location archive must show its events first: the calendar block renders
 * immediately after the archive header, and every secondary furniture piece
 * (map, merged scene prompt, venue workspace disclosure) registers on the
 * below-calendar hook instead of stacking above the listings. Source-level
 * assertions keep the ordering structural, not incidental.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/archive-layout-stubs.php';

if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_URL' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_URL', 'https://events.example/wp-content/plugins/extrachill-events/' );
}
if ( ! defined( 'EXTRACHILL_EVENTS_VERSION' ) ) {
	define( 'EXTRACHILL_EVENTS_VERSION', 'tests' );
}

require_once dirname( __DIR__, 3 ) . '/inc/core/archive-map.php';

/**
 * Verifies the events-first archive ordering and the archive map attribute contract.
 */
final class ArchiveEventsFirstTest extends TestCase {
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_is_tax'],
			$GLOBALS['test_enqueued_scripts']
		);
		parent::tearDown();
	}

	/** The below-calendar hook fires after the calendar block, before venue booking inquiry. */
	public function test_below_calendar_hook_fires_after_calendar_block(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/templates/archive.php' );

		$calendar = strpos( $source, 'wp:data-machine-events/calendar' );
		$hook     = strpos( $source, "do_action( 'extrachill_archive_below_calendar' )" );
		$booking  = strpos( $source, 'wp:extrachill/venue-booking-inquiry' );

		$this->assertNotFalse( $calendar );
		$this->assertNotFalse( $hook );
		$this->assertGreaterThan( $calendar, $hook );
		if ( false !== $booking ) {
			$this->assertGreaterThan( $hook, $booking );
		}

		// The legacy hook stays in place above the calendar for header consumers.
		$this->assertStringContainsString( "do_action( 'extrachill_archive_below_description' )", $source );
	}

	/** Map, merged scene prompt, and workspace action all register below the calendar. */
	public function test_archive_furniture_registers_below_calendar(): void {
		$base = dirname( __DIR__, 3 ) . '/inc/core/';

		foreach ( array( 'location-map.php', 'venue-map.php', 'artist-map.php' ) as $file ) {
			$source = file_get_contents( $base . $file );
			$this->assertStringContainsString( "add_action( 'extrachill_archive_below_calendar', ", $source, $file );
			$this->assertStringNotContainsString( 'extrachill_archive_below_description', $source, $file );
		}

		$cta = file_get_contents( $base . 'account-market.php' );
		$this->assertStringContainsString( "add_action( 'extrachill_archive_below_calendar', 'extrachill_events_render_archive_scene_cta', 10 )", $cta );
		$this->assertStringNotContainsString( 'extrachill_archive_below_description', $cta );

		$workspace = file_get_contents( $base . 'booking-console.php' );
		$this->assertStringContainsString( "add_action( 'extrachill_archive_below_calendar', 'ec_events_render_venue_archive_workspace_action', 30 )", $workspace );
		$this->assertStringNotContainsString( 'extrachill_archive_below_description', $workspace );

		// The standalone digest opt-in no longer renders as its own panel.
		$digest = file_get_contents( $base . 'local-scene-digest.php' );
		$this->assertStringNotContainsString( 'extrachill_archive_below_description', $digest );
		$this->assertStringNotContainsString( 'extrachill_archive_below_calendar', $digest );
		$this->assertStringNotContainsString( 'extrachill_events_render_local_scene_digest_opt_in', $digest );
	}

	/** Archive maps ship collapsed by default with the taller expanded height. */
	public function test_archive_map_attributes_collapse_and_expand(): void {
		$attributes = extrachill_events_archive_map_block_attributes();

		$this->assertTrue( $attributes['collapsible'] );
		$this->assertTrue( $attributes['defaultCollapsed'] );
		$this->assertSame( 560, $attributes['height'] );

		// Caller-specific attributes merge over the common defaults.
		$venue = extrachill_events_archive_map_block_attributes( array( 'zoom' => 14 ) );
		$this->assertSame( 14, $venue['zoom'] );
		$this->assertTrue( $venue['defaultCollapsed'] );
		$this->assertSame( 560, $venue['height'] );
	}

	/** The rendered block string carries the collapse contract. */
	public function test_archive_map_render_contains_collapse_contract(): void {
		$output = extrachill_events_render_archive_map( array( 'chronologicalRouteMode' => true ) );

		$this->assertStringContainsString( 'wp:data-machine-events/events-map', $output );
		$this->assertStringContainsString( '"defaultCollapsed":true', $output );
		$this->assertStringContainsString( '"collapsible":true', $output );
		$this->assertStringContainsString( '"chronologicalRouteMode":true', $output );
	}

	/** The persistence script is enqueued on taxonomy archives only. */
	public function test_archive_map_persistence_script_enqueues_on_map_archives(): void {
		$GLOBALS['test_is_tax'] = false;
		extrachill_events_enqueue_archive_map_assets();
		$this->assertArrayNotHasKey( 'extrachill-events-archive-map', $GLOBALS['test_enqueued_scripts'] ?? array() );

		$GLOBALS['test_is_tax'] = true;
		extrachill_events_enqueue_archive_map_assets();
		$this->assertArrayHasKey( 'extrachill-events-archive-map', $GLOBALS['test_enqueued_scripts'] );
		$this->assertStringContainsString( 'assets/js/archive-map.js', (string) $GLOBALS['test_enqueued_scripts']['extrachill-events-archive-map']['src'] );
	}
}
