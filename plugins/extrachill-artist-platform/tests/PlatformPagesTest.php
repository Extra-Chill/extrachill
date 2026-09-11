<?php

require_once __DIR__ . '/support/base-test-case.php';

final class PlatformPagesTest extends EC_Artist_Platform_TestCase {
	public function test_fresh_install_provisions_canonical_analytics_page_with_block(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			extrachill_artist_platform_create_pages();

			$page = get_page_by_path( 'analytics' );

			$this->assertNotNull( $page );
			$this->assertSame( 'publish', $page->post_status );
			$this->assertSame( '<!-- wp:extrachill/artist-analytics /-->', $page->post_content );
		} finally {
			restore_current_blog();
		}
	}
}
