<?php
/**
 * Data Machine Events configuration tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( string $site ): int {
		return 'events' === $site ? 7 : 0;
	}
}

if ( ! function_exists( 'ec_get_network_bot_user_id' ) ) {
	function ec_get_network_bot_user_id(): int {
		return 42;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/data-machine-events/configuration.php';

final class DataMachineEventsConfigurationTest extends TestCase {
	public function test_configures_canonical_events_site(): void {
		$this->assertSame( 7, apply_filters( 'data_machine_events_events_blog_id', 1 ) );
	}

	public function test_configures_network_bot_for_automated_imports(): void {
		$this->assertSame( 42, apply_filters( 'data_machine_events_fallback_author_id', 0, array(), null ) );
	}

	public function test_configures_events_retention_policy(): void {
		$this->assertSame( 2, apply_filters( 'datamachine_as_actions_max_age_days', 7 ) );
		$this->assertSame( 2, apply_filters( 'datamachine_log_max_age_days', 7 ) );
		$this->assertSame( 14, apply_filters( 'datamachine_completed_jobs_max_age_days', 30 ) );
		$this->assertSame( 14, apply_filters( 'datamachine_failed_jobs_max_age_days', 30 ) );
		$this->assertSame( 14, apply_filters( 'datamachine_processed_items_max_age_days', 30 ) );
	}
}
