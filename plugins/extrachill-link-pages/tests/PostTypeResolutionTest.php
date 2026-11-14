<?php
/**
 * ec_link_page_post_type() resolution behavior.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

/**
 * Link Pages are always stored as `ec_link_page`.
 */
final class PostTypeResolutionTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	public function test_post_type_is_always_ec_link_page(): void {
		$this->assertSame( 'ec_link_page', ec_link_page_post_type() );
		$this->assertSame( 'ec_link_page', ec_link_page_post_type( 4 ) );
		$this->assertSame( 'ec_link_page', ec_link_page_post_type( 13 ) );
		$this->assertSame( 'ec_link_page', ec_link_page_post_type( 0 ) );
	}

	public function test_legacy_post_type_is_not_registered(): void {
		ec_register_link_page_post_type();
		$this->assertArrayHasKey( 'ec_link_page', $GLOBALS['ec_test']['registered_post_types'] );
		$this->assertArrayNotHasKey( 'artist_link_page', $GLOBALS['ec_test']['registered_post_types'] );
		$this->assertFalse( defined( 'EC_LINK_PAGE_POST_TYPE' ) );
	}
}
