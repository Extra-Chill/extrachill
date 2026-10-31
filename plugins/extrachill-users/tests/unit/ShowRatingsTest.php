<?php
/**
 * Tests for private, personal show ratings on the attendance record.
 *
 * @package ExtraChill\Users
 */

use DataMachineEvents\Core\EventDatesTable;

/**
 * A fan rates shows they attended; only they ever read the rating.
 */
class Test_Show_Ratings extends WP_UnitTestCase {

	private int $events_blog_id;

	private int $past_id;

	private int $upcoming_id;

	private int $user_id;

	protected function setUp(): void {
		parent::setUp();
		$this->events_blog_id = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();

		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		add_filter( 'extrachill_users_event_timing_now', array( $this, 'filter_event_timing_now' ) );
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type( 'data_machine_events', array( 'public' => true ) );
		}

		switch_to_blog( $this->events_blog_id );
		try {
			EventDatesTable::create_table();
			$this->past_id     = $this->create_event( '2026-07-20 20:00:00' );
			$this->upcoming_id = $this->create_event( '2026-07-30 20:00:00' );
		} finally {
			restore_current_blog();
		}

		extrachill_users_install_concert_tracking_table();
		$this->clear_tracking_table();
		wp_set_current_user( $this->user_id );
	}

	protected function tearDown(): void {
		$this->clear_tracking_table();
		remove_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		remove_filter( 'extrachill_users_event_timing_now', array( $this, 'filter_event_timing_now' ) );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
	}

	public function filter_event_timing_now(): string {
		return '2026-07-24 12:00:00';
	}

	public function test_rate_show_sets_updates_and_clears_a_rating(): void {
		ec_users_mark_event( $this->user_id, $this->past_id, $this->events_blog_id );
		$rate = wp_get_ability( 'extrachill/rate-show' );
		$read = wp_get_ability( 'extrachill/get-my-show-ratings' );

		$this->assertSame(
			array(
				'event_id' => $this->past_id,
				'rating'   => 4,
			),
			$rate->execute( $this->rate_input( $this->past_id, 4 ) )
		);
		$this->assertSame( array( $this->past_id => 4 ), $this->my_ratings( $read, array( $this->past_id ) ) );

		$rate->execute( $this->rate_input( $this->past_id, 2 ) );
		$this->assertSame( array( $this->past_id => 2 ), $this->my_ratings( $read, array( $this->past_id ) ) );

		$cleared = $rate->execute( $this->rate_input( $this->past_id, 0 ) );
		$this->assertSame( 0, $cleared['rating'] );
		$this->assertSame( array(), $this->my_ratings( $read, array( $this->past_id ) ) );
	}

	public function test_rate_show_requires_attending_the_show(): void {
		$result = wp_get_ability( 'extrachill/rate-show' )->execute( $this->rate_input( $this->past_id, 5 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'show_not_attended', $result->get_error_code() );
	}

	public function test_rate_show_rejects_upcoming_shows(): void {
		ec_users_mark_event( $this->user_id, $this->upcoming_id, $this->events_blog_id );
		$result = wp_get_ability( 'extrachill/rate-show' )->execute( $this->rate_input( $this->upcoming_id, 5 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'show_not_past', $result->get_error_code() );
	}

	public function test_rate_show_rejects_out_of_range_ratings(): void {
		ec_users_mark_event( $this->user_id, $this->past_id, $this->events_blog_id );
		$rate = wp_get_ability( 'extrachill/rate-show' );

		foreach ( array( -1, 6 ) as $value ) {
			$result = $rate->execute( $this->rate_input( $this->past_id, $value ) );
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		}

		$this->assertWPError( ec_users_set_show_rating( $this->user_id, $this->past_id, $this->events_blog_id, 9 ) );
	}

	public function test_rating_abilities_require_login(): void {
		wp_set_current_user( 0 );

		$rate = wp_get_ability( 'extrachill/rate-show' )->execute( $this->rate_input( $this->past_id, 5 ) );
		$read = wp_get_ability( 'extrachill/get-my-show-ratings' )->execute( array( 'event_ids' => array( $this->past_id ) ) );

		$this->assertSame( 'ability_invalid_permissions', $rate->get_error_code() );
		$this->assertSame( 'ability_invalid_permissions', $read->get_error_code() );
	}

	public function test_ratings_are_only_readable_by_their_owner(): void {
		ec_users_mark_event( $this->user_id, $this->past_id, $this->events_blog_id );
		ec_users_set_show_rating( $this->user_id, $this->past_id, $this->events_blog_id, 5 );

		$other_id = self::factory()->user->create();
		ec_users_mark_event( $other_id, $this->past_id, $this->events_blog_id );
		wp_set_current_user( $other_id );

		$read = wp_get_ability( 'extrachill/get-my-show-ratings' );
		$this->assertSame( array(), $this->my_ratings( $read, array( $this->past_id ) ) );
		$this->assertArrayNotHasKey( 'user_id', $read->get_input_schema()['properties'] );
		$this->assertArrayNotHasKey( 'user_id', wp_get_ability( 'extrachill/rate-show' )->get_input_schema()['properties'] );
	}

	public function test_public_show_history_never_includes_ratings(): void {
		ec_users_mark_event( $this->user_id, $this->past_id, $this->events_blog_id );
		ec_users_set_show_rating( $this->user_id, $this->past_id, $this->events_blog_id, 5 );

		$owner_view  = wp_json_encode( ec_users_get_user_events( $this->user_id, array( 'period' => 'past' ) ) );
		$stats_view  = wp_json_encode( ec_users_get_user_concert_stats( $this->user_id ) );
		$attendance  = wp_json_encode( ec_users_get_event_attendees_full( $this->past_id, $this->events_blog_id ) );
		$this->assertStringNotContainsString( 'rating', $owner_view );
		$this->assertStringNotContainsString( 'rating', $stats_view );
		$this->assertStringNotContainsString( 'rating', $attendance );
	}

	public function test_unmarking_a_show_deletes_its_rating(): void {
		ec_users_mark_event( $this->user_id, $this->past_id, $this->events_blog_id );
		ec_users_set_show_rating( $this->user_id, $this->past_id, $this->events_blog_id, 3 );
		ec_users_unmark_event( $this->user_id, $this->past_id, $this->events_blog_id );
		ec_users_mark_event( $this->user_id, $this->past_id, $this->events_blog_id );

		$this->assertSame( array(), ec_users_get_show_ratings( $this->user_id, array( $this->past_id ), $this->events_blog_id ) );
	}

	private function create_event( string $start ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $id, $start, null, 'publish' );
		return $id;
	}

	private function rate_input( int $event_id, int $rating ): array {
		return array(
			'event_id' => $event_id,
			'rating'   => $rating,
			'blog_id'  => $this->events_blog_id,
		);
	}

	private function my_ratings( WP_Ability $read, array $event_ids ): array {
		$result = $read->execute(
			array(
				'event_ids' => $event_ids,
				'blog_id'   => $this->events_blog_id,
			)
		);
		$this->assertFalse( is_wp_error( $result ) );
		return (array) $result['ratings'];
	}

	private function clear_tracking_table(): void {
		global $wpdb;
		$table = extrachill_users_concert_tracking_table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
	}
}
