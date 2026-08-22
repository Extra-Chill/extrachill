<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/platform-pages.php';

final class PlatformPagesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				4 => array(
					'posts' => array(),
				),
			),
		);
	}

	public function test_fresh_install_provisions_canonical_analytics_page_with_block(): void {
		extrachill_artist_platform_create_pages();

		$page = get_page_by_path( 'analytics' );

		$this->assertNotNull( $page );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertSame( '<!-- wp:extrachill/artist-analytics /-->', $page->post_content );
	}
}
