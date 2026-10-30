<?php
/**
 * Tests for cross-site upload URL host correction.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

/**
 * @group upload-url-host
 */
class UploadUrlHostTest extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/inc/core/upload-url-host.php';
	}

	public function test_rewrites_requesting_host_to_blog_host(): void {
		$this->assertSame(
			'https://example.com/wp-content/uploads',
			extrachill_network_rewrite_upload_url( 'https://studio.example.com/wp-content/uploads', 'https://studio.example.com/wp-content', 'https://example.com/wp-content' )
		);
	}

	public function test_keeps_subsite_suffix_and_dated_path(): void {
		$this->assertSame(
			'https://artist.example.com/wp-content/uploads/sites/4/2026/09',
			extrachill_network_rewrite_upload_url( 'https://community.example.com/wp-content/uploads/sites/4/2026/09', 'https://community.example.com/wp-content', 'https://artist.example.com/wp-content/' )
		);
	}

	public function test_noop_when_hosts_match(): void {
		$url = 'https://example.com/wp-content/uploads';
		$this->assertSame( $url, extrachill_network_rewrite_upload_url( $url, 'https://example.com/wp-content', 'https://example.com/wp-content' ) );
	}

	public function test_leaves_custom_upload_url_path_untouched(): void {
		$url = 'https://cdn.example.net/uploads';
		$this->assertSame( $url, extrachill_network_rewrite_upload_url( $url, 'https://studio.example.com/wp-content', 'https://example.com/wp-content' ) );
	}

	public function test_requires_path_boundary(): void {
		$url = 'https://studio.example.com/wp-content-extra/uploads';
		$this->assertSame( $url, extrachill_network_rewrite_upload_url( $url, 'https://studio.example.com/wp-content', 'https://example.com/wp-content' ) );
	}

	public function test_filter_uses_switched_blog_siteurl(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$blog_id = (int) self::factory()->blog->create( array( 'domain' => 'other.example.org', 'path' => '/' ) );
		if ( $blog_id <= 0 || get_current_blog_id() === $blog_id ) {
			$this->markTestSkipped( 'Test environment cannot create a separate blog.' );
		}
		update_blog_option( $blog_id, 'siteurl', 'https://other.example.org' );

		$before = wp_upload_dir()['baseurl'];

		switch_to_blog( $blog_id );
		$switched = wp_upload_dir()['baseurl'];
		restore_current_blog();

		$this->assertStringStartsWith( 'https://other.example.org/wp-content/uploads', $switched );
		$this->assertSame( $before, wp_upload_dir()['baseurl'] );
	}

	public function test_filter_noop_on_unswitched_request(): void {
		$uploads = array(
			'url'     => WP_CONTENT_URL . '/uploads/2026/10',
			'baseurl' => WP_CONTENT_URL . '/uploads',
		);

		if ( untrailingslashit( get_option( 'siteurl' ) ) . '/wp-content' === WP_CONTENT_URL ) {
			$this->assertSame( $uploads, extrachill_network_filter_upload_dir_host( $uploads ) );
		} else {
			$this->assertSame( untrailingslashit( get_option( 'siteurl' ) ) . '/wp-content/uploads', extrachill_network_filter_upload_dir_host( $uploads )['baseurl'] );
		}
	}
}
