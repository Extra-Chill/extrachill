<?php
/**
 * WordPress integration tests for search result hydration.
 *
 * @package ExtraChill\Search
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

class HydrateSearchResultTest extends WP_UnitTestCase {

	public function test_hydrates_title_permalink_and_content_without_global_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Phish at the Gorge',
				'post_content' => 'A long night of improvisation under the stars.',
				'post_status'  => 'publish',
			)
		);

		$GLOBALS['post'] = null;

		$post = get_post( $post_id );
		setup_postdata( $post );
		$result = extrachill_hydrate_search_result( $post, get_current_blog_id(), get_blog_details( get_current_blog_id() ) );
		wp_reset_postdata();

		$this->assertSame( 'Phish at the Gorge', $result['post_title'] );
		$this->assertSame( get_permalink( $post_id ), $result['permalink'] );
		$this->assertNotEmpty( $result['permalink'] );
		$this->assertStringContainsString( 'improvisation', $result['post_content'] );
		$this->assertStringContainsString( 'improvisation', $result['post_excerpt'] );
	}

	public function test_hydration_ignores_an_unrelated_global_post() {
		$other_id = self::factory()->post->create( array( 'post_title' => 'Unrelated Page' ) );
		$post_id  = self::factory()->post->create( array( 'post_title' => 'Grateful Dead Setlists' ) );

		$GLOBALS['post'] = get_post( $other_id );

		$result = extrachill_hydrate_search_result( get_post( $post_id ), get_current_blog_id(), get_blog_details( get_current_blog_id() ) );

		$this->assertSame( 'Grateful Dead Setlists', $result['post_title'] );
		$this->assertSame( get_permalink( $post_id ), $result['permalink'] );
	}
}
