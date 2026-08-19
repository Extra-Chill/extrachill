<?php
/**
 * Tests for the Data Machine Business network-density host scope (#242).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/network-density.php';

/**
 * Verify the consumer-owned network scope passed to Data Machine Business.
 */
final class NetworkDensityHostsTest extends TestCase {

	/**
	 * Reset canonical multisite fixtures before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['extrachill_analytics_test_apply_registered_filters'] = true;
		$GLOBALS['extrachill_analytics_test_registered_filters']       = array_values(
			array_filter(
				$GLOBALS['extrachill_analytics_test_registered_filters'],
				static function ( $filter ) {
					return 'datamachine_network_density_hosts' !== $filter[0]
						|| 'extrachill_analytics_network_density_hosts' === $filter[1];
				}
			)
		);
		$GLOBALS['extrachill_analytics_test_active_site_ids']          = array( 1, 2, 7, 12 );
		$GLOBALS['extrachill_analytics_test_home_urls']                = array(
			1  => 'HTTPS://EXTRACHILL.COM./',
			2  => 'https://community.extrachill.com/',
			7  => 'https://events.extrachill.com/',
			12 => 'https://studio.extrachill.com/',
			13 => 'https://archived.extrachill.com/',
		);
	}

	/**
	 * Restore the default no-op filter harness after each test.
	 */
	protected function tearDown(): void {
		$GLOBALS['extrachill_analytics_test_apply_registered_filters'] = false;
		parent::tearDown();
	}

	/**
	 * The DMB extension point is registered at plugin load.
	 */
	public function test_network_density_filter_is_registered(): void {
		$this->assertContains(
			array( 'datamachine_network_density_hosts', 'extrachill_analytics_network_density_hosts' ),
			$GLOBALS['extrachill_analytics_test_registered_filters']
		);
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

		$this->assertSame(
			array(
				'extrachill.com',
				'community.extrachill.com',
				'events.extrachill.com',
				'studio.extrachill.com',
			),
			apply_filters( 'datamachine_network_density_hosts', array() )
		);
		$this->assertNotContains( 'archived.extrachill.com', apply_filters( 'datamachine_network_density_hosts', array() ) );
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
