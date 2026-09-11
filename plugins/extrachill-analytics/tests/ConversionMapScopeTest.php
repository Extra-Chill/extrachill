<?php
/**
 * Tests for conversion-map entry and destination scope helpers.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Protect the intentionally narrow, currently collected conversion-map scope.
 */
final class ConversionMapScopeTest extends Extrachill_Analytics_TestCase {
	/**
	 * Serve extrachill.com permalinks for identity assertions.
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter(
			'home_url',
			static function ( $url, $path = '' ) {
				return 'https://extrachill.com' . $path;
			},
			10,
			2
		);
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Create one real post for scope checks.
	 *
	 * @param array $args Post args.
	 * @return int Post ID.
	 */
	private function scope_post( array $args ): int {
		$user_id = self::factory()->user->create();
		return self::factory()->post->create(
			array_merge(
				array(
					'post_status' => 'publish',
					'post_type'   => 'post',
					'post_author' => $user_id,
				),
				$args
			)
		);
	}

	/**
	 * Non-post singular objects cannot inflate the editorial-entry denominator.
	 */
	public function test_only_published_posts_are_editorial_entries(): void {
		$page      = $this->scope_post( array( 'post_type' => 'page' ) );
		$draft     = $this->scope_post( array( 'post_status' => 'draft' ) );
		$published = $this->scope_post( array() );

		$this->assertFalse(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => $page,
				),
				1
			)
		);
		$this->assertFalse(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => $draft,
				),
				1
			)
		);
		$this->assertTrue(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => $published,
				),
				1
			)
		);
	}

	/**
	 * An author-scoped report admits only posts whose primary author matches.
	 */
	public function test_editorial_entries_can_be_scoped_to_primary_author(): void {
		$first_author = self::factory()->user->create();
		$other_author = self::factory()->user->create();
		$mine         = $this->scope_post( array( 'post_author' => $first_author ) );
		$theirs       = $this->scope_post( array( 'post_author' => $other_author ) );

		$this->assertTrue(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => $mine,
				),
				1,
				$first_author
			)
		);
		$this->assertFalse(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => $theirs,
				),
				1,
				$first_author
			)
		);
		$this->assertTrue(
			extrachill_analytics_conversion_is_editorial_entry(
				array(
					'blog_id' => 1,
					'post_id' => $theirs,
				),
				1
			)
		);
	}

	/**
	 * Route-level destinations count without becoming editorial entries.
	 */
	public function test_homepage_and_archive_destinations_are_counted(): void {
		$platform = array(
			7 => 'events',
			2 => 'community',
			4 => 'artist',
		);

		$this->assertTrue(
			extrachill_analytics_conversion_is_measured_platform_event(
				array(
					'blog_id' => 7,
					'post_id' => 0,
				),
				$platform
			)
		);
		$this->assertTrue(
			extrachill_analytics_conversion_is_measured_platform_event(
				array(
					'blog_id' => 2,
					'post_id' => 0,
				),
				$platform
			)
		);
		$this->assertTrue(
			extrachill_analytics_conversion_is_measured_platform_event(
				array(
					'blog_id' => 4,
					'post_id' => 99,
				),
				$platform
			)
		);
		$this->assertFalse(
			extrachill_analytics_conversion_is_measured_platform_event(
				array(
					'blog_id' => 9,
					'post_id' => 0,
				),
				$platform
			)
		);
	}

	/**
	 * Pre-window sessions and late entries do not enter the mature denominator.
	 */
	public function test_entry_session_requires_full_return_observation_period(): void {
		$published = $this->scope_post( array() );

		$since  = '2026-07-01 00:00:00';
		$cutoff = '2026-07-08 00:00:00';
		$this->assertFalse(
			extrachill_analytics_conversion_is_mature_entry_session(
				array(
					'blog_id' => 1,
					'post_id' => $published,
					'ts'      => strtotime( '2026-06-30 23:59:59' ),
				),
				1,
				$since,
				$cutoff
			)
		);
		$this->assertTrue(
			extrachill_analytics_conversion_is_mature_entry_session(
				array(
					'blog_id' => 1,
					'post_id' => $published,
					'ts'      => strtotime( '2026-07-05 12:00:00' ),
				),
				1,
				$since,
				$cutoff
			)
		);
		$this->assertFalse(
			extrachill_analytics_conversion_is_mature_entry_session(
				array(
					'blog_id' => 1,
					'post_id' => $published,
					'ts'      => strtotime( '2026-07-08 00:00:01' ),
				),
				1,
				$since,
				$cutoff
			)
		);
	}

	/**
	 * Machine consumers receive complete canonical article identity and typed metrics.
	 */
	public function test_article_identity_and_metrics_are_machine_readable(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Mama Say Mama Sa Mama Coosa: The Story Behind an Iconic Michael Jackson Lyric',
				'post_name'   => 'mama-say-mama-sa-mama-coosa',
				'post_status' => 'publish',
			)
		);
		$post    = get_post( $post_id );

		$identity = extrachill_analytics_conversion_article_identity( 1, $post );
		$metrics  = extrachill_analytics_conversion_rate_row(
			array_merge(
				extrachill_analytics_conversion_zero_bucket(),
				array(
					'entry_sessions'     => 4,
					'reached_any'        => 2,
					'reached_any_same'   => 1,
					'reached_any_return' => 1,
					'returned'           => 3,
				)
			),
			array( 'post_id' => $post_id )
		);

		$this->assertSame( $post->post_title, $identity['title'] );
		$this->assertSame( 'https://extrachill.com/mama-say-mama-sa-mama-coosa/', $identity['url'] );
		$this->assertSame( '/mama-say-mama-sa-mama-coosa/', $identity['path'] );
		$this->assertSame( $post_id, $metrics['post_id'] );
		$this->assertIsInt( $metrics['entry_sessions'] );
		$this->assertIsInt( $metrics['reached_any'] );
		$this->assertIsFloat( $metrics['reached_any_rate'] );
		$this->assertSame( 0.5, $metrics['reached_any_rate'] );
	}

	/**
	 * The contract documents buffered lower-boundary sessionization and mature
	 * journey denominator semantics instead of implying every entry session.
	 */
	public function test_contract_declares_boundary_and_denominator_semantics(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local production source as a contract fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-conversion-map.php' );

		$this->assertStringContainsString( 'stream_since', $source );
		$this->assertStringContainsString( 'return_observation_days', $source );
		$this->assertStringContainsString( 'first eligible, mature entry journey per visitor', $source );
	}
}
