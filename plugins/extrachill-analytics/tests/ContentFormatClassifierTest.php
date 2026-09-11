<?php
/**
 * Content-format classifier tests.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify conservative taxonomy coverage additions preserve revenue semantics.
 */
final class ContentFormatClassifierTest extends Extrachill_Analytics_TestCase {
	/**
	 * Created category term IDs keyed by slug.
	 *
	 * @var array<string,int>
	 */
	private $term_ids = array();

	/**
	 * Create one published post with real categories.
	 *
	 * @param array<int, string> $categories Category slugs.
	 * @param string             $title Post title.
	 * @return int Post ID.
	 */
	private function fixture_post( array $categories, string $title = 'Fixture post' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		foreach ( $categories as $slug ) {
			if ( ! isset( $this->term_ids[ $slug ] ) ) {
				$existing = get_term_by( 'slug', $slug, 'category' );
				if ( $existing instanceof WP_Term ) {
					$this->term_ids[ $slug ] = (int) $existing->term_id;
				} else {
					$this->term_ids[ $slug ] = (int) self::factory()->category->create( array( 'slug' => $slug ) );
				}
			}
			wp_set_object_terms( $post_id, array( $this->term_ids[ $slug ] ), 'category', true );
		}
		return $post_id;
	}

	/**
	 * Defensible revenue-bearing categories classify into their existing formats.
	 */
	public function test_defensible_categories_classify_into_existing_formats(): void {
		$guitars = $this->fixture_post( array( 'famous-guitars' ) );
		$art     = $this->fixture_post( array( 'band-art' ) );

		$this->assertSame( 'guitar-history', extrachill_analytics_classify_format( $guitars ) );
		$this->assertSame( 'music-history', extrachill_analytics_classify_format( $art ) );
	}

	/**
	 * Every stable editorial taxonomy family resolves to its intended format.
	 */
	public function test_stable_editorial_taxonomy_families(): void {
		$fixtures = array(
			'song-meanings'      => 'song-meaning',
			'famous-guitars'     => 'guitar-history',
			'music-history'      => 'music-history',
			'charleston-music'   => 'charleston-local',
			'interviews'         => 'interview',
			'trivia'             => 'trivia',
			'lists'              => 'listicle',
			'music-theory'       => 'explainer',
			'music-news'         => 'news',
			'premieres'          => 'news',
			'live-music-reviews' => 'news',
		);

		foreach ( $fixtures as $category => $expected ) {
			$post_id = $this->fixture_post( array( $category ) );
			$this->assertSame( $expected, extrachill_analytics_classify_format( $post_id ), $category );
		}
	}

	/**
	 * Editorial eligibility follows owning site and post type, never URL shape.
	 */
	public function test_editorial_format_eligibility_is_explicit(): void {
		$this->assertTrue( extrachill_analytics_is_editorial_format_eligible( 1, 'post' ) );
		$this->assertFalse( extrachill_analytics_is_editorial_format_eligible( 1, 'page' ) );
		$this->assertFalse( extrachill_analytics_is_editorial_format_eligible( 7, 'data_machine_events' ) );
		$this->assertFalse( extrachill_analytics_is_editorial_format_eligible( 11, 'festival_wire' ) );
	}

	/**
	 * The mixed root category must not override an existing listicle taxonomy.
	 */
	public function test_musical_curiosities_preserves_listicle_precedence(): void {
		$post_id = $this->fixture_post( array( 'musical-curiosities', 'lists' ) );

		$this->assertSame( 'listicle', extrachill_analytics_classify_format( $post_id ) );
	}

	/**
	 * Reclassification changes a format bucket, never totals or the unresolved partition.
	 */
	public function test_reclassification_preserves_totals_and_unresolved_partition(): void {
		$post_id = $this->fixture_post( array( 'band-art' ) );
		$format  = extrachill_analytics_classify_format( $post_id );
		$record  = array(
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
			array( array_merge( $record, array( 'format' => $format ) ), $unresolved ),
			'format'
		);

		$this->assertSame( $before['totals'], $after['totals'] );
		$this->assertSame( $before['unresolved'], $after['unresolved'] );
		$this->assertSame( 1, $after['unresolved']['pages'] );
		$this->assertEquals( 2.50, $after['unresolved']['revenue'] );
		$this->assertSame( 'music-history', $after['rollups']['by_format'][0]['bucket'] );
	}
}
