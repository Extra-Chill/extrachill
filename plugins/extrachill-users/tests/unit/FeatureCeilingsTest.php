<?php
/**
 * Unit tests for the feature ceiling registry.
 *
 * @package ExtraChill\Users
 */

/**
 * Locks the code ceilings that bound each feature's network rollout tier.
 */
class Test_Feature_Ceilings extends WP_UnitTestCase {

	/**
	 * Reset the shop rollout option between tests.
	 */
	public function tear_down(): void {
		delete_site_option( 'ec_feature_tier_shop' );
		parent::tear_down();
	}

	/**
	 * The shop code is ready for every tier.
	 */
	public function test_shop_ceiling_is_public(): void {
		$this->assertSame( 'public', ec_feature_ceiling( 'shop' ) );
	}

	/**
	 * Brand socials stays admin-only until a review workflow exists.
	 */
	public function test_brand_socials_ceiling_stays_admin(): void {
		$this->assertSame( 'admin', ec_feature_ceiling( 'brand_socials' ) );
	}

	/**
	 * The network option still sets the live shop tier below the ceiling.
	 */
	public function test_shop_live_tier_follows_network_option(): void {
		update_site_option( 'ec_feature_tier_shop', 'team' );
		$this->assertSame( 'team', ec_feature_tier( 'shop' ) );

		update_site_option( 'ec_feature_tier_shop', 'admin' );
		$this->assertSame( 'admin', ec_feature_tier( 'shop' ) );
	}
}
