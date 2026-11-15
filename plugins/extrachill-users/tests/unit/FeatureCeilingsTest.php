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
		remove_all_filters( 'ec_feature_default_tiers' );
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

	/**
	 * Without a stored option or registered starting tier, the ceiling applies.
	 */
	public function test_unset_option_falls_back_to_the_ceiling(): void {
		delete_site_option( 'ec_feature_tier_shop' );
		$this->assertSame( 'public', ec_feature_tier( 'shop' ) );
	}

	/**
	 * A registered starting tier holds while the option is unset.
	 */
	public function test_registered_starting_tier_applies_while_option_is_unset(): void {
		delete_site_option( 'ec_feature_tier_shop' );
		add_filter( 'ec_feature_default_tiers', static fn() => array( 'shop' => 'team' ) );

		$this->assertSame( 'team', ec_feature_default_tier( 'shop' ) );
		$this->assertSame( 'team', ec_feature_tier( 'shop' ) );
	}

	/**
	 * A starting tier can never exceed the ceiling, and invalid values fall back to it.
	 */
	public function test_starting_tier_is_clamped_and_validated(): void {
		add_filter( 'ec_feature_default_tiers', static fn() => array( 'brand_socials' => 'public', 'shop' => 'everyone' ) );

		$this->assertSame( 'admin', ec_feature_default_tier( 'brand_socials' ) );
		$this->assertSame( 'public', ec_feature_default_tier( 'shop' ) );
	}

	/**
	 * Promotion from an unset option persists (Extra-Chill/extrachill#1509).
	 */
	public function test_promoting_from_a_registered_starting_tier_persists(): void {
		delete_site_option( 'ec_feature_tier_shop' );
		add_filter( 'ec_feature_default_tiers', static fn() => array( 'shop' => 'team' ) );

		$this->assertTrue( update_site_option( 'ec_feature_tier_shop', 'public' ) );
		$this->assertSame( 'public', ec_feature_tier( 'shop' ) );
	}
}
