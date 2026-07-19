<?php
/**
 * Tests for public concert history event visibility.
 *
 * @package ExtraChill\Users
 */

class Test_Concert_History_Visibility extends WP_UnitTestCase {

	/** @var int */
	private $events_blog_id;

	/** @var int */
	private $user_id;

	/** @var string */
	private $dates_table;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->events_blog_id = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();
		$this->dates_table    = $wpdb->get_blog_prefix( $this->events_blog_id ) . 'datamachine_event_dates';

		extrachill_users_install_concert_tracking_table();

		// Mirror the Events-owned date table columns used by the public readers.
		$wpdb->query(
			"CREATE TABLE {$this->dates_table} (
				post_id BIGINT UNSIGNED NOT NULL,
				start_datetime DATETIME NOT NULL,
				end_datetime DATETIME DEFAULT NULL,
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (post_id),
				KEY start_datetime (start_datetime)
			) {$wpdb->get_charset_collate()}"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only table name and schema.

		switch_to_blog( $this->events_blog_id );
		register_post_type( 'data_machine_events', array( 'public' => true ) );
		register_taxonomy( 'artist', 'data_machine_events', array( 'public' => true ) );
		register_taxonomy( 'venue', 'data_machine_events', array( 'public' => true ) );
		register_taxonomy( 'location', 'data_machine_events', array( 'public' => true ) );
		restore_current_blog();
	}

	public function test_public_history_excludes_every_non_public_event_and_keeps_pages_consistent(): void {
		$published_first = $this->track_event( 'Published First', 'publish', 'data_machine_events', '2024-01-10 20:00:00' );
		$draft           = $this->track_event( 'Draft', 'draft', 'data_machine_events', '2024-02-10 20:00:00' );
		$published_mid   = $this->track_event( 'Published Middle', 'publish', 'data_machine_events', '2024-03-10 20:00:00' );
		$private         = $this->track_event( 'Private', 'private', 'data_machine_events', '2024-04-10 20:00:00' );
		$pending         = $this->track_event( 'Pending', 'pending', 'data_machine_events', '2024-05-10 20:00:00' );
		$trashed         = $this->track_event( 'Trashed', 'trash', 'data_machine_events', '2024-06-10 20:00:00' );
		$wrong_type      = $this->track_event( 'Wrong Type', 'publish', 'post', '2024-07-10 20:00:00' );
		$deleted         = $this->track_event( 'Deleted', 'publish', 'data_machine_events', '2024-08-10 20:00:00' );
		$published_last  = $this->track_event( 'Published Last', 'publish', 'data_machine_events', '2025-01-10 20:00:00' );

		switch_to_blog( $this->events_blog_id );
		wp_delete_post( $deleted, true );
		restore_current_blog();
		$this->write_event_date( $deleted, '2024-08-10 20:00:00' );

		$page_one = ec_users_get_user_events(
			$this->user_id,
			array(
				'blog_id'  => $this->events_blog_id,
				'page'     => 1,
				'per_page' => 2,
				'order'    => 'ASC',
			)
		);
		$page_two = ec_users_get_user_events(
			$this->user_id,
			array(
				'blog_id'  => $this->events_blog_id,
				'page'     => 2,
				'per_page' => 2,
				'order'    => 'ASC',
			)
		);

		$this->assertSame( array( $published_first, $published_mid ), wp_list_pluck( $page_one['shows'], 'event_id' ) );
		$this->assertSame( array( $published_last ), wp_list_pluck( $page_two['shows'], 'event_id' ) );
		$this->assertSame( 3, $page_one['total'] );
		$this->assertSame( 2, $page_one['pages'] );
		$this->assertSame( 3, $page_two['total'] );
		$this->assertSame( 2, $page_two['pages'] );

		$excluded = array( $draft, $private, $pending, $trashed, $wrong_type, $deleted );
		$this->assertEmpty( array_intersect( $excluded, wp_list_pluck( array_merge( $page_one['shows'], $page_two['shows'] ), 'event_id' ) ) );

		// Raw owner/service counts retain all attendance rows; only public readers filter visibility.
		$this->assertSame( 9, ec_users_get_user_event_count( $this->user_id, $this->events_blog_id ) );
	}

	public function test_public_stats_use_the_same_visibility_rules_and_restore_blog_context(): void {
		$first = $this->track_event( 'First Public', 'publish', 'data_machine_events', '2023-12-20 20:00:00' );
		$this->track_event( 'Hidden Draft', 'draft', 'data_machine_events', '2024-01-10 20:00:00' );
		$this->track_event( 'Wrong Type', 'publish', 'post', '2024-02-10 20:00:00' );
		$latest  = $this->track_event( 'Latest Public', 'publish', 'data_machine_events', '2024-03-10 20:00:00' );
		$deleted = $this->track_event( 'Deleted', 'publish', 'data_machine_events', '2025-01-10 20:00:00' );

		switch_to_blog( $this->events_blog_id );
		wp_delete_post( $deleted, true );
		restore_current_blog();
		$this->write_event_date( $deleted, '2025-01-10 20:00:00' );

		$original_blog_id = get_current_blog_id();
		$history         = ec_users_get_user_events( $this->user_id, array( 'blog_id' => $this->events_blog_id ) );
		$this->assertSame( $original_blog_id, get_current_blog_id(), 'History enrichment must restore the calling blog.' );

		$stats = ec_users_get_user_concert_stats( $this->user_id, array( 'blog_id' => $this->events_blog_id ) );
		$this->assertSame( $original_blog_id, get_current_blog_id(), 'Stats enrichment must restore the calling blog.' );
		$this->assertSame( 2, $history['total'] );
		$this->assertSame( 2, $stats['total_shows'] );
		$this->assertSame( array( '2024' => 1, '2023' => 1 ), $stats['shows_by_year'] );
		$this->assertSame( $first, $stats['first_show']['event_id'] );
		$this->assertSame( 'First Public', $stats['first_show']['title'] );
		$this->assertSame( $latest, $stats['latest_show']['event_id'] );
		$this->assertSame( 'Latest Public', $stats['latest_show']['title'] );
	}

	private function track_event( string $title, string $status, string $post_type, string $start_datetime ): int {
		switch_to_blog( $this->events_blog_id );
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => $status,
				'post_type'   => $post_type,
			)
		);
		restore_current_blog();

		$this->write_event_date( $post_id, $start_datetime, $status );
		ec_users_mark_event( $this->user_id, $post_id, $this->events_blog_id );

		return $post_id;
	}

	private function write_event_date( int $post_id, string $start_datetime, string $status = 'publish' ): void {
		global $wpdb;

		$wpdb->replace(
			$this->dates_table,
			array(
				'post_id'        => $post_id,
				'start_datetime' => $start_datetime,
				'post_status'    => $status,
			),
			array( '%d', '%s', '%s' )
		);
	}
}
