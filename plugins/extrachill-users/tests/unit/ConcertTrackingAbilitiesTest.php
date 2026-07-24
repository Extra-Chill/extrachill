<?php
/**
 * Tests for explicit, idempotent concert attendance abilities.
 *
 * @package ExtraChill\Users
 */

use DataMachineEvents\Core\EventDatesTable;

/**
 * Exercise concert attendance through registered WP_Ability contracts.
 */
class Test_Concert_Tracking_Abilities extends WP_UnitTestCase {

	private int $events_blog_id;

	private int $event_id;

	private int $user_id;

	/** @var string[] */
	private array $attendee_queries = array();

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
			$this->event_id = self::factory()->post->create(
				array(
					'post_type'   => 'data_machine_events',
					'post_status' => 'publish',
				)
			);
			EventDatesTable::upsert( $this->event_id, '2026-07-25 20:00:00', null, 'publish' );
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

	public function test_event_timing_uses_owned_event_dates_fixture_for_all_states(): void {
		$fixtures = array(
			'upcoming' => array( '2026-07-25 20:00:00', null ),
			'ongoing'  => array( '2026-07-24 10:00:00', '2026-07-24 14:00:00' ),
			'past'     => array( '2026-07-23 20:00:00', '2026-07-23 23:00:00' ),
		);

		switch_to_blog( $this->events_blog_id );
		try {
			foreach ( $fixtures as $expected => $dates ) {
				EventDatesTable::upsert( $this->event_id, $dates[0], $dates[1], 'publish' );
				$this->assertSame( $expected, ec_users_get_event_timing( $this->event_id, $this->events_blog_id ) );
			}
		} finally {
			restore_current_blog();
		}
	}

	public function test_registered_set_ability_validates_input_and_declares_stable_output(): void {
		$ability = wp_get_ability( 'extrachill/set-event-mark' );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame(
			array( 'user_id', 'marked', 'changed', 'count', 'count_label', 'timing' ),
			$ability->get_output_schema()['required']
		);
		$this->assertSame( 0, $ability->get_input_schema()['properties']['user_id']['default'] );
		$this->assertSame( 0, $ability->get_input_schema()['properties']['blog_id']['default'] );

		$result = $ability->execute(
			array(
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_registered_set_ability_is_idempotent_in_both_directions(): void {
		$ability = wp_get_ability( 'extrachill/set-event-mark' );
		$input   = array(
			'event_id' => $this->event_id,
			'blog_id'  => $this->events_blog_id,
			'marked'   => true,
		);

		$first_mark  = $ability->execute( $input );
		$second_mark = $ability->execute( $input );

		$this->assertFalse( is_wp_error( $first_mark ) );
		$this->assertSame( $this->user_id, $first_mark['user_id'], 'Omitted user_id must default to the current user.' );
		$this->assertTrue( $first_mark['changed'] );
		$this->assertTrue( $first_mark['marked'] );
		$this->assertFalse( $second_mark['changed'] );
		$this->assertTrue( $second_mark['marked'] );

		$input['marked'] = false;
		$first_unmark    = $ability->execute( $input );
		$second_unmark   = $ability->execute( $input );

		$this->assertTrue( $first_unmark['changed'] );
		$this->assertFalse( $first_unmark['marked'] );
		$this->assertFalse( $second_unmark['changed'] );
		$this->assertFalse( $second_unmark['marked'] );
	}

	public function test_registered_set_ability_enforces_self_admin_and_unauthenticated_permissions(): void {
		$ability        = wp_get_ability( 'extrachill/set-event-mark' );
		$target_user_id = self::factory()->user->create();
		$input          = array(
			'user_id'  => $target_user_id,
			'event_id' => $this->event_id,
			'blog_id'  => $this->events_blog_id,
			'marked'   => true,
		);

		$denied = $ability->execute( $input );
		$this->assertWPError( $denied );
		$this->assertSame( 'ability_invalid_permissions', $denied->get_error_code() );

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $administrator_id );
		wp_set_current_user( $administrator_id );
		$allowed = $ability->execute( $input );
		$this->assertFalse( is_wp_error( $allowed ) );
		$this->assertSame( $target_user_id, $allowed['user_id'] );
		revoke_super_admin( $administrator_id );

		wp_set_current_user( 0 );
		$unauthenticated = $ability->execute( $input );
		$this->assertWPError( $unauthenticated );
		$this->assertSame( 'ability_invalid_permissions', $unauthenticated->get_error_code() );
	}

	public function test_registered_set_ability_propagates_canonical_target_validation(): void {
		$ability = wp_get_ability( 'extrachill/set-event-mark' );
		$missing = $ability->execute(
			array(
				'event_id' => 999999,
				'blog_id'  => $this->events_blog_id,
				'marked'   => true,
			)
		);
		$this->assertWPError( $missing );
		$this->assertSame( 'event_not_found', $missing->get_error_code() );

		$wrong_type_id = $this->create_event( 'post', 'publish' );
		$wrong_type    = $ability->execute(
			array(
				'event_id' => $wrong_type_id,
				'blog_id'  => $this->events_blog_id,
				'marked'   => true,
			)
		);
		$this->assertWPError( $wrong_type );
		$this->assertSame( 'invalid_event_post_type', $wrong_type->get_error_code() );

		$draft_event_id = $this->create_event( 'data_machine_events', 'draft' );
		$unpublished    = $ability->execute(
			array(
				'event_id' => $draft_event_id,
				'blog_id'  => $this->events_blog_id,
				'marked'   => true,
			)
		);
		$this->assertWPError( $unpublished );
		$this->assertSame( 'event_not_published', $unpublished->get_error_code() );
	}

	public function test_registered_attendance_ability_preserves_public_reads_and_authorizes_targets(): void {
		$ability = wp_get_ability( 'extrachill/get-event-attendance' );
		$this->assertSame(
			array( 'count', 'count_label', 'timing', 'user_marked', 'attendees' ),
			$ability->get_output_schema()['required']
		);

		wp_set_current_user( 0 );
		$public = $ability->execute(
			array(
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);
		$this->assertFalse( is_wp_error( $public ) );
		$this->assertFalse( $public['user_marked'] );

		$targeted = $ability->execute(
			array(
				'user_id'  => $this->user_id,
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);
		$this->assertWPError( $targeted );
		$this->assertSame( 'ability_invalid_permissions', $targeted->get_error_code() );

		wp_set_current_user( $this->user_id );
		$other_user_id = self::factory()->user->create();
		$denied        = $ability->execute(
			array(
				'user_id'  => $other_user_id,
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);
		$this->assertWPError( $denied );
		$this->assertSame( 'ability_invalid_permissions', $denied->get_error_code() );

		$self = $ability->execute(
			array(
				'user_id'  => $this->user_id,
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);
		$this->assertFalse( is_wp_error( $self ) );

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $administrator_id );
		wp_set_current_user( $administrator_id );
		$admin = $ability->execute(
			array(
				'user_id'  => $other_user_id,
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);
		$this->assertFalse( is_wp_error( $admin ) );
		revoke_super_admin( $administrator_id );
	}

	public function test_registered_reader_schemas_declare_shared_query_bounds(): void {
		$history_schema = wp_get_ability( 'extrachill/get-user-shows' )->get_input_schema()['properties']['per_page'];
		$this->assertSame( EC_USERS_CONCERT_HISTORY_PER_PAGE_MIN, $history_schema['minimum'] );
		$this->assertSame( EC_USERS_CONCERT_HISTORY_PER_PAGE_DEFAULT, $history_schema['default'] );
		$this->assertSame( EC_USERS_CONCERT_HISTORY_PER_PAGE_MAX, $history_schema['maximum'] );

		$attendee_schema = wp_get_ability( 'extrachill/get-event-attendance' )->get_input_schema()['properties']['limit'];
		$this->assertSame( EC_USERS_EVENT_ATTENDEE_LIMIT_MIN, $attendee_schema['minimum'] );
		$this->assertSame( EC_USERS_EVENT_ATTENDEE_LIMIT_DEFAULT, $attendee_schema['default'] );
		$this->assertSame( EC_USERS_EVENT_ATTENDEE_LIMIT_MAX, $attendee_schema['maximum'] );
		$this->assertSame(
			array( 'user_id', 'display_name', 'avatar_url', 'profile_url' ),
			wp_get_ability( 'extrachill/get-event-attendance' )->get_output_schema()['properties']['attendees']['items']['required']
		);
	}

	/**
	 * @dataProvider invalid_reader_input_provider
	 */
	public function test_registered_reader_abilities_reject_malformed_and_out_of_range_inputs( string $ability_name, string $field, $value ): void {
		$input = 'extrachill/get-user-shows' === $ability_name
			? array(
				'user_id' => $this->user_id,
				$field     => $value,
			)
			: array(
				'event_id' => $this->event_id,
				$field      => $value,
			);
		$result = wp_get_ability( $ability_name )->execute( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function invalid_reader_input_provider(): array {
		return array(
			'history zero'          => array( 'extrachill/get-user-shows', 'per_page', 0 ),
			'history negative'      => array( 'extrachill/get-user-shows', 'per_page', -1 ),
			'history over maximum'  => array( 'extrachill/get-user-shows', 'per_page', 101 ),
			'history nonnumeric'    => array( 'extrachill/get-user-shows', 'per_page', 'all' ),
			'attendee zero'         => array( 'extrachill/get-event-attendance', 'limit', 0 ),
			'attendee negative'     => array( 'extrachill/get-event-attendance', 'limit', -1 ),
			'attendee over maximum' => array( 'extrachill/get-event-attendance', 'limit', 101 ),
			'attendee nonnumeric'   => array( 'extrachill/get-event-attendance', 'limit', 'all' ),
		);
	}

	public function test_attendee_service_clamps_defaults_boundaries_and_malformed_values(): void {
		$seeded_user_ids = $this->seed_attendees( 105 );

		$default = ec_users_get_event_attendees( $this->event_id, $this->events_blog_id );
		$this->assertCount( 10, $default );
		$this->assertSame( array_slice( $seeded_user_ids, 0, 10 ), wp_list_pluck( $default, 'user_id' ) );
		$this->assertSame( array( 'user_id', 'display_name', 'avatar_url', 'profile_url' ), array_keys( $default[0] ) );
		$this->assertIsInt( $default[0]['user_id'] );
		$this->assertIsString( $default[0]['display_name'] );
		$this->assertIsString( $default[0]['avatar_url'] );
		$this->assertIsString( $default[0]['profile_url'] );
		foreach ( array( 0, -10, 'not-a-number' ) as $malformed_limit ) {
			$this->assertCount( 1, ec_users_get_event_attendees( $this->event_id, $this->events_blog_id, $malformed_limit ) );
		}

		add_filter( 'query', array( $this, 'capture_attendee_query' ) );
		try {
			$maximum = ec_users_get_event_attendees( $this->event_id, $this->events_blog_id, PHP_INT_MAX );
		} finally {
			remove_filter( 'query', array( $this, 'capture_attendee_query' ) );
		}

		$this->assertCount( 100, $maximum );
		$this->assertNotEmpty( $this->attendee_queries );
		$this->assertStringContainsString( 'LIMIT 100', end( $this->attendee_queries ) );
		$this->assertStringNotContainsString( '%d', end( $this->attendee_queries ) );
	}

	public function test_private_attendees_are_filtered_before_limit_without_changing_count_or_owner_mark(): void {
		global $wpdb;
		$table           = extrachill_users_concert_tracking_table_name();
		$seeded_user_ids = $this->seed_attendees( 4 );
		extrachill_users_set_event_attendance_visibility( $seeded_user_ids[0], 'private' );
		extrachill_users_set_event_attendance_visibility( $seeded_user_ids[2], 'private' );

		$wpdb->insert(
			$table,
			array(
				'user_id'    => $this->user_id,
				'event_id'   => $this->event_id,
				'blog_id'    => $this->events_blog_id,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() + 1 ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
		extrachill_users_set_event_attendance_visibility( $this->user_id, 'private' );
		$this->assertSame(
			array( $seeded_user_ids[1], $seeded_user_ids[3] ),
			wp_list_pluck( ec_users_get_event_attendees( $this->event_id, $this->events_blog_id, 2 ), 'user_id' )
		);

		$result = wp_get_ability( 'extrachill/get-event-attendance' )->execute(
			array(
				'event_id'          => $this->event_id,
				'blog_id'           => $this->events_blog_id,
				'include_attendees' => true,
				'limit'             => 2,
			)
		);

		$this->assertSame( 5, $result['count'] );
		$this->assertTrue( $result['user_marked'] );
		$this->assertSame( array( $seeded_user_ids[1], $seeded_user_ids[3] ), wp_list_pluck( $result['attendees'], 'user_id' ) );
	}

	public function test_registered_attendance_ability_and_rest_route_apply_defaults_and_bounds(): void {
		$this->seed_attendees( 105 );
		$ability = wp_get_ability( 'extrachill/get-event-attendance' );

		$default = $ability->execute(
			array(
				'event_id'          => $this->event_id,
				'blog_id'           => $this->events_blog_id,
				'include_attendees' => true,
			)
		);
		$this->assertCount( 10, $default['attendees'] );

		$minimum = $ability->execute(
			array(
				'event_id'          => $this->event_id,
				'blog_id'           => $this->events_blog_id,
				'include_attendees' => true,
				'limit'             => 1,
			)
		);
		$this->assertCount( 1, $minimum['attendees'] );

		$maximum = $ability->execute(
			array(
				'event_id'          => $this->event_id,
				'blog_id'           => $this->events_blog_id,
				'include_attendees' => true,
				'limit'             => 100,
			)
		);
		$this->assertCount( 100, $maximum['attendees'] );

		wp_set_current_user( 0 );
		foreach ( array( 0, -1, 101, 'all' ) as $invalid_limit ) {
			$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/extrachill/get-event-attendance/run' );
			$request->set_query_params(
				array(
					'input' => array(
						'event_id'          => $this->event_id,
						'blog_id'           => $this->events_blog_id,
						'include_attendees' => true,
						'limit'             => $invalid_limit,
					),
				)
			);
			$this->assertSame( 400, rest_do_request( $request )->get_status() );
		}
	}

	public function capture_attendee_query( string $query ): string {
		if ( false !== strpos( $query, 'SELECT user_id' ) && false !== strpos( $query, 'LIMIT' ) ) {
			$this->attendee_queries[] = $query;
		}

		return $query;
	}

	public function test_registered_set_ability_reports_insert_and_delete_failures(): void {
		global $wpdb;
		$ability = wp_get_ability( 'extrachill/set-event-mark' );
		$input   = array(
			'event_id' => $this->event_id,
			'blog_id'  => $this->events_blog_id,
			'marked'   => true,
		);
		get_post( $this->event_id );

		$previous_suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', array( $this, 'fail_tracking_writes' ) );
		try {
			$failed_insert = $ability->execute( $input );
		} finally {
			remove_filter( 'query', array( $this, 'fail_tracking_writes' ) );
			$wpdb->suppress_errors( $previous_suppress );
		}

		$this->assertWPError( $failed_insert );
		$this->assertSame( 'event_mark_database_error', $failed_insert->get_error_code() );
		$this->assertFalse( ec_users_is_event_marked( $this->user_id, $this->event_id, $this->events_blog_id ) );

		$this->assertFalse( is_wp_error( $ability->execute( $input ) ) );
		$input['marked']   = false;
		$previous_suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', array( $this, 'fail_tracking_writes' ) );
		try {
			$failed_delete = $ability->execute( $input );
		} finally {
			remove_filter( 'query', array( $this, 'fail_tracking_writes' ) );
			$wpdb->suppress_errors( $previous_suppress );
		}

		$this->assertWPError( $failed_delete );
		$this->assertSame( 'event_unmark_database_error', $failed_delete->get_error_code() );
		$this->assertTrue( ec_users_is_event_marked( $this->user_id, $this->event_id, $this->events_blog_id ) );
	}

	public function fail_tracking_writes( string $query ): string {
		$table = extrachill_users_concert_tracking_table_name();
		if ( preg_match( '/^(INSERT(?: IGNORE)? INTO|DELETE FROM)\s+`?' . preg_quote( $table, '/' ) . '`?/i', $query ) ) {
			return str_replace( $table, $table . '_missing', $query );
		}

		return $query;
	}

	private function create_event( string $post_type, string $post_status ): int {
		switch_to_blog( $this->events_blog_id );
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

	private function clear_tracking_table(): void {
		global $wpdb;
		$table = extrachill_users_concert_tracking_table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
	}

	private function seed_attendees( int $count ): array {
		global $wpdb;
		$table    = extrachill_users_concert_tracking_table_name();
		$user_ids = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$user_id    = self::factory()->user->create();
			$user_ids[] = $user_id;
			update_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, 'public' );
			$wpdb->insert(
				$table,
				array(
					'user_id'    => $user_id,
					'event_id'   => $this->event_id,
					'blog_id'    => $this->events_blog_id,
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - $index ),
				),
				array( '%d', '%d', '%d', '%s' )
			);
		}

		return $user_ids;
	}
}
