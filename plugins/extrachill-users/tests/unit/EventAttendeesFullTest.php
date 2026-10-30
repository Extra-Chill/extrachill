<?php
/**
 * Tests for the full (private-inclusive) event attendee list — the
 * extrachill-events#877 RSVP-perk door list primitive.
 *
 * Mirrors ConcertTrackingAbilitiesTest.php's fixture shape (same table,
 * same events-blog switching pattern) so the two suites stay consistent.
 *
 * @package ExtraChill\Users
 */

use DataMachineEvents\Core\EventDatesTable;

/**
 * Exercise ec_users_get_event_attendees_full() and the
 * extrachill/get-event-attendees-full ability's default-deny authorization.
 */
class Test_Event_Attendees_Full extends WP_UnitTestCase {

	private int $events_blog_id;

	private int $event_id;

	protected function setUp(): void {
		parent::setUp();
		$this->events_blog_id = self::factory()->blog->create();

		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
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
			EventDatesTable::upsert( $this->event_id, '2026-10-21 18:30:00', null, 'publish' );
		} finally {
			restore_current_blog();
		}

		extrachill_users_install_concert_tracking_table();
		$this->clear_tracking_table();
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

	public function test_includes_private_attendees_the_public_strip_excludes(): void {
		$public_user  = $this->seed_attendee( 'public' );
		$private_user = $this->seed_attendee( 'private' );
		$unset_user   = $this->seed_attendee( null );

		$public_only = ec_users_get_event_attendees( $this->event_id, $this->events_blog_id );
		$full        = ec_users_get_event_attendees_full( $this->event_id, $this->events_blog_id );

		$this->assertSame( array( $public_user ), wp_list_pluck( $public_only, 'user_id' ), 'The public strip must stay exactly as today.' );
		$this->assertEqualsCanonicalizing(
			array( $public_user, $private_user, $unset_user ),
			wp_list_pluck( $full, 'user_id' ),
			'The full list must include private and never-chosen attendees.'
		);
	}

	public function test_full_attendee_rows_carry_marked_at(): void {
		$user_id = $this->seed_attendee( 'private' );

		$full = ec_users_get_event_attendees_full( $this->event_id, $this->events_blog_id );

		$this->assertCount( 1, $full );
		$this->assertSame( $user_id, $full[0]['user_id'] );
		$this->assertNotEmpty( $full[0]['marked_at'] );
	}

	public function test_ability_denies_by_default_even_for_a_logged_in_stranger(): void {
		$stranger = self::factory()->user->create();
		wp_set_current_user( $stranger );

		$ability = wp_get_ability( 'extrachill/get-event-attendees-full' );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute(
			array(
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_ability_denies_a_logged_out_visitor(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'extrachill/get-event-attendees-full' );
		$result  = $ability->execute(
			array(
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);

		$this->assertWPError( $result );
	}

	public function test_network_admin_is_always_authorized(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $administrator_id );
		wp_set_current_user( $administrator_id );

		$private_user = $this->seed_attendee( 'private' );

		$ability = wp_get_ability( 'extrachill/get-event-attendees-full' );
		$result  = $ability->execute(
			array(
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);

		revoke_super_admin( $administrator_id );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertContains( $private_user, wp_list_pluck( $result['attendees'], 'user_id' ) );
	}

	/**
	 * The cross-plugin composition — extrachill-events answering this
	 * filter with its own promoter/venue authority — is exercised in that
	 * plugin's own test suite (RsvpDoorListAuthorityTest.php). Here we
	 * prove only extrachill-users' side of the contract: the filter, once
	 * granted, is trusted, and the correct attendees are returned.
	 */
	public function test_a_granting_filter_is_trusted(): void {
		$stranger = self::factory()->user->create();
		wp_set_current_user( $stranger );

		$grant = static fn() => true;
		add_filter( 'extrachill_users_can_manage_event_attendance', $grant );

		$attendee = $this->seed_attendee( 'private' );

		$ability = wp_get_ability( 'extrachill/get-event-attendees-full' );
		$result  = $ability->execute(
			array(
				'event_id' => $this->event_id,
				'blog_id'  => $this->events_blog_id,
			)
		);

		remove_filter( 'extrachill_users_can_manage_event_attendance', $grant );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertContains( $attendee, wp_list_pluck( $result['attendees'], 'user_id' ) );
	}

	private function seed_attendee( ?string $visibility ): int {
		global $wpdb;
		$user_id = self::factory()->user->create();
		if ( null !== $visibility ) {
			update_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, $visibility );
		}
		$table = extrachill_users_concert_tracking_table_name();
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'event_id'   => $this->event_id,
				'blog_id'    => $this->events_blog_id,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
		return $user_id;
	}

	private function clear_tracking_table(): void {
		global $wpdb;
		$table = extrachill_users_concert_tracking_table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
	}
}
