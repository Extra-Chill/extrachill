<?php
/**
 * Tests for canonical event validation on attendance writes.
 *
 * @package ExtraChill\Users
 */

/**
 * Verifies attendance writes only accept published canonical Events posts.
 */
class Test_Concert_Tracking_Validation extends WP_UnitTestCase {

	/**
	 * Canonical Events site fixture ID.
	 *
	 * @var int
	 */
	private int $events_blog_id;

	/**
	 * Noncanonical site fixture ID.
	 *
	 * @var int
	 */
	private int $other_blog_id;

	/**
	 * Authenticated test user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Tracking-table operation to replace with invalid SQL.
	 *
	 * @var string
	 */
	private string $failed_operation = '';

	/**
	 * Captured advisory-lock queries.
	 *
	 * @var string[]
	 */
	private array $lock_queries = array();

	/**
	 * Create isolated multisite fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->events_blog_id = self::factory()->blog->create();
		$this->other_blog_id  = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();
		wp_set_current_user( $this->user_id );

		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type( 'data_machine_events', array( 'public' => true ) );
		}
		extrachill_users_install_concert_tracking_table();
		$this->clear_tracking_table();
	}

	/**
	 * Remove shared table rows and test filters.
	 */
	protected function tearDown(): void {
		$this->clear_tracking_table();
		remove_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Return the Events site fixture ID.
	 */
	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
	}

	/**
	 * Replace one tracking-table mutation with a deterministic database error.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function fail_tracking_mutation( string $query ): string {
		$table = extrachill_users_concert_tracking_table_name();
		if ( 'insert' === $this->failed_operation && str_starts_with( ltrim( $query ), "INSERT IGNORE INTO {$table}" ) ) {
			return 'INSERT INTO ec_missing_attendance_table (id) VALUES (1)';
		}
		if ( 'delete' === $this->failed_operation && str_starts_with( ltrim( $query ), "DELETE FROM {$table}" ) ) {
			return 'DELETE FROM ec_missing_attendance_table WHERE id = 1';
		}
		return $query;
	}

	/**
	 * Capture advisory-lock acquisition and release queries.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function capture_lock_queries( string $query ): string {
		if ( false !== strpos( $query, 'GET_LOCK(' ) || false !== strpos( $query, 'RELEASE_LOCK(' ) ) {
			$this->lock_queries[] = $query;
		}
		return $query;
	}

	/**
	 * Force advisory-lock acquisition to time out.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function timeout_toggle_lock( string $query ): string {
		return false !== strpos( $query, 'GET_LOCK(' ) ? 'SELECT 0' : $query;
	}

	/**
	 * A requested site must exist.
	 */
	public function test_rejects_nonexistent_site_without_mutation(): void {
		$this->assert_rejected_without_mutation(
			array(
				'event_id' => 123,
				'blog_id'  => 999999,
			),
			'event_site_not_found',
			'The requested event site does not exist.',
			404
		);
	}

	/**
	 * The canonical Events site must resolve before writes.
	 */
	public function test_rejects_unavailable_canonical_site_without_mutation(): void {
		add_filter( 'extrachill_users_events_blog_id', '__return_zero', 20 );
		try {
			$this->assert_rejected_without_mutation(
				array( 'event_id' => 123 ),
				'events_site_unavailable',
				'The canonical Events site is unavailable.',
				500
			);
		} finally {
			remove_filter( 'extrachill_users_events_blog_id', '__return_zero', 20 );
		}
	}

	/**
	 * A requested post must exist on the canonical site.
	 */
	public function test_rejects_missing_post_without_mutation(): void {
		$this->assert_rejected_without_mutation(
			array( 'event_id' => 999999 ),
			'event_not_found',
			'The requested event does not exist.',
			404
		);
	}

	/**
	 * A blog-local post ID cannot select another site.
	 */
	public function test_rejects_same_id_from_noncanonical_site(): void {
		$event_id          = $this->create_post_on_blog( $this->other_blog_id, 'data_machine_events', 'publish' );
		$canonical_post_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', 'publish' );
		$this->assertSame( $canonical_post_id, $event_id, 'The fixture must prove blog-local IDs cannot select a noncanonical site.' );

		$this->assert_rejected_without_mutation(
			array(
				'event_id' => $event_id,
				'blog_id'  => $this->other_blog_id,
			),
			'noncanonical_event_site',
			'Attendance can only be recorded for events on the canonical Events site.',
			400
		);
	}

	/**
	 * A canonical-site post must use the event post type.
	 */
	public function test_rejects_wrong_post_type_without_mutation(): void {
		$post_id = $this->create_post_on_blog( $this->events_blog_id, 'post', 'publish' );
		$this->assert_rejected_without_mutation(
			array( 'event_id' => $post_id ),
			'invalid_event_post_type',
			'The requested post is not an event.',
			400
		);
	}

	/**
	 * The lower-level service protects non-ability callers such as imports.
	 */
	public function test_direct_mark_rejects_invalid_target_without_mutation(): void {
		$rows_before   = $this->tracking_row_count();
		$marked_before = did_action( 'ec_users_event_marked' );

		$result = ec_users_mark_event( $this->user_id, 999999, $this->events_blog_id );

		$this->assertWPError( $result );
		$this->assertSame( 'event_not_found', $result->get_error_code() );
		$this->assertSame( $rows_before, $this->tracking_row_count() );
		$this->assertSame( $marked_before, did_action( 'ec_users_event_marked' ) );
	}

	/**
	 * Only published event posts can be marked.
	 *
	 * @param string $status Event post status.
	 *
	 * @dataProvider unpublished_status_provider
	 */
	public function test_rejects_unpublished_events_without_mutation( string $status ): void {
		$event_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', $status );
		$this->assert_rejected_without_mutation(
			array( 'event_id' => $event_id ),
			'event_not_published',
			'Attendance can only be recorded for published events.',
			400
		);
	}

	/**
	 * Provide all rejected unpublished statuses.
	 *
	 * @return array<string, array{string}>
	 */
	public function unpublished_status_provider(): array {
		return array(
			'draft'   => array( 'draft' ),
			'pending' => array( 'pending' ),
			'future'  => array( 'future' ),
			'private' => array( 'private' ),
			'trash'   => array( 'trash' ),
		);
	}

	/**
	 * Mark database errors are not reported as idempotent no-ops.
	 */
	public function test_mark_distinguishes_noop_from_database_failure(): void {
		$event_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', 'publish' );
		$this->assertTrue( ec_users_mark_event( $this->user_id, $event_id ) );
		$this->assertFalse( ec_users_mark_event( $this->user_id, $event_id ) );
		$this->assertTrue( ec_users_unmark_event( $this->user_id, $event_id ) );

		$this->failed_operation = 'insert';
		add_filter( 'query', array( $this, 'fail_tracking_mutation' ) );
		try {
			$result = ec_users_mark_event( $this->user_id, $event_id );
		} finally {
			remove_filter( 'query', array( $this, 'fail_tracking_mutation' ) );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'event_mark_database_error', $result->get_error_code() );
		$this->assertFalse( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );
	}

	/**
	 * Unmark database errors are not reported as idempotent no-ops.
	 */
	public function test_unmark_distinguishes_noop_from_database_failure(): void {
		$event_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', 'publish' );
		$this->assertFalse( ec_users_unmark_event( $this->user_id, $event_id ) );
		$this->assertTrue( ec_users_mark_event( $this->user_id, $event_id ) );

		$this->failed_operation = 'delete';
		add_filter( 'query', array( $this, 'fail_tracking_mutation' ) );
		try {
			$result = ec_users_unmark_event( $this->user_id, $event_id );
		} finally {
			remove_filter( 'query', array( $this, 'fail_tracking_mutation' ) );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'event_unmark_database_error', $result->get_error_code() );
		$this->assertTrue( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );
	}

	/**
	 * Registered ability execution preserves service database errors.
	 */
	public function test_registered_ability_propagates_database_failure(): void {
		$event_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', 'publish' );
		$ability  = wp_get_ability( 'extrachill/toggle-event-mark' );
		$this->assertNotNull( $ability );

		$this->failed_operation = 'insert';
		add_filter( 'query', array( $this, 'fail_tracking_mutation' ) );
		try {
			$result = $ability->execute( array( 'event_id' => $event_id ) );
		} finally {
			remove_filter( 'query', array( $this, 'fail_tracking_mutation' ) );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'event_mark_database_error', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( 0, ec_users_get_event_mark_count( $event_id, $this->events_blog_id ) );
	}

	/**
	 * Every toggle holds the per-key lock through its mutation.
	 */
	public function test_toggle_serializes_two_transitions_through_advisory_lock(): void {
		$event_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', 'publish' );
		add_filter( 'query', array( $this, 'capture_lock_queries' ) );
		try {
			$marked   = ec_users_toggle_event( $this->user_id, $event_id );
			$unmarked = ec_users_toggle_event( $this->user_id, $event_id );
		} finally {
			remove_filter( 'query', array( $this, 'capture_lock_queries' ) );
		}

		$this->assertSame( array( 'marked' => true ), $marked );
		$this->assertSame( array( 'marked' => false ), $unmarked );
		$this->assertCount( 4, $this->lock_queries );
		$this->assertStringContainsString( 'GET_LOCK(', $this->lock_queries[0] );
		$this->assertStringContainsString( 'RELEASE_LOCK(', $this->lock_queries[1] );
		$this->assertStringContainsString( 'GET_LOCK(', $this->lock_queries[2] );
		$this->assertStringContainsString( 'RELEASE_LOCK(', $this->lock_queries[3] );
		$this->assertFalse( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );
	}

	/**
	 * Lock contention returns a stable ability-facing error without mutation.
	 */
	public function test_toggle_lock_timeout_does_not_report_unstored_state(): void {
		$event_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', 'publish' );
		add_filter( 'query', array( $this, 'timeout_toggle_lock' ) );
		try {
			$result = extrachill_users_ability_toggle_event_mark( array( 'event_id' => $event_id ) );
		} finally {
			remove_filter( 'query', array( $this, 'timeout_toggle_lock' ) );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'event_toggle_lock_timeout', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 0, ec_users_get_event_mark_count( $event_id, $this->events_blog_id ) );
	}

	/**
	 * Valid events retain mark idempotence and toggle behavior.
	 */
	public function test_valid_event_toggles_and_preserves_idempotent_marks(): void {
		$event_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', 'publish' );

		$this->assertTrue( ec_users_mark_event( $this->user_id, $event_id ) );
		$this->assertFalse( ec_users_mark_event( $this->user_id, $event_id ) );
		$this->assertSame( 1, ec_users_get_event_mark_count( $event_id, $this->events_blog_id ) );

		$unmarked = extrachill_users_ability_toggle_event_mark( array( 'event_id' => $event_id ) );
		$this->assertFalse( $unmarked['marked'] );
		$this->assertSame( 0, $unmarked['count'] );

		$marked = extrachill_users_ability_toggle_event_mark( array( 'event_id' => $event_id ) );
		$this->assertTrue( $marked['marked'] );
		$this->assertSame( 1, $marked['count'] );
	}

	/**
	 * Validation restores the caller's multisite context.
	 */
	public function test_validation_restores_multisite_context(): void {
		$event_id = $this->create_post_on_blog( $this->events_blog_id, 'data_machine_events', 'publish' );
		switch_to_blog( $this->other_blog_id );
		$original_blog_id = get_current_blog_id();

		$result = extrachill_users_ability_toggle_event_mark( array( 'event_id' => $event_id ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $original_blog_id, get_current_blog_id() );
		restore_current_blog();
	}

	/**
	 * Assert a stable error and no write-side effects.
	 *
	 * @param array  $input Ability input.
	 * @param string $code Expected error code.
	 * @param string $message Expected error message.
	 * @param int    $status Expected HTTP status.
	 */
	private function assert_rejected_without_mutation( array $input, string $code, string $message, int $status ): void {
		$rows_before     = $this->tracking_row_count();
		$marked_before   = did_action( 'ec_users_event_marked' );
		$unmarked_before = did_action( 'ec_users_event_unmarked' );
		$original_blog   = get_current_blog_id();

		$result = extrachill_users_ability_toggle_event_mark( $input );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertSame( $rows_before, $this->tracking_row_count() );
		$this->assertSame( $marked_before, did_action( 'ec_users_event_marked' ) );
		$this->assertSame( $unmarked_before, did_action( 'ec_users_event_unmarked' ) );
		$this->assertSame( $original_blog, get_current_blog_id() );
	}

	/**
	 * Create a post on a specific test site.
	 *
	 * @param int    $blog_id Site ID.
	 * @param string $post_type Post type.
	 * @param string $post_status Post status.
	 */
	private function create_post_on_blog( int $blog_id, string $post_type, string $post_status ): int {
		switch_to_blog( $blog_id );
		try {
			return self::factory()->post->create(
				array(
					'post_type'   => $post_type,
					'post_status' => $post_status,
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Count all rows in the network attendance table.
	 */
	private function tracking_row_count(): int {
		global $wpdb;
		$table = extrachill_users_concert_tracking_table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
	}

	/**
	 * Clear the network attendance table between tests.
	 */
	private function clear_tracking_table(): void {
		global $wpdb;
		$table = extrachill_users_concert_tracking_table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
	}
}
