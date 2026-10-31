<?php
/**
 * Data Machine integration loader contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/** Verify the runtime integration loader topology. */
class DataMachineIntegrationLoaderTest extends TestCase {
	/** Entity follow notification modules must remain retired. */
	public function test_runtime_loaders_do_not_register_entity_follow_notifications(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.
		$integration_loader = file_get_contents( dirname( __DIR__, 3 ) . '/inc/core/data-machine-events/init.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.
		$plugin_loader = file_get_contents( dirname( __DIR__, 3 ) . '/extrachill-events.php' );

		$this->assertStringNotContainsString( 'festival-notifications.php', $integration_loader );
		$this->assertStringNotContainsString( 'extrachill_events_init_festival_notifications', $integration_loader );
		$this->assertStringNotContainsString( 'venue-update-subscriptions.php', $plugin_loader );
		$this->assertStringNotContainsString( 'extrachill_events_init_venue_update_subscriptions', $plugin_loader );
	}
}
