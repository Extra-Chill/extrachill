<?php
/**
 * WordPress integration tests for search result sorting and scope persistence.
 *
 * Regression coverage for #1539: the filter-bar sort control had no effect on
 * search results, and the filter-bar form dropped the network search scope.
 *
 * @package ExtraChill\Search
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

class SearchSortScopeTest extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_GET['sort'], $_GET['search_scope'] );
		parent::tear_down();
	}

	/**
	 * Result rows shaped like extrachill_hydrate_search_result() output.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function results() {
		$row = static function ( $id, $title, $date ) {
			return array(
				'ID'           => $id,
				'post_title'   => $title,
				'post_content' => '',
				'post_excerpt' => '',
				'post_date'    => $date,
			);
		};

		return array(
			$row( 1, 'Some other night', '2025-06-01 12:00:00' ),
			$row( 2, 'Phish', '2019-01-01 12:00:00' ),
			$row( 3, 'Unrelated recap', '2026-02-01 12:00:00' ),
		);
	}

	public function test_relevance_sort_ranks_title_match_first() {
		$ordered = extrachill_order_search_results( $this->results(), 'phish', array( 'sort' => 'relevance' ) );

		$this->assertSame( 2, $ordered[0]['ID'] );
	}

	public function test_recent_sort_orders_newest_first() {
		$ordered = extrachill_order_search_results( $this->results(), 'phish', array( 'sort' => 'recent' ) );

		$this->assertSame( array( 3, 1, 2 ), wp_list_pluck( $ordered, 'ID' ) );
	}

	public function test_oldest_sort_orders_oldest_first() {
		$ordered = extrachill_order_search_results( $this->results(), 'phish', array( 'sort' => 'oldest' ) );

		$this->assertSame( array( 2, 1, 3 ), wp_list_pluck( $ordered, 'ID' ) );
	}

	public function test_resolve_search_sort_validates_request() {
		$this->assertSame( 'relevance', extrachill_resolve_search_sort() );

		$_GET['sort'] = 'oldest';
		$this->assertSame( 'oldest', extrachill_resolve_search_sort() );

		$_GET['sort'] = 'random';
		$this->assertSame( 'relevance', extrachill_resolve_search_sort() );
	}

	public function test_sort_options_are_limited_on_search_results() {
		$archive_options = array(
			'recent'  => 'Sort by Recent',
			'oldest'  => 'Sort by Oldest',
			'random'  => 'Sort by Random',
			'popular' => 'Sort by Popular',
		);

		$this->go_to( home_url( '/' ) );
		$this->assertSame( $archive_options, extrachill_search_filter_bar_sort_options( $archive_options ) );

		$this->go_to( home_url( '/?s=phish' ) );
		$this->assertSame(
			extrachill_search_sort_choices(),
			array_keys( extrachill_search_filter_bar_sort_options( $archive_options ) )
		);
	}

	public function test_filter_bar_carries_network_scope_on_search_results() {
		$_GET['search_scope'] = 'network';
		$this->go_to( home_url( '/?s=phish&search_scope=network' ) );

		ob_start();
		do_action( 'extrachill_filter_bar_start' );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="hidden" name="search_scope" value="network">', $markup );
	}

	public function test_filter_bar_has_no_scope_field_off_search() {
		$this->go_to( home_url( '/' ) );

		ob_start();
		extrachill_search_filter_bar_scope_field();
		$markup = (string) ob_get_clean();

		$this->assertSame( '', $markup );
	}
}
