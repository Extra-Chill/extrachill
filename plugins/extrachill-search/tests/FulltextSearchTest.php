<?php
/**
 * WordPress integration tests for indexed search routing.
 *
 * @package ExtraChill\Search
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

class FulltextSearchTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		extrachill_ensure_fulltext_index( get_current_blog_id(), true );
	}

	public function test_current_posts_table_has_exact_fulltext_index() {
		global $wpdb;

		$status = extrachill_get_fulltext_index_status( true );

		$this->assertTrue( $status['ready'] );
		$this->assertSame( $wpdb->posts, $status['table'] );
		$this->assertSame( array( 'post_title', 'post_excerpt', 'post_content' ), $status['expected_columns'] );
	}

	public function test_fulltext_filters_replace_like_sql() {
		$query = new WP_Query();
		$query->set( 'extrachill_fulltext_term', 'live music' );

		$search = extrachill_fulltext_posts_search( ' AND legacy LIKE clause', $query );
		$order  = extrachill_fulltext_posts_orderby( 'post_date DESC', $query );

		$this->assertStringContainsString( 'MATCH(', $search );
		$this->assertStringContainsString( "AGAINST('+live* +music*' IN BOOLEAN MODE)", $search );
		$this->assertStringNotContainsString( 'LIKE', $search );
		$this->assertStringContainsString( 'MATCH(', $order );
	}

	public function test_frontend_main_search_routes_without_found_rows() {
		global $wp_the_query;

		$previous_query = $wp_the_query;
		$query          = new WP_Query();
		$query->parse_query( array( 's' => 'festival' ) );
		$wp_the_query = $query;

		try {
			extrachill_route_frontend_search( $query );
			$this->assertSame( 'festival', $query->get( 'extrachill_fulltext_term' ) );
			$this->assertTrue( $query->get( 'no_found_rows' ) );
		} finally {
			$wp_the_query = $previous_query;
		}
	}

	public function test_ready_index_repair_is_idempotent() {
		$status = extrachill_ensure_fulltext_index( get_current_blog_id(), false );

		$this->assertTrue( $status['ready'] );
		$this->assertSame( 'none', $status['action'] );
		$this->assertFalse( $status['applied'] );
	}
}
