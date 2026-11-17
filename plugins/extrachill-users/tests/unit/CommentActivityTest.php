<?php
/**
 * Comments as network activity: rank points + dated contribution events (#1634).
 *
 * @package ExtraChill\Users
 */

require_once dirname( __DIR__ ) . '/support/fixtures.php';

/**
 * Verify approved comments on the hosting sites feed the rank engine and heatmap.
 */
class Test_Comment_Activity extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $main_blog_id;
	private int $events_blog_id;
	private int $other_blog_id;
	private int $user_id;
	private int $switches = 0;

	public static function wpSetUpBeforeClass(): void {
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'main' ) );
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'events' ) );
		ec_users_tests_ensure_site( (int) ec_get_blog_id( 'community' ) );
	}

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Comment activity spans sites and requires multisite.' );
		}

		$this->main_blog_id   = (int) ec_get_blog_id( 'main' );
		$this->events_blog_id = (int) ec_get_blog_id( 'events' );
		$this->other_blog_id  = (int) self::factory()->blog->create();
		$this->user_id        = (int) self::factory()->user->create();

		if ( ! get_site( $this->events_blog_id ) ) {
			$this->markTestSkipped( 'The events site could not be provisioned.' );
		}
	}

	protected function tearDown(): void {
		remove_action( 'switch_blog', array( $this, 'count_switch' ), 10 );
		parent::tearDown();
	}

	public function count_switch( $new_blog_id, $prev_blog_id, $context = 'switch' ): void {
		if ( 'switch' === $context ) {
			++$this->switches;
		}
	}

	/**
	 * Insert a comment on a fresh post in the given blog.
	 *
	 * @param int    $blog_id  Blog ID.
	 * @param array  $args     Comment overrides.
	 * @param string $date_gmt Comment UTC date.
	 * @return int Comment ID.
	 */
	private function comment_on( int $blog_id, array $args = array(), string $date_gmt = '2026-03-10 15:00:00' ): int {
		switch_to_blog( $blog_id );
		try {
			$post_id = (int) self::factory()->post->create();
			return (int) self::factory()->comment->create(
				array_merge(
					array(
						'comment_post_ID'  => $post_id,
						'user_id'          => $this->user_id,
						'comment_approved' => 1,
						'comment_type'     => 'comment',
						'comment_date'     => $date_gmt,
						'comment_date_gmt' => $date_gmt,
					),
					$args
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	public function test_default_hosting_sites_are_main_and_events(): void {
		$this->assertSame( array( $this->main_blog_id, $this->events_blog_id ), ec_users_get_comment_activity_blog_ids() );
		$this->assertTrue( ec_users_is_comment_activity_blog( $this->events_blog_id ) );
		$this->assertFalse( ec_users_is_comment_activity_blog( $this->other_blog_id ) );
	}

	public function test_hosting_site_list_is_filterable_and_sanitized(): void {
		$other  = $this->other_blog_id;
		$filter = static function ( $ids ) use ( $other ) {
			return array_merge( $ids, array( $other, (string) $other, 0, -3, 999999 ) );
		};
		add_filter( 'ec_comment_activity_blog_ids', $filter );

		$this->assertSame(
			array( $this->main_blog_id, $this->events_blog_id, $other ),
			ec_users_get_comment_activity_blog_ids()
		);
	}

	public function test_counts_only_approved_discussion_comments_on_hosting_sites(): void {
		$this->comment_on( $this->main_blog_id );
		$this->comment_on( $this->main_blog_id );
		$this->comment_on( $this->events_blog_id );

		// None of these count.
		$this->comment_on( $this->main_blog_id, array( 'comment_approved' => 0 ) );
		$this->comment_on( $this->main_blog_id, array( 'comment_approved' => 'spam' ) );
		$this->comment_on( $this->events_blog_id, array( 'comment_type' => 'pingback' ) );
		$this->comment_on( $this->events_blog_id, array( 'comment_type' => 'note' ) );
		$this->comment_on( $this->other_blog_id );
		$this->comment_on( $this->events_blog_id, array( 'user_id' => (int) self::factory()->user->create() ) );

		$this->assertSame( 3, ec_users_get_user_comment_activity_count( $this->user_id ) );
		$this->assertSame( 0, ec_users_get_user_comment_activity_count( 0 ) );
	}

	public function test_reads_do_not_switch_blogs(): void {
		$this->comment_on( $this->events_blog_id );

		$this->switches = 0;
		add_action( 'switch_blog', array( $this, 'count_switch' ), 10, 3 );

		$this->assertSame( 1, ec_users_get_user_comment_activity_count( $this->user_id ) );
		$this->assertCount( 1, ec_users_get_user_comment_activity_timestamps( $this->user_id ) );
		$this->assertSame( 0, $this->switches );
	}

	public function test_points_source_awards_one_point_per_comment_by_default(): void {
		$this->comment_on( $this->main_blog_id );
		$this->comment_on( $this->events_blog_id );

		$sources = apply_filters( 'ec_points_sources', array( 'existing' => 7 ), $this->user_id );
		$this->assertSame( 2, $sources['comments'] );
		$this->assertSame( 7, $sources['existing'] );

		delete_transient( 'user_points_' . $this->user_id );
		$this->assertSame( 2.0, extrachill_get_user_total_points( $this->user_id ) - $this->other_sources_total() );
	}

	public function test_points_weight_filter_applies(): void {
		$this->comment_on( $this->main_blog_id );
		$this->comment_on( $this->events_blog_id );

		$filter = static function ( $weight, $user_id ) {
			return 4;
		};
		add_filter( 'ec_users_comment_rank_points_per_comment', $filter, 10, 2 );

		$this->assertSame( 8, apply_filters( 'ec_points_sources', array(), $this->user_id )['comments'] );
	}

	public function test_contribution_events_bucket_comments_by_day(): void {
		$this->comment_on( $this->main_blog_id, array(), '2026-03-10 15:00:00' );
		$this->comment_on( $this->events_blog_id, array(), '2026-03-10 18:00:00' );
		$this->comment_on( $this->events_blog_id, array(), '2026-03-12 09:00:00' );
		$this->comment_on( $this->main_blog_id, array( 'comment_approved' => 0 ), '2026-03-11 09:00:00' );

		$comment_events = array_values(
			array_filter(
				ec_get_contribution_events( $this->user_id ),
				static function ( $event ) {
					return 'comment' === $event['type'];
				}
			)
		);

		$this->assertSame(
			array(
				array(
					'date'  => '2026-03-10',
					'type'  => 'comment',
					'count' => 2,
				),
				array(
					'date'  => '2026-03-12',
					'type'  => 'comment',
					'count' => 1,
				),
			),
			$comment_events
		);

		$since = ec_users_comments_contribution_events( array(), $this->user_id, '2026-03-11' );
		$this->assertSame( array( '2026-03-12' ), wp_list_pluck( $since, 'date' ) );
	}

	public function test_approval_change_busts_cached_points_on_hosting_and_community_sites(): void {
		$comment_id        = $this->comment_on( $this->events_blog_id, array( 'comment_approved' => 0 ) );
		$community_blog_id = (int) ec_get_blog_id( 'community' );
		$key               = 'user_points_' . $this->user_id;

		switch_to_blog( $community_blog_id );
		set_transient( $key, 99, HOUR_IN_SECONDS );
		set_transient( 'ec_contrib_calendar_' . $this->user_id, array( 'cached' ), HOUR_IN_SECONDS );
		restore_current_blog();

		switch_to_blog( $this->events_blog_id );
		try {
			set_transient( $key, 99, HOUR_IN_SECONDS );
			wp_set_comment_status( $comment_id, 'approve' );
			$this->assertFalse( get_transient( $key ) );
		} finally {
			restore_current_blog();
		}

		switch_to_blog( $community_blog_id );
		try {
			$this->assertFalse( get_transient( $key ) );
			$this->assertFalse( get_transient( 'ec_contrib_calendar_' . $this->user_id ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_approval_change_on_non_hosting_site_is_ignored(): void {
		$comment_id = $this->comment_on( $this->other_blog_id, array( 'comment_approved' => 0 ) );

		switch_to_blog( $this->other_blog_id );
		try {
			set_transient( 'user_points_' . $this->user_id, 99, HOUR_IN_SECONDS );
			wp_set_comment_status( $comment_id, 'approve' );
			$this->assertSame( 99, (int) get_transient( 'user_points_' . $this->user_id ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_recent_comment_activity_merges_hosting_sites_newest_first(): void {
		ec_users_flush_recent_comment_activity_cache();

		$article = $this->comment_on( $this->main_blog_id, array(), '2026-03-10 15:00:00' );
		$event   = $this->comment_on( $this->events_blog_id, array(), '2026-03-12 09:00:00' );
		$this->comment_on( $this->main_blog_id, array( 'comment_approved' => 0 ), '2026-03-13 09:00:00' );
		$this->comment_on( $this->events_blog_id, array( 'comment_type' => 'pingback' ), '2026-03-13 10:00:00' );
		$this->comment_on( $this->other_blog_id, array(), '2026-03-14 10:00:00' );

		$items = ec_users_get_recent_comment_activity( 20 );
		$this->assertSame( array( $event, $article ), wp_list_pluck( $items, 'comment_id' ) );
		$this->assertSame( array( $this->events_blog_id, $this->main_blog_id ), wp_list_pluck( $items, 'blog_id' ) );

		$item = $items[0];
		$this->assertSame(
			array( 'blog_id', 'comment_id', 'post_id', 'post_type', 'post_title', 'post_url', 'comment_url', 'user_id', 'author_name', 'date_gmt' ),
			array_keys( $item )
		);
		$this->assertSame( $item['post_url'] . '#comment-' . $event, $item['comment_url'] );
		$this->assertSame( $this->user_id, $item['user_id'] );
		$this->assertSame( '2026-03-12 09:00:00', $item['date_gmt'] );
		$this->assertCount( 1, ec_users_get_recent_comment_activity( 1 ) );
	}

	public function test_recent_comment_activity_cache_busts_on_approval(): void {
		ec_users_flush_recent_comment_activity_cache();
		$this->assertSame( array(), ec_users_get_recent_comment_activity( 20 ) );

		$comment_id = $this->comment_on( $this->events_blog_id, array( 'comment_approved' => 0 ) );
		$this->assertSame( array(), ec_users_get_recent_comment_activity( 20 ), 'The cached list is served until a status change.' );

		switch_to_blog( $this->events_blog_id );
		try {
			wp_set_comment_status( $comment_id, 'approve' );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( array( $comment_id ), wp_list_pluck( ec_users_get_recent_comment_activity( 20 ), 'comment_id' ) );
	}

	public function test_recent_comment_activity_skips_unpublished_and_protected_posts(): void {
		ec_users_flush_recent_comment_activity_cache();

		switch_to_blog( $this->main_blog_id );
		try {
			$draft     = (int) self::factory()->post->create( array( 'post_status' => 'draft' ) );
			$protected = (int) self::factory()->post->create( array( 'post_password' => 'secret' ) );
			foreach ( array( $draft, $protected ) as $post_id ) {
				self::factory()->comment->create(
					array(
						'comment_post_ID'  => $post_id,
						'user_id'          => $this->user_id,
						'comment_approved' => 1,
					)
				);
			}
		} finally {
			restore_current_blog();
		}

		$this->assertSame( array(), ec_users_get_recent_comment_activity( 20 ) );
	}

	/**
	 * Sum every registered source except comments, for an isolated delta.
	 */
	private function other_sources_total(): float {
		$sources = apply_filters( 'ec_points_sources', array(), $this->user_id );
		unset( $sources['comments'] );

		return (float) array_sum( $sources ) + (float) apply_filters( 'ec_rank_extra_points', 0.0, $this->user_id );
	}
}
