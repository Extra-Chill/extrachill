<?php
/**
 * Public host and root page are host configuration, not hardcoded.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

final class PublicHostDefaultTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	private function withoutHostFilters( callable $callback ) {
		$saved = array();
		foreach ( array( 'ec_link_page_public_host', 'ec_link_page_root_slug' ) as $hook ) {
			$saved[ $hook ] = $GLOBALS['ec_test']['filters'][ $hook ] ?? null;
			unset( $GLOBALS['ec_test']['filters'][ $hook ] );
		}
		try {
			return $callback();
		} finally {
			foreach ( $saved as $hook => $filters ) {
				if ( null !== $filters ) {
					$GLOBALS['ec_test']['filters'][ $hook ] = $filters;
				}
			}
		}
	}

	public function test_defaults_to_the_storage_site_domain_with_no_root_page(): void {
		$this->withoutHostFilters(
			function () {
				$this->assertSame( 'storage.test', ec_link_page_public_host() );
				$this->assertSame( 'https://storage.test/', ec_link_page_public_base_url() );
				$this->assertSame( '', ec_link_page_root_slug() );
				$this->assertTrue( ec_is_link_page_public_host( 'storage.test' ) );
				$this->assertTrue( ec_is_link_page_public_host( 'www.storage.test' ) );
				$this->assertFalse( ec_is_link_page_public_host( 'extrachill.link' ) );
			}
		);
	}

	public function test_host_site_can_configure_a_dedicated_domain(): void {
		$this->assertSame( 'extrachill.link', ec_link_page_public_host() );
		$this->assertSame( 'extra-chill', ec_link_page_root_slug() );
		$this->assertTrue( ec_is_link_page_public_host( 'www.extrachill.link:443' ) );
	}
}
