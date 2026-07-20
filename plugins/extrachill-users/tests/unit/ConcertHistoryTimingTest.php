<?php
/**
 * Tests for canonical concert history timing.
 *
 * @package ExtraChill\Users
 */

class Test_Concert_History_Timing extends WP_UnitTestCase {

	private int $events_blog_id;

	private int $user_id;

	private string $dates_table;

	private string $now = '2026-07-19 12:00:00';

	/** @var array<string, int> */
	private array $events = array();

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->events_blog_id = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();
		update_user_meta( $this->user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, 'public' );
		$this->dates_table    = $wpdb->get_blog_prefix( $this->events_blog_id ) . 'datamachine_event_dates';

		update_blog_option( $this->events_blog_id, 'timezone_string', 'America/New_York' );
		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		add_filter( 'extrachill_users_event_timing_now', array( $this, 'filter_timing_now' ), 10, 2 );

		extrachill_users_install_concert_tracking_table();
		$wpdb->query(
			"CREATE TABLE {$this->dates_table} (
				post_id BIGINT UNSIGNED NOT NULL,
				start_datetime DATETIME NOT NULL,
				end_datetime DATETIME DEFAULT NULL,
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (post_id),
				KEY start_datetime (start_datetime),
				KEY end_datetime (end_datetime)
			) {$wpdb->get_charset_collate()}"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only table name and schema.

		switch_to_blog( $this->events_blog_id );
		register_post_type( 'data_machine_events', array( 'public' => true ) );
		register_taxonomy( 'artist', 'data_machine_events', array( 'public' => true ) );
		register_taxonomy( 'venue', 'data_machine_events', array( 'public' => true ) );
		register_taxonomy( 'location', 'data_machine_events', array( 'public' => true ) );
		restore_current_blog();

		$this->events = array(
			'later_today'  => $this->track_event( 'Later Today', '2026-07-19 18:00:00' ),
			'ended_today'  => $this->track_event( 'Ended Today', '2026-07-19 08:00:00', '2026-07-19 10:00:00' ),
			'overnight'    => $this->track_event( 'Overnight', '2026-07-18 23:00:00', '2026-07-19 13:00:00' ),
			'multi_day'    => $this->track_event( 'Multi-day', '2026-07-17 18:00:00', '2026-07-20 01:00:00' ),
			'missing_end'  => $this->track_event( 'Missing End', '2026-07-19 10:00:00' ),
			'exact_start'  => $this->track_event( 'Exact Start', '2026-07-19 12:00:00' ),
			'exact_end'    => $this->track_event( 'Exact End', '2026-07-19 11:00:00', '2026-07-19 12:00:00' ),
			'previous_year' => $this->track_event( 'Previous Year', '2025-06-01 20:00:00', '2025-06-01 23:00:00' ),
		);
	}

	protected function tearDown(): void {
		global $wpdb;

		remove_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		remove_filter( 'extrachill_users_event_timing_now', array( $this, 'filter_timing_now' ), 10 );
		wp_set_current_user( 0 );
		$wpdb->query( "DROP TABLE IF EXISTS {$this->dates_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only table name.

		parent::tearDown();
	}

	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
	}

	public function filter_timing_now( string $now, int $blog_id ): string {
		$this->assertSame( $this->events_blog_id, $blog_id );

		return $this->now;
	}

	public function test_tabs_use_canonical_timing_for_rows_totals_pages_and_years(): void {
		$upcoming_page_one = ec_users_get_user_events(
			$this->user_id,
			array(
				'period'   => 'upcoming',
				'page'     => 1,
				'per_page' => 2,
			)
		);
		$upcoming_page_three = ec_users_get_user_events(
			$this->user_id,
			array(
				'period'   => 'upcoming',
				'page'     => 3,
				'per_page' => 2,
			)
		);
		$upcoming_all        = ec_users_get_user_events( $this->user_id, array( 'period' => 'upcoming' ) );
		$past                = ec_users_get_user_events( $this->user_id, array( 'period' => 'past' ) );

		$this->assertSame( 5, $upcoming_page_one['total'] );
		$this->assertSame( 3, $upcoming_page_one['pages'] );
		$this->assertCount( 2, $upcoming_page_one['shows'] );
		$this->assertSame( 5, $upcoming_page_three['total'] );
		$this->assertSame( 3, $upcoming_page_three['pages'] );
		$this->assertCount( 1, $upcoming_page_three['shows'] );
		$this->assertNotContains( 'past', wp_list_pluck( $upcoming_all['shows'], 'timing' ) );
		$this->assertContains( 'ongoing', wp_list_pluck( $upcoming_all['shows'], 'timing' ) );

		$this->assertSame( 3, $past['total'] );
		$this->assertSame( array( 'past' ), array_values( array_unique( wp_list_pluck( $past['shows'], 'timing' ) ) ) );
		$this->assertNotContains( $this->events['overnight'], wp_list_pluck( $past['shows'], 'event_id' ) );
		$this->assertNotContains( $this->events['multi_day'], wp_list_pluck( $past['shows'], 'event_id' ) );

		$stats = ec_users_get_user_concert_stats( $this->user_id );
		$this->assertSame( 8, $stats['total_shows'] );
		$this->assertSame( array( '2026' => 7, '2025' => 1 ), $stats['shows_by_year'] );
		$this->assertSame( 7, ec_users_get_user_concert_stats( $this->user_id, array( 'year' => 2026 ) )['total_shows'] );
	}

	public function test_upcoming_only_owner_stats_remain_nonzero_with_future_year_option(): void {
		$owner_id = self::factory()->user->create();
		$this->track_event( 'Future Owner Show', '2027-02-10 20:00:00', null, $owner_id );
		wp_set_current_user( $owner_id );

		$stats = wp_get_ability( 'extrachill/get-user-concert-stats' )->execute( array( 'user_id' => $owner_id ) );

		$this->assertSame( 1, $stats['total_shows'] );
		$this->assertSame( array( '2027' => 1 ), $stats['shows_by_year'] );
		$this->assertSame( 1, wp_get_ability( 'extrachill/get-user-shows' )->execute( array( 'user_id' => $owner_id, 'period' => 'upcoming' ) )['total'] );
	}

	public function test_ongoing_only_owner_stats_remain_nonzero(): void {
		$owner_id = self::factory()->user->create();
		$this->track_event( 'Ongoing Owner Show', '2026-07-18 20:00:00', '2026-07-20 01:00:00', $owner_id );
		wp_set_current_user( $owner_id );

		$stats = wp_get_ability( 'extrachill/get-user-concert-stats' )->execute( array( 'user_id' => $owner_id ) );

		$this->assertSame( 1, $stats['total_shows'] );
		$this->assertSame( array( '2026' => 1 ), $stats['shows_by_year'] );
		$this->assertSame( 1, wp_get_ability( 'extrachill/get-user-shows' )->execute( array( 'user_id' => $owner_id, 'period' => 'upcoming' ) )['total'] );
	}

	public function test_owner_stats_and_year_filter_include_mixed_past_and_future_years(): void {
		$owner_id = self::factory()->user->create();
		$this->track_event( 'Past Mixed Show', '2024-05-10 20:00:00', '2024-05-10 23:00:00', $owner_id );
		$this->track_event( 'Future Mixed Show', '2027-05-10 20:00:00', null, $owner_id );
		wp_set_current_user( $owner_id );

		$stats        = wp_get_ability( 'extrachill/get-user-concert-stats' )->execute( array( 'user_id' => $owner_id ) );
		$future_stats = wp_get_ability( 'extrachill/get-user-concert-stats' )->execute(
			array(
				'user_id' => $owner_id,
				'year'    => 2027,
			)
		);

		$this->assertSame( 2, $stats['total_shows'] );
		$this->assertSame( array( '2027' => 1, '2024' => 1 ), $stats['shows_by_year'] );
		$this->assertSame( 1, $future_stats['total_shows'] );
		$this->assertSame( array( '2027' => 1 ), $future_stats['shows_by_year'] );
	}

	public function test_public_date_bounded_stats_remain_canonically_past_only(): void {
		$owner_id = self::factory()->user->create();
		update_user_meta( $owner_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, 'public' );
		$this->track_event( 'Public Past Show', '2025-05-10 20:00:00', '2025-05-10 23:00:00', $owner_id );
		$this->track_event( 'Public Ongoing Show', '2026-07-18 20:00:00', '2026-07-20 01:00:00', $owner_id );
		$this->track_event( 'Public Future Show', '2027-05-10 20:00:00', null, $owner_id );
		wp_set_current_user( 0 );

		$stats = wp_get_ability( 'extrachill/get-user-concert-stats' )->execute(
			array(
				'user_id' => $owner_id,
				'date_to' => '2026-07-19',
			)
		);

		$this->assertSame( 1, $stats['total_shows'] );
		$this->assertSame( array( '2025' => 1 ), $stats['shows_by_year'] );
	}

	public function test_public_stats_are_past_only_without_trusting_date_to(): void {
		$owner_id = self::factory()->user->create();
		update_user_meta( $owner_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, 'public' );
		$this->track_event( 'Public Past', '2025-05-10 20:00:00', '2025-05-10 23:00:00', $owner_id );
		$this->track_event( 'Public Future', '2027-05-10 20:00:00', null, $owner_id );
		wp_set_current_user( 0 );

		$unbounded = wp_get_ability( 'extrachill/get-user-concert-stats' )->execute( array( 'user_id' => $owner_id ) );
		$widened   = wp_get_ability( 'extrachill/get-user-concert-stats' )->execute(
			array(
				'user_id' => $owner_id,
				'date_to' => '2099-12-31',
			)
		);

		$this->assertSame( 1, $unbounded['total_shows'] );
		$this->assertSame( 1, $widened['total_shows'] );
		$this->assertSame( array( '2025' => 1 ), $widened['shows_by_year'] );
	}

	public function test_private_history_returns_forbidden_but_owner_and_admin_keep_full_access(): void {
		$owner_id = self::factory()->user->create();
		$this->track_event( 'Private Past', '2025-05-10 20:00:00', '2025-05-10 23:00:00', $owner_id );
		$this->track_event( 'Private Future', '2027-05-10 20:00:00', null, $owner_id );
		extrachill_users_set_concert_history_visibility( $owner_id, 'private' );

		wp_set_current_user( 0 );
		foreach ( array( 'extrachill/get-user-shows', 'extrachill/get-user-concert-stats' ) as $ability_name ) {
			$result = wp_get_ability( $ability_name )->execute( array( 'user_id' => $owner_id ) );
			$this->assertWPError( $result );
			$this->assertSame( 'concert_history_private', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}

		wp_set_current_user( $owner_id );
		$this->assertSame( 2, wp_get_ability( 'extrachill/get-user-concert-stats' )->execute( array( 'user_id' => $owner_id ) )['total_shows'] );

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $administrator_id );
		wp_set_current_user( $administrator_id );
		$this->assertSame( 2, wp_get_ability( 'extrachill/get-user-shows' )->execute( array( 'user_id' => $owner_id ) )['total'] );
		revoke_super_admin( $administrator_id );
	}

	public function test_exact_boundaries_and_missing_end_match_canonical_states(): void {
		$past_condition = ec_users_build_event_timing_condition( 'past', $this->now );
		$this->assertStringContainsString( 'start_datetime < %s', $past_condition['where'] );
		$this->assertStringContainsString( 'COALESCE(ed.end_datetime, ed.start_datetime) < %s', $past_condition['where'] );
		$this->assertStringNotContainsString( ' OR ', $past_condition['where'] );

		$this->assertSame( 'upcoming', ec_users_get_event_timing( $this->events['later_today'] ) );
		$this->assertSame( 'past', ec_users_get_event_timing( $this->events['ended_today'] ) );
		$this->assertSame( 'ongoing', ec_users_get_event_timing( $this->events['overnight'] ) );
		$this->assertSame( 'ongoing', ec_users_get_event_timing( $this->events['multi_day'] ) );
		$this->assertSame( 'past', ec_users_get_event_timing( $this->events['missing_end'] ) );
		$this->assertSame( 'upcoming', ec_users_get_event_timing( $this->events['exact_start'] ) );
		$this->assertSame( 'ongoing', ec_users_get_event_timing( $this->events['exact_end'] ) );

		$this->assertSame( 0, ec_users_search_events_for_marking( $this->user_id, array( 'query' => 'Overnight' ) )['total'] );
		$past_search = ec_users_search_events_for_marking( $this->user_id, array( 'query' => 'Ended Today' ) );
		$this->assertSame( 1, $past_search['total'] );
		$this->assertSame( 'past', $past_search['events'][0]['timing'] );
	}

	public function test_dst_boundary_uses_events_site_local_time(): void {
		$this->now = '2026-03-08 03:00:00';
		$event_id  = $this->track_event( 'DST Boundary', '2026-03-08 01:30:00', '2026-03-08 03:30:00' );

		$this->assertSame( 'ongoing', ec_users_get_event_timing( $event_id ) );
		$this->assertContains(
			$event_id,
			wp_list_pluck( ec_users_get_user_events( $this->user_id, array( 'period' => 'upcoming' ) )['shows'], 'event_id' )
		);
		$this->assertNotContains(
			$event_id,
			wp_list_pluck( ec_users_get_user_events( $this->user_id, array( 'period' => 'past' ) )['shows'], 'event_id' )
		);
	}

	public function test_events_now_uses_events_timezone_and_restores_calling_blog(): void {
		remove_filter( 'extrachill_users_event_timing_now', array( $this, 'filter_timing_now' ), 10 );
		$original_blog_id = get_current_blog_id();
		$before           = time();
		$events_now       = ec_users_get_events_now( $this->events_blog_id );
		$after            = time();
		$timezone         = new DateTimeZone( 'America/New_York' );
		$parsed           = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $events_now, $timezone );

		$this->assertSame( $original_blog_id, get_current_blog_id() );
		$this->assertInstanceOf( DateTimeImmutable::class, $parsed );
		$this->assertGreaterThanOrEqual( $before, $parsed->getTimestamp() );
		$this->assertLessThanOrEqual( $after, $parsed->getTimestamp() );

		add_filter( 'extrachill_users_event_timing_now', array( $this, 'filter_timing_now' ), 10, 2 );
	}

	public function test_public_non_owner_ability_remains_past_only(): void {
		$ability = wp_get_ability( 'extrachill/get-user-shows' );
		wp_set_current_user( 0 );

		$public_upcoming = $ability->execute(
			array(
				'user_id' => $this->user_id,
				'period'  => 'upcoming',
			)
		);
		$public_all      = $ability->execute(
			array(
				'user_id' => $this->user_id,
				'period'  => 'all',
			)
		);

		$this->assertSame( 0, $public_upcoming['total'] );
		$this->assertSame( 3, $public_all['total'] );
		$this->assertSame( array( 'past' ), array_values( array_unique( wp_list_pluck( $public_all['shows'], 'timing' ) ) ) );

		wp_set_current_user( $this->user_id );
		$owner_upcoming = $ability->execute(
			array(
				'user_id' => $this->user_id,
				'period'  => 'upcoming',
			)
		);
		$this->assertSame( 5, $owner_upcoming['total'] );
		$this->assertNotContains( 'past', wp_list_pluck( $owner_upcoming['shows'], 'timing' ) );
	}

	private function track_event( string $title, string $start_datetime, ?string $end_datetime = null, ?int $user_id = null ): int {
		global $wpdb;

		switch_to_blog( $this->events_blog_id );
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'data_machine_events',
			)
		);
		restore_current_blog();

		$wpdb->replace(
			$this->dates_table,
			array(
				'post_id'        => $post_id,
				'start_datetime' => $start_datetime,
				'end_datetime'   => $end_datetime,
				'post_status'    => 'publish',
			),
			array( '%d', '%s', '%s', '%s' )
		);
		ec_users_mark_event( $user_id ? $user_id : $this->user_id, $post_id, $this->events_blog_id );

		return $post_id;
	}
}
