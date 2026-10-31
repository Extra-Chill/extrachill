<?php
/**
 * Tests for the network-owned ad-policy adapter.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/revenue-ad-policy.php';

/**
 * Verify policy normalization and context mapping.
 */
final class RevenueAdPolicyTest extends TestCase {

	/** Missing integration stays explicitly unknown. */
	public function test_unavailable_policy_is_unknown(): void {
		$policy = extrachill_analytics_revenue_normalize_ad_policy( null );

		$this->assertNull( $policy['site_enabled'] );
		$this->assertNull( $policy['serve_ads'] );
		$this->assertSame( 'not_instrumented', $policy['reason'] );
		$this->assertSame( 'unknown', extrachill_analytics_revenue_policy_status( $policy ) );
	}

	/** Homepage and Page facts are passed as context, not policy inferred here. */
	public function test_context_maps_known_route_facts_only(): void {
		$home = extrachill_analytics_revenue_ad_policy_context( 1, 'https://extrachill.com/', '', 'home' );
		$page = extrachill_analytics_revenue_ad_policy_context( 1, 'https://extrachill.com/about/', 'page', '' );

		$this->assertTrue( $home['is_front_page'] );
		$this->assertTrue( $home['is_home'] );
		$this->assertTrue( $page['is_page'] );
		$this->assertTrue( $page['is_singular'] );
		$this->assertFalse( $page['is_front_page'] );
	}

	/** The adapter preserves all upstream policy outcomes without a site matrix. */
	public function test_upstream_outcomes_are_preserved(): void {
		foreach ( array( 'enabled', 'site_disabled', 'route_blocked', 'member_benefit', 'integration_unavailable' ) as $reason ) {
			$serve_ads = 'enabled' === $reason;
			$policy    = extrachill_analytics_revenue_normalize_ad_policy(
				array(
					'site_enabled' => ! in_array( $reason, array( 'site_disabled', 'integration_unavailable' ), true ),
					'serve_ads'    => $serve_ads,
					'reason'       => $reason,
				)
			);

			$this->assertSame( $reason, $policy['reason'] );
			$this->assertSame( $serve_ads ? 'applicable' : 'not_applicable', extrachill_analytics_revenue_policy_status( $policy ) );
		}
	}
}
