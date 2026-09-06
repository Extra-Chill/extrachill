<?php
/**
 * Single-site boot regression coverage.
 *
 * WordPress loads wp-includes/ms-site.php only on multisite, so get_site() does
 * not exist on a single-site install. The plugin previously called it
 * unconditionally from ec_validate_link_page_storage_blog_id(), which made
 * activation fatal on init for every single-site install (issue #15).
 *
 * The harness models that boundary by throwing from its get_site() stub
 * whenever is_multisite() is false, so any regression here fails loudly
 * instead of silently passing against an API that will not exist at runtime.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class SingleSiteBootTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['multisite']       = false;
		$GLOBALS['ec_test']['current_blog_id'] = 1;
		// Single site has exactly one blog; the fixture's network blogs do not apply.
		$GLOBALS['ec_test']['blogs'] = array(
			1 => array(
				'posts'     => array(),
				'post_meta' => array(),
				'terms'     => array(),
			),
		);
	}

	protected function tearDown(): void {
		ec_test_reset();
	}

	/** The harness must refuse multisite-only APIs on single site. */
	public function test_harness_treats_get_site_as_unavailable_on_single_site(): void {
		$this->expectException( EcTestMultisiteApiUnavailable::class );
		get_site( 1 );
	}

	/** Storage resolution must not reach for get_site() on single site. */
	public function test_storage_blog_resolves_without_multisite_api(): void {
		$this->assertSame( 1, ec_get_link_page_storage_blog_id() );
	}

	/** The validator accepts the current blog without a site record. */
	public function test_validator_accepts_current_blog_on_single_site(): void {
		$this->assertSame( 1, ec_validate_link_page_storage_blog_id( 1 ) );
	}

	/** A non-current blog cannot be the storage site on single site. */
	public function test_validator_rejects_foreign_blog_on_single_site(): void {
		$this->assertSame( 0, ec_validate_link_page_storage_blog_id( 4 ) );
	}

	/** Zero and negative candidates stay invalid without touching multisite APIs. */
	public function test_validator_rejects_empty_candidate_on_single_site(): void {
		$this->assertSame( 0, ec_validate_link_page_storage_blog_id( 0 ) );
		$this->assertSame( 0, ec_validate_link_page_storage_blog_id( -3 ) );
	}

	/** Post type registration must complete on single site instead of fataling. */
	public function test_post_type_registers_on_single_site(): void {
		ec_register_link_page_post_type();
		$this->assertArrayHasKey( EC_LINK_PAGE_POST_TYPE, $GLOBALS['ec_test']['registered_post_types'] );
	}

	/** Multisite behavior is unchanged: site records are still consulted. */
	public function test_multisite_validation_still_inspects_site_record(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['sites'][4] = array( 'deleted' => 1 );
		$this->assertSame( 0, ec_validate_link_page_storage_blog_id( 4 ) );
	}
}
