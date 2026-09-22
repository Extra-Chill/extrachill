<?php
/**
 * Tests for event attendance visibility resolution and public attendee listing.
 *
 * Covers extrachill-users#414: absent meta must resolve identically for every
 * user (private) so effective visibility never depends on signup date, and
 * public attendee lists must include only users who explicitly opted in.
 *
 * @package ExtraChill\Users
 */

class Test_Event_Attendance_Visibility extends WP_UnitTestCase {

	/** @var int */
	private $event_id;

	/** @var int */
	private $blog_id;

	/** @var array[] */
	private $visibility_changes = array();

	protected function setUp(): void {
		parent::setUp();

		$this->event_id = 420001;
		$this->blog_id  = get_current_blog_id();

		extrachill_users_install_concert_tracking_table();
		$this->clear_tracking_table();

		add_action( 'extrachill_users_visibility_changed', array( $this, 'record_visibility_change' ), 10, 4 );
	}

	protected function tearDown(): void {
		$this->clear_tracking_table();
		remove_action( 'extrachill_users_visibility_changed', array( $this, 'record_visibility_change' ), 10 );
		parent::tearDown();
	}

	public function record_visibility_change( int $user_id, string $setting, string $old_visibility, string $new_visibility ): void {
		$this->visibility_changes[] = array(
			'user_id' => $user_id,
			'setting' => $setting,
			'old'     => $old_visibility,
			'new'     => $new_visibility,
		);
	}

	public function test_absent_meta_resolves_private_regardless_of_signup_cohort(): void {
		// Simulates a pre-hook registrant: no visibility meta row at all.
		$legacy_user_id = self::factory()->user->create();
		delete_user_meta( $legacy_user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY );

		// Simulates a post-hook registrant: explicit private written at registration.
		$modern_user_id = self::factory()->user->create();

		$this->assertSame(
			'private',
			get_user_meta( $modern_user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, true ),
			'Registration must write the explicit private default.'
		);
		$this->assertSame( 'private', extrachill_users_get_event_attendance_visibility( $legacy_user_id ) );
		$this->assertSame( 'private', extrachill_users_get_event_attendance_visibility( $modern_user_id ) );

		wp_set_current_user( $legacy_user_id );
		$settings = extrachill_users_ability_get_settings();
		$this->assertArrayNotHasKey( 'errors', (array) $settings );
		$this->assertSame( 'private', $settings['event_attendance_visibility'] );
	}

	public function test_only_explicit_opt_in_appears_in_public_attendee_list(): void {
		$public_user_id = self::factory()->user->create();
		update_user_meta( $public_user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, 'public' );

		// Absent meta: the pre-hook cohort. Must be excluded exactly like an
		// explicit private user now that absent resolves private.
		$absent_meta_user_id = self::factory()->user->create();
		delete_user_meta( $absent_meta_user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY );

		$private_user_id = self::factory()->user->create();

		$this->mark_event( $public_user_id );
		$this->mark_event( $absent_meta_user_id );
		$this->mark_event( $private_user_id );

		$attendees = ec_users_get_event_attendees( $this->event_id, $this->blog_id );

		$this->assertSame( array( $public_user_id ), wp_list_pluck( $attendees, 'user_id' ) );

		// The count stays unfiltered — it reports total marks, not identities.
		$this->assertSame( 3, ec_users_get_event_mark_count( $this->event_id, $this->blog_id ) );

		$result = extrachill_users_ability_get_event_attendance(
			array(
				'event_id'          => $this->event_id,
				'blog_id'           => $this->blog_id,
				'include_attendees' => true,
			)
		);
		$this->assertSame( 3, $result['count'] );
		$this->assertSame( array( $public_user_id ), wp_list_pluck( $result['attendees'], 'user_id' ) );
	}

	public function test_visibility_toggle_changes_listing_and_reports_effective_changes(): void {
		$user_id = self::factory()->user->create();
		$this->mark_event( $user_id );

		$this->assertEmpty(
			ec_users_get_event_attendees( $this->event_id, $this->blog_id ),
			'A user with absent meta must not appear before opting in.'
		);

		// Opt in: effective change private -> public must be reported.
		$this->assertTrue( extrachill_users_set_event_attendance_visibility( $user_id, 'public' ) );
		$this->assertSame( array( $user_id ), wp_list_pluck( ec_users_get_event_attendees( $this->event_id, $this->blog_id ), 'user_id' ) );
		$this->assertSame(
			array(
				'user_id' => $user_id,
				'setting' => 'event_attendance_visibility',
				'old'     => 'private',
				'new'     => 'public',
			),
			$this->visibility_changes[0]
		);

		// Opt back out: effective change public -> private must be reported.
		$this->visibility_changes = array();
		$this->assertTrue( extrachill_users_set_event_attendance_visibility( $user_id, 'private' ) );
		$this->assertEmpty( ec_users_get_event_attendees( $this->event_id, $this->blog_id ) );
		$this->assertSame(
			array(
				'user_id' => $user_id,
				'setting' => 'event_attendance_visibility',
				'old'     => 'public',
				'new'     => 'private',
			),
			$this->visibility_changes[0]
		);

		// Writing private over absent meta is a no-op effective change and
		// must not be reported.
		$this->visibility_changes = array();
		$silent_user_id           = self::factory()->user->create();
		delete_user_meta( $silent_user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY );
		$this->assertTrue( extrachill_users_set_event_attendance_visibility( $silent_user_id, 'private' ) );
		$this->assertSame( array(), $this->visibility_changes );
	}

	private function mark_event( int $user_id ): void {
		global $wpdb;
		$wpdb->insert(
			extrachill_users_concert_tracking_table_name(),
			array(
				'user_id'    => $user_id,
				'event_id'   => $this->event_id,
				'blog_id'    => $this->blog_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}

	private function clear_tracking_table(): void {
		global $wpdb;
		$table = extrachill_users_concert_tracking_table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
	}
}
