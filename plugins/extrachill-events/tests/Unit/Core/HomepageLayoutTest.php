<?php
/**
 * Homepage composition regression tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Protects the homepage's component ordering contract.
 */
final class HomepageLayoutTest extends TestCase {
	/**
	 * Calendar totals belong between the location router and feature cards.
	 */
	public function test_calendar_stats_render_between_router_and_feature_cards(): void {
		$plugin_root = dirname( __DIR__, 3 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.
		$actions = file_get_contents( $plugin_root . '/inc/home/actions.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.
		$template = file_get_contents( $plugin_root . '/inc/templates/homepage.php' );

		$this->assertIsString( $actions );
		$this->assertIsString( $template );
		$this->assertStringNotContainsString( 'extrachill_events_render_calendar_stats();', $template );
		$this->assertMatchesRegularExpression(
			"/extrachill_events_location_badges', 10.*extrachill_events_render_calendar_stats\(\).*extrachill_events_home_calendar_stats', 15.*extrachill_events_home_feature_cards', 20/s",
			$actions
		);
	}
}
