<?php
/**
 * Tests for the cross-site forward-surface internal-linking candidate hook.
 *
 * Covers inc/cross-site-links/internal-linking-candidates.php: the
 * `datamachine_internal_linking_candidates` filter callback that adds sibling-
 * site (events/community/wire) targets to Data Machine's same-site candidate
 * list and biases forward surfaces above the residual catalog.
 *
 * The cross-site term-link primitive (extrachill_get_cross_site_term_links) is
 * controlled deterministically by priming its persistent object-cache entry, so
 * the suite performs no cross-site queries or HTTP loopbacks.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

/**
 * Cross-site internal-linking candidate hook tests.
 *
 * @group internal-linking
 */
class InternalLinkingCandidatesTest extends WP_UnitTestCase {

	/**
	 * Artist discussions are a destination, while artist profiles stay canonical.
	 */
	public function test_artist_map_includes_community_without_changing_canonical_authority(): void {
		$site_map         = extrachill_get_taxonomy_site_map();
		$canonical_config = extrachill_get_taxonomy_canonical_config();

		$this->assertSame(
			array( 'main', 'events', 'shop', 'artist', 'community' ),
			$site_map['artist']
		);
		$this->assertSame( 'artist', $canonical_config['artist']['canonical'] );
	}

	/**
	 * Prime the cross-site term-link cache for a term so the hook reads the
	 * supplied links instead of computing them.
	 *
	 * Mirrors the cache key built in extrachill_get_cross_site_term_links().
	 *
	 * @param WP_Term $term  Term object.
	 * @param array   $links Link rows to return.
	 */
	private function prime_cross_site_links( WP_Term $term, array $links ): void {
		$current_site_key = function_exists( 'extrachill_get_current_site_key' )
			? extrachill_get_current_site_key()
			: null;
		$bare_key         = 'links_' . $term->taxonomy . '_' . (int) $term->term_id . '_' . (string) $current_site_key;
		$cache_key        = function_exists( 'extrachill_cross_site_links_cache_key' )
			? extrachill_cross_site_links_cache_key( $bare_key )
			: $bare_key;
		wp_cache_set( $cache_key, $links, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP, HOUR_IN_SECONDS );
	}

	/**
	 * Flush the primed cross-site link cache between tests.
	 */
	public function tear_down() {
		if ( defined( 'EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP' ) ) {
			wp_cache_flush_group( EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
		}
		parent::tear_down();
	}

	/**
	 * No registered cross-site taxonomy on the post → candidates unchanged.
	 */
	public function test_no_terms_returns_candidates_unchanged(): void {
		$post_id = self::factory()->post->create();
		$same    = array(
			array(
				'id'    => 5,
				'url'   => 'https://extrachill.com/a/',
				'title' => 'A',
				'score' => 3.0,
			),
		);

		$result = apply_filters( 'datamachine_internal_linking_candidates', $same, $post_id, 'Src', array(), array(), 3 );

		$this->assertSame( $same, $result );
	}

	/**
	 * A forward-surface term link is appended and scored above same-site.
	 */
	public function test_forward_surface_candidate_is_added_and_boosted(): void {
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'post' );
		}

		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'artist',
				'name'     => 'Wednesday',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $term_id ), 'artist' );
		$term = get_term( $term_id, 'artist' );

		$this->prime_cross_site_links(
			$term,
			array(
				array(
					'blog_id'   => 7,
					'site_key'  => 'events',
					'url'       => 'https://events.extrachill.com/artist/wednesday/',
					'label'     => 'Events',
					'term_name' => 'Wednesday',
					'count'     => 3,
				),
			)
		);

		$same = array(
			array(
				'id'    => 5,
				'url'   => 'https://extrachill.com/some-song-meaning/',
				'title' => 'Song',
				'score' => 8.0,
			),
		);

		$result = apply_filters( 'datamachine_internal_linking_candidates', $same, $post_id, 'Src', array(), array(), 5 );

		$this->assertCount( 2, $result, 'cross-site candidate appended' );

		$added = $result[1];
		$this->assertSame( 'https://events.extrachill.com/artist/wednesday/', $added['url'] );
		$this->assertSame( 0, $added['id'], 'off-site candidate carries id 0' );
		$this->assertSame( 'Wednesday', $added['title'] );
		$this->assertGreaterThan( 8.0, $added['score'], 'forward-surface candidate outranks same-site' );
	}

	/**
	 * An events archive below the UPCOMING-show floor is never linked: crosslinks
	 * are written permanently, so an archive with only past shows is skipped.
	 */
	public function test_events_archive_below_upcoming_floor_is_skipped(): void {
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'post' );
		}

		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'artist',
				'name'     => 'Past Only Band',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $term_id ), 'artist' );
		$term = get_term( $term_id, 'artist' );

		$this->prime_cross_site_links(
			$term,
			array(
				array(
					'blog_id'   => 7,
					'site_key'  => 'events',
					'url'       => 'https://events.extrachill.com/artist/past-only-band',
					'label'     => 'Events',
					'term_name' => 'Past Only Band',
					'count'     => 2,
				),
			)
		);

		$result = apply_filters( 'datamachine_internal_linking_candidates', array(), $post_id, 'Src', array(), array(), 5 );

		$this->assertSame( array(), $result, 'events archive with fewer than 3 upcoming shows is not a candidate' );
	}

	/**
	 * A non-forward cross-site target (e.g. shop) is added but not boosted
	 * above a forward surface.
	 */
	public function test_non_forward_surface_scores_below_forward(): void {
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'post' );
		}

		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'artist',
				'name'     => 'MJ Lenderman',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $term_id ), 'artist' );
		$term = get_term( $term_id, 'artist' );

		$this->prime_cross_site_links(
			$term,
			array(
				array(
					'blog_id'   => 7,
					'site_key'  => 'events',
					'url'       => 'https://events.extrachill.com/artist/mj/',
					'label'     => 'Events',
					'term_name' => 'MJ Lenderman',
					'count'     => 1,
				),
				array(
					'blog_id'   => 3,
					'site_key'  => 'shop',
					'url'       => 'https://shop.extrachill.com/artist/mj/',
					'label'     => 'Shop',
					'term_name' => 'MJ Lenderman',
					'count'     => 1,
				),
			)
		);

		$result = apply_filters( 'datamachine_internal_linking_candidates', array(), $post_id, 'Src', array(), array(), 5 );

		$this->assertCount( 2, $result );

		$by_site = array();
		foreach ( $result as $row ) {
			$by_site[ $row['url'] ] = $row['score'];
		}

		$this->assertGreaterThan(
			$by_site['https://shop.extrachill.com/artist/mj/'],
			$by_site['https://events.extrachill.com/artist/mj/'],
			'forward surface (events) outranks non-forward (shop)'
		);
	}

	/**
	 * A cross-site URL already linked from the post is not offered again.
	 */
	public function test_duplicate_url_is_skipped(): void {
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'post' );
		}

		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'artist',
				'name'     => 'Indigo De Souza',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $term_id ), 'artist' );
		$term = get_term( $term_id, 'artist' );

		$dupe = 'https://events.extrachill.com/artist/indigo/';
		$this->prime_cross_site_links(
			$term,
			array(
				array(
					'blog_id'   => 7,
					'site_key'  => 'events',
					'url'       => $dupe,
					'label'     => 'Events',
					'term_name' => 'Indigo De Souza',
					'count'     => 1,
				),
			)
		);

		$same = array(
			array(
				'id'    => 0,
				'url'   => $dupe,
				'title' => 'Indigo',
				'score' => 1.0,
			),
		);

		$result = apply_filters( 'datamachine_internal_linking_candidates', $same, $post_id, 'Src', array(), array(), 5 );

		$this->assertCount( 1, $result, 'already-linked cross-site URL is not duplicated' );
	}

	/** Wire location archives are withheld from non-news source articles. */
	public function test_wire_location_requires_news_category(): void {
		register_taxonomy( 'location', 'post' );
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'location', 'name' => 'California' ) );
		wp_set_object_terms( $post_id, array( $term_id ), 'location' );
		$term = get_term( $term_id, 'location' );
		$this->prime_cross_site_links( $term, array( array( 'blog_id' => 9, 'site_key' => 'wire', 'url' => 'https://wire.extrachill.com/location/california', 'term_name' => 'California', 'count' => 1 ) ) );

		$this->assertSame( array(), apply_filters( 'datamachine_internal_linking_candidates', array(), $post_id, 'Song Meaning', array(), array(), 5 ) );

		$category_id = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Music News', 'slug' => 'music-news' ) );
		wp_set_post_categories( $post_id, array( $category_id ) );
		$result = apply_filters( 'datamachine_internal_linking_candidates', array(), $post_id, 'News', array(), array(), 5 );
		$this->assertSame( 'https://wire.extrachill.com/location/california', $result[0]['url'] );
	}
}
