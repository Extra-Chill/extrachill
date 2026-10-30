<?php
/**
 * Tests for cross-site content purge (moderation).
 *
 * Covers issue #383: purging from a different site's request context must not
 * emit map_meta_cap _doing_it_wrong notices for unregistered post types, and
 * must recompute bbPress denormalized counters on parent topics/forums.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify purge behavior in a switched-blog (plugin-less) context.
 */
class Test_Moderation_Content_Purge extends WP_UnitTestCase {

	/**
	 * Number of map_meta_cap _doing_it_wrong notices observed during a test.
	 *
	 * @var int
	 */
	private int $map_meta_cap_notices = 0;

	/**
	 * Track map_meta_cap misuse notices and simulate hooked-plugin cap checks.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->map_meta_cap_notices = 0;

		add_action( 'doing_it_wrong_run', array( $this, 'record_doing_it_wrong' ), 10, 3 );

		// Network plugins loaded in the CLI request context perform
		// current_user_can() checks against the post being deleted (that is
		// what surfaced issue #383 on production). Simulate that deterministically.
		add_action( 'before_delete_post', array( $this, 'simulate_plugin_cap_check' ) );
	}

	/**
	 * Remove test hooks.
	 */
	public function tear_down(): void {
		remove_action( 'doing_it_wrong_run', array( $this, 'record_doing_it_wrong' ), 10, 3 );
		remove_action( 'before_delete_post', array( $this, 'simulate_plugin_cap_check' ) );
		parent::tear_down();
	}

	/**
	 * Simulate a hooked plugin's capability check against the deleted post.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public function simulate_plugin_cap_check( $post_id ): void {
		current_user_can( 'delete_post', (int) $post_id );
	}

	/**
	 * Count map_meta_cap notices.
	 *
	 * @param string $function_name Function flagged by _doing_it_wrong().
	 * @return void
	 */
	public function record_doing_it_wrong( string $function_name ): void {
		if ( 'map_meta_cap' === $function_name ) {
			++$this->map_meta_cap_notices;
		}
	}

	/**
	 * Purging a post of an unregistered CPT from blog 1 context is silent.
	 */
	public function test_purge_of_unregistered_post_type_emits_no_map_meta_cap_notices(): void {
		$blog_id = $this->get_purge_test_blog();
		if ( null === $blog_id ) {
			$this->markTestSkipped( 'Multisite blog creation is unavailable.' );
		}

		$user_id = self::factory()->user->create();

		switch_to_blog( $blog_id );
		register_post_type( 'ec_purge_fixture', array( 'public' => true ) );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'ec_purge_fixture',
				'post_author' => $user_id,
			)
		);
		// Mirror production: the type is not registered in the purging request.
		unregister_post_type( 'ec_purge_fixture' );
		restore_current_blog();

		$result = extrachill_users_purge_user_content( $user_id );

		$this->assertSame( 0, $this->map_meta_cap_notices, 'Purge emitted map_meta_cap notices.' );
		$this->assertSame( 1, $result['posts'] );

		switch_to_blog( $blog_id );
		$this->assertNull( get_post( $post_id ) );
		restore_current_blog();
	}

	/**
	 * Purging a reply recomputes the parent topic's and forum's counters.
	 */
	public function test_purge_recomputes_bbp_topic_and_forum_counters(): void {
		$blog_id = $this->get_purge_test_blog();
		if ( null === $blog_id ) {
			$this->markTestSkipped( 'Multisite blog creation is unavailable.' );
		}

		$user_id  = self::factory()->user->create();
		$other_id = self::factory()->user->create();

		switch_to_blog( $blog_id );
		register_post_type( 'forum', array( 'public' => true ) );
		register_post_type( 'topic', array( 'public' => true ) );
		register_post_type( 'reply', array( 'public' => true ) );

		$forum_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'forum',
				'post_status' => 'publish',
				'post_author' => $other_id,
			)
		);
		$topic_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'topic',
				'post_status' => 'publish',
				'post_parent' => $forum_id,
				'post_author' => $other_id,
				'post_date'   => '2026-01-01 10:00:00',
			)
		);

		$r1 = (int) self::factory()->post->create(
			array(
				'post_type'   => 'reply',
				'post_status' => 'publish',
				'post_parent' => $topic_id,
				'post_author' => $other_id,
				'post_date'   => '2026-01-02 10:00:00',
			)
		);
		// The purged user's reply: currently the topic's newest activity.
		$r2 = (int) self::factory()->post->create(
			array(
				'post_type'   => 'reply',
				'post_status' => 'publish',
				'post_parent' => $topic_id,
				'post_author' => $user_id,
				'post_date'   => '2026-01-03 10:00:00',
			)
		);
		$r3 = (int) self::factory()->post->create(
			array(
				'post_type'   => 'reply',
				'post_status' => 'publish',
				'post_parent' => $topic_id,
				'post_author' => $other_id,
				'post_date'   => '2026-01-04 10:00:00',
			)
		);

		// bbPress hierarchy meta mirrors, as bbPress maintains them.
		update_post_meta( $topic_id, '_bbp_forum_id', $forum_id );
		foreach ( array( $r1, $r2, $r3 ) as $reply_id ) {
			update_post_meta( $reply_id, '_bbp_topic_id', $topic_id );
			update_post_meta( $reply_id, '_bbp_forum_id', $forum_id );
		}

		// Stale denormalized counters including the reply about to be purged.
		update_post_meta( $topic_id, '_bbp_reply_count', 3 );
		update_post_meta( $topic_id, '_bbp_reply_count_hidden', 0 );
		update_post_meta( $topic_id, '_bbp_last_reply_id', $r2 );
		update_post_meta( $topic_id, '_bbp_last_active_id', $r2 );
		update_post_meta( $topic_id, '_bbp_last_active_time', '2026-01-03 10:00:00' );

		update_post_meta( $forum_id, '_bbp_topic_count', 1 );
		update_post_meta( $forum_id, '_bbp_topic_count_hidden', 0 );
		update_post_meta( $forum_id, '_bbp_reply_count', 3 );
		update_post_meta( $forum_id, '_bbp_reply_count_hidden', 0 );
		update_post_meta( $forum_id, '_bbp_total_topic_count', 1 );
		update_post_meta( $forum_id, '_bbp_total_reply_count', 3 );
		update_post_meta( $forum_id, '_bbp_last_topic_id', $topic_id );
		update_post_meta( $forum_id, '_bbp_last_reply_id', $r2 );
		update_post_meta( $forum_id, '_bbp_last_active_id', $r2 );
		update_post_meta( $forum_id, '_bbp_last_active_time', '2026-01-03 10:00:00' );

		// Mirror production: bbPress types are not registered while purging.
		unregister_post_type( 'forum' );
		unregister_post_type( 'topic' );
		unregister_post_type( 'reply' );
		restore_current_blog();

		$result = extrachill_users_purge_user_content( $user_id );

		$this->assertSame( 0, $this->map_meta_cap_notices, 'Purge emitted map_meta_cap notices.' );
		$this->assertSame( 1, $result['posts'] );

		switch_to_blog( $blog_id );
		$this->assertNull( get_post( $r2 ), 'The purged reply still exists.' );

		// Topic counters: two remaining public replies, newest is r3.
		$this->assertSame( 2, (int) get_post_meta( $topic_id, '_bbp_reply_count', true ) );
		$this->assertSame( 0, (int) get_post_meta( $topic_id, '_bbp_reply_count_hidden', true ) );
		$this->assertSame( $r3, (int) get_post_meta( $topic_id, '_bbp_last_reply_id', true ) );
		$this->assertSame( $r3, (int) get_post_meta( $topic_id, '_bbp_last_active_id', true ) );
		$this->assertSame( '2026-01-04 10:00:00', get_post_meta( $topic_id, '_bbp_last_active_time', true ) );

		// Forum counters: the reply's forum ancestry was captured pre-delete.
		$this->assertSame( 1, (int) get_post_meta( $forum_id, '_bbp_topic_count', true ) );
		$this->assertSame( 2, (int) get_post_meta( $forum_id, '_bbp_reply_count', true ) );
		$this->assertSame( 1, (int) get_post_meta( $forum_id, '_bbp_total_topic_count', true ) );
		$this->assertSame( 2, (int) get_post_meta( $forum_id, '_bbp_total_reply_count', true ) );
		$this->assertSame( $topic_id, (int) get_post_meta( $forum_id, '_bbp_last_topic_id', true ) );
		$this->assertSame( $r3, (int) get_post_meta( $forum_id, '_bbp_last_reply_id', true ) );
		$this->assertSame( $r3, (int) get_post_meta( $forum_id, '_bbp_last_active_id', true ) );
		$this->assertSame( '2026-01-04 10:00:00', get_post_meta( $forum_id, '_bbp_last_active_time', true ) );
		restore_current_blog();
	}

	/**
	 * Purging a whole topic zeroes the parent forum's pointers and counts.
	 */
	public function test_purge_of_topic_zeroes_forum_pointers(): void {
		$blog_id = $this->get_purge_test_blog();
		if ( null === $blog_id ) {
			$this->markTestSkipped( 'Multisite blog creation is unavailable.' );
		}

		$user_id  = self::factory()->user->create();
		$other_id = self::factory()->user->create();

		switch_to_blog( $blog_id );
		register_post_type( 'forum', array( 'public' => true ) );
		register_post_type( 'topic', array( 'public' => true ) );

		$forum_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'forum',
				'post_status' => 'publish',
				'post_author' => $other_id,
			)
		);
		$topic_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'topic',
				'post_status' => 'publish',
				'post_parent' => $forum_id,
				'post_author' => $user_id,
				'post_date'   => '2026-02-01 10:00:00',
			)
		);

		update_post_meta( $topic_id, '_bbp_forum_id', $forum_id );
		update_post_meta( $forum_id, '_bbp_topic_count', 1 );
		update_post_meta( $forum_id, '_bbp_total_topic_count', 1 );
		update_post_meta( $forum_id, '_bbp_last_topic_id', $topic_id );
		update_post_meta( $forum_id, '_bbp_last_reply_id', $topic_id );
		update_post_meta( $forum_id, '_bbp_last_active_id', $topic_id );
		update_post_meta( $forum_id, '_bbp_last_active_time', '2026-02-01 10:00:00' );

		unregister_post_type( 'forum' );
		unregister_post_type( 'topic' );
		restore_current_blog();

		$result = extrachill_users_purge_user_content( $user_id );

		$this->assertSame( 0, $this->map_meta_cap_notices, 'Purge emitted map_meta_cap notices.' );
		$this->assertSame( 1, $result['posts'] );

		switch_to_blog( $blog_id );
		$this->assertNull( get_post( $topic_id ), 'The purged topic still exists.' );
		$this->assertSame( 0, (int) get_post_meta( $forum_id, '_bbp_topic_count', true ) );
		$this->assertSame( 0, (int) get_post_meta( $forum_id, '_bbp_total_topic_count', true ) );
		$this->assertSame( 0, (int) get_post_meta( $forum_id, '_bbp_last_topic_id', true ) );
		$this->assertSame( 0, (int) get_post_meta( $forum_id, '_bbp_last_reply_id', true ) );
		$this->assertSame( 0, (int) get_post_meta( $forum_id, '_bbp_last_active_id', true ) );
		restore_current_blog();
	}

	/**
	 * Create (or reuse) a second blog for purge fixtures.
	 *
	 * @return int|null Blog ID, or null when multisite is unavailable.
	 */
	private function get_purge_test_blog(): ?int {
		if ( ! is_multisite() || ! function_exists( 'wpmu_create_blog' ) ) {
			return null;
		}

		$domain   = 'purgetest.example.org';
		$existing = get_sites(
			array(
				'domain' => $domain,
				'path'   => '/',
				'number' => 1,
				'fields' => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return (int) reset( $existing );
		}

		$blog_id = wpmu_create_blog( $domain, '/', 'Purge Test', 1, array( 'public' => 1 ), 1 );

		return is_wp_error( $blog_id ) ? null : (int) $blog_id;
	}
}
