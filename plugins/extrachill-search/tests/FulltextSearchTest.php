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

	public function test_frontend_main_search_is_template_owned() {
		global $wp_query, $wp_the_query;

		$previous_query     = $wp_query;
		$previous_the_query = $wp_the_query;
		$query              = new WP_Query();
		$wp_query           = $query;
		$wp_the_query       = $query;
		$content_queries    = array();
		$query_filter       = static function ( $sql ) use ( &$content_queries ) {
			if ( false !== strpos( $sql, 'LIKE' ) || false !== strpos( $sql, 'MATCH(' ) ) {
				$content_queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $query_filter );

		try {
			$query->query( array( 's' => 'festival' ) );

			$this->assertTrue( $query->is_search() );
			$this->assertSame( 'festival', $query->get( 's' ) );
			$this->assertSame( 'festival', get_search_query( false ) );
			$this->assertTrue( $query->get( 'extrachill_template_owned_search' ) );
			$this->assertSame( array(), $query->posts );
			$this->assertSame( 0, $query->found_posts );
			$this->assertSame( 0, $query->max_num_pages );
			$this->assertSame( array(), $content_queries, 'The discarded main query executed a content search.' );
		} finally {
			remove_filter( 'query', $query_filter );
			$wp_query     = $previous_query;
			$wp_the_query = $previous_the_query;
		}
	}

	public function test_empty_or_secondary_search_is_not_short_circuited() {
		$empty = new WP_Query();
		$empty->parse_query( array( 's' => '' ) );
		extrachill_route_frontend_search( $empty );

		$secondary = new WP_Query();
		$secondary->parse_query( array( 's' => 'festival' ) );
		extrachill_route_frontend_search( $secondary );

		$this->assertFalse( (bool) $empty->get( 'extrachill_template_owned_search' ) );
		$this->assertFalse( (bool) $secondary->get( 'extrachill_template_owned_search' ) );
	}

	public function test_ready_index_repair_is_idempotent() {
		$status = extrachill_ensure_fulltext_index( get_current_blog_id(), false );

		$this->assertTrue( $status['ready'] );
		$this->assertSame( 'none', $status['action'] );
		$this->assertFalse( $status['applied'] );
	}
}
