<?php
/**
 * ec_link_page_post_type() resolution behavior.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

/**
 * The post type follows the storage site, resolved lazily at call time.
 *
 * The bootstrap harness always keeps the storage blog pinned to 4 (gate
 * off), so every existing fixture stays legacy-only. These tests exercise
 * explicit blog IDs to prove the dedicated-site mapping without disturbing
 * that default.
 */
final class PostTypeResolutionTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	public function test_legacy_storage_site_resolves_to_the_historical_type(): void {
		$this->assertSame( 'artist_link_page', ec_link_page_post_type( 4 ) );
		$this->assertSame( 'artist_link_page', ec_link_page_post_type() );
	}

	public function test_dedicated_storage_site_resolves_to_the_new_type(): void {
		$this->assertSame( 'ec_link_page', ec_link_page_post_type( 13 ) );
		// The harness's storage blog stays pinned to 4 (gate off): the
		// default-blog resolution must not flip just because the dedicated
		// site's ID was looked up elsewhere.
		$this->assertSame( 'artist_link_page', ec_link_page_post_type() );
	}

	public function test_resolution_is_never_pinned_to_a_literal(): void {
		$this->assertTrue( defined( 'EC_LINK_PAGE_POST_TYPE' ) );
		$this->assertSame( 'artist_link_page', EC_LINK_PAGE_POST_TYPE );
		// Deprecated back-compat value, distinct from the resolved API.
		$this->assertSame( ec_link_page_post_type( 4 ), EC_LINK_PAGE_POST_TYPE );
		$this->assertNotSame( ec_link_page_post_type( 13 ), EC_LINK_PAGE_POST_TYPE );
	}

	public function test_zero_or_unresolvable_blog_falls_back_to_legacy(): void {
		$this->assertSame( 'artist_link_page', ec_link_page_post_type( 0 ) );
		$this->assertSame( 'artist_link_page', ec_link_page_post_type( -5 ) );
	}

	public function test_registration_uses_the_resolved_type_per_blog(): void {
		ec_register_link_page_post_type();
		$this->assertArrayHasKey( 'artist_link_page', $GLOBALS['ec_test']['registered_post_types'] );
		$this->assertArrayNotHasKey( 'ec_link_page', $GLOBALS['ec_test']['registered_post_types'] );

		switch_to_blog( 13 );
		ec_register_link_page_post_type();
		$this->assertArrayHasKey( 'ec_link_page', $GLOBALS['ec_test']['registered_post_types'] );
		restore_current_blog();
	}
}
