<?php
/**
 * Content-format classifier tests.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/content-format-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue.php';

/**
 * Verify conservative taxonomy coverage additions preserve revenue semantics.
 */
final class ContentFormatClassifierTest extends TestCase {

	/**
	 * Reset classifier fixtures after each test.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['extrachill_analytics_classifier_posts'],
			$GLOBALS['extrachill_analytics_classifier_permalinks'],
			$GLOBALS['extrachill_analytics_classifier_terms']
		);
	}

	/**
	 * Configure one published post and its category fixtures.
	 *
	 * @param int                $id Post ID.
	 * @param array<int, string> $categories Category slugs.
	 * @param string             $title Post title.
	 */
	private function fixture_post( int $id, array $categories, string $title = 'Fixture post' ): void {
		$post             = new WP_Post();
		$post->ID         = $id;
		$post->post_title = $title;

		$GLOBALS['extrachill_analytics_classifier_posts'][ $id ]      = $post;
		$GLOBALS['extrachill_analytics_classifier_terms'][ $id ]      = $categories;
		$GLOBALS['extrachill_analytics_classifier_permalinks'][ $id ] = 'https://extrachill.com/fixture-' . $id . '/';
	}

	/**
	 * Defensible revenue-bearing categories classify into their existing formats.
	 */
	public function test_defensible_categories_classify_into_existing_formats(): void {
		$this->fixture_post( 145, array( 'famous-guitars' ) );
		$this->fixture_post( 146, array( 'band-art' ) );

		$this->assertSame( 'guitar-history', extrachill_analytics_classify_format( 145 ) );
		$this->assertSame( 'music-history', extrachill_analytics_classify_format( 146 ) );
	}

	/**
	 * The mixed root category must not override an existing listicle taxonomy.
	 */
	public function test_musical_curiosities_preserves_listicle_precedence(): void {
		$this->fixture_post( 147, array( 'musical-curiosities', 'lists' ) );

		$this->assertSame( 'listicle', extrachill_analytics_classify_format( 147 ) );
	}

	/**
	 * Reclassification changes a format bucket, never totals or the unresolved partition.
	 */
	public function test_reclassification_preserves_totals_and_unresolved_partition(): void {
		$this->fixture_post( 145, array( 'band-art' ) );
		$record = array(
			'is_content' => true,
			'page_key'   => 'p145',
			'categories' => array( 'band-art' ),
			'views'      => 1250,
			'revenue'    => 37.50,
			'url'        => '/album-art-history/',
		);

		$unresolved = array(
			'is_content' => false,
			'page_key'   => 'u123',
			'views'      => 500,
			'revenue'    => 2.50,
			'url'        => '/not-a-post/',
		);
		$before     = extrachill_analytics_revenue_build_rollups(
			array( array_merge( $record, array( 'format' => 'uncategorized' ) ), $unresolved ),
			'format'
		);
		$after      = extrachill_analytics_revenue_build_rollups(
			array( array_merge( $record, array( 'format' => extrachill_analytics_classify_format( 145 ) ) ), $unresolved ),
			'format'
		);

		$this->assertSame( $before['totals'], $after['totals'] );
		$this->assertSame( $before['unresolved'], $after['unresolved'] );
		$this->assertSame( 1, $after['unresolved']['pages'] );
		$this->assertEquals( 2.50, $after['unresolved']['revenue'] );
		$this->assertSame( 'music-history', $after['rollups']['by_format'][0]['bucket'] );
	}
}
