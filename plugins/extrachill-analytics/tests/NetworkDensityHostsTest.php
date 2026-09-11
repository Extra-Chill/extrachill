<?php
/**
 * Tests for the Data Machine Business network-density host scope (#242).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once __DIR__ . '/class-platform-contract-fixture.php';

/**
 * Verify the consumer-owned network scope passed to Data Machine Business.
 */
final class NetworkDensityHostsTest extends Extrachill_Analytics_TestCase {
	/**
	 * The DMB extension point is registered at plugin load.
	 */
	public function test_network_density_filter_is_registered(): void {
		$this->assertSame( 10, has_filter( 'datamachine_network_density_hosts', 'extrachill_analytics_network_density_hosts' ) );
	}

	/**
	 * Canonical discovery replaces earlier values with normalized active hosts.
	 */
	public function test_real_filter_composition_uses_canonical_active_multisite_hosts(): void {
		add_filter(
			'datamachine_network_density_hosts',
			static function () {
				return array( 'earlier.example' );
			},
			5
		);

		$community = $this->create_blog( 'community.example.org' );
		$events    = $this->create_blog( 'events.example.org' );
		$studio    = $this->create_blog( 'studio.example.org' );
		$GLOBALS['extrachill_analytics_test_active_site_ids'] = array( 1, $community, $events, $studio );

		$this->assertSame(
			array(
				'localhost',
				'community.example.org',
				'events.example.org',
				'studio.example.org',
			),
			apply_filters( 'datamachine_network_density_hosts', array() )
		);
		$this->assertNotContains( 'earlier.example', apply_filters( 'datamachine_network_density_hosts', array() ) );
	}

	/**
	 * A missing canonical result preserves earlier valid filter contributions.
	 */
	public function test_real_filter_composition_falls_back_to_earlier_hosts(): void {
		$GLOBALS['extrachill_analytics_test_active_site_ids'] = array();
		add_filter(
			'datamachine_network_density_hosts',
			static function () {
				return array( 'fallback.example' );
			},
			5
		);

		$this->assertSame(
			array( 'fallback.example' ),
			apply_filters( 'datamachine_network_density_hosts', array() )
		);
	}
}
