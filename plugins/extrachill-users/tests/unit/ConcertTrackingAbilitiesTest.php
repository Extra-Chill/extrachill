<?php
/**
 * Tests for explicit, idempotent concert attendance abilities.
 *
 * @package ExtraChill\Users
 */

/**
 * Exercise concert attendance through registered WP_Ability contracts.
 */
class Test_Concert_Tracking_Abilities extends WP_UnitTestCase {

	private int $events_blog_id;

	private int $event_id;

	private int $user_id;

	protected function setUp(): void {
		parent::setUp();
		$this->events_blog_id = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();

		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type( 'data_machine_events', array( 'public' => true ) );
		}

		switch_to_blog( $this->events_blog_id );
		try {
			$this->event_id = self::factory()->post->create(
				array(
					'post_type'   => 'data_machine_events',
					'post_status' => 'publish',
				)
			);
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
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
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
}
