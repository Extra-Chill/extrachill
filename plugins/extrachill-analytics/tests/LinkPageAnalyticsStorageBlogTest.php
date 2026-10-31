<?php
/**
 * Link Page analytics runtime tables follow the Link Page storage blog.
 *
 * @package ExtraChillAnalytics
 */

class LinkPageAnalyticsStorageBlogTest extends WP_UnitTestCase {

	public function test_storage_tables_default_to_the_resolved_storage_blog(): void {
		global $wpdb;
		$blog_id = function_exists( 'ec_get_link_page_storage_blog_id' ) ? (int) ec_get_link_page_storage_blog_id() : 0;
		$prefix  = $blog_id > 0 ? $wpdb->get_blog_prefix( $blog_id ) : $wpdb->prefix;
		$this->assertSame( $prefix . 'extrch_link_page_daily_views', extrachill_analytics_link_page_storage_views_table() );
		$this->assertSame( $prefix . 'extrch_link_page_daily_link_clicks', extrachill_analytics_link_page_storage_clicks_table() );
	}

	public function test_storage_tables_use_the_storage_blog_prefix(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}
		global $wpdb;
		$blog_id  = (int) self::factory()->blog->create();
		$callback = static function () use ( $blog_id ) {
			return $blog_id;
		};
		add_filter( 'extrachill_analytics_link_page_storage_blog_id', $callback );
		try {
			$this->assertSame( $wpdb->get_blog_prefix( $blog_id ) . 'extrch_link_page_daily_views', extrachill_analytics_link_page_storage_views_table() );
			$this->assertSame( $wpdb->get_blog_prefix( $blog_id ) . 'extrch_link_page_daily_link_clicks', extrachill_analytics_link_page_storage_clicks_table() );
			$this->assertNotSame( extrachill_analytics_link_page_views_table(), extrachill_analytics_link_page_storage_views_table() );
		} finally {
			remove_filter( 'extrachill_analytics_link_page_storage_blog_id', $callback );
		}
	}
}
