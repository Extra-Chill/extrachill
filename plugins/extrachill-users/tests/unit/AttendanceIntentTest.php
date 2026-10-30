<?php
// phpcs:ignoreFile -- Unit tests intentionally exercise signed request state without form nonces.
/**
 * Tests for signed attendance continuations.
 *
 * @package ExtraChill\Users
 * @file
 */
class Test_Attendance_Intent extends WP_UnitTestCase {
	/** Events site fixture ID. */
	private int $events_blog_id = 0;

	/** Clear request state after each test. */
	protected function tearDown(): void {
		unset( $_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] );
		remove_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		parent::tearDown();
	}

	/** Return the Events site fixture ID. */
	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
	}

	/**
	 * Build a correctly signed token with an explicit expiry.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $blog_id  Blog ID.
	 * @param int $expires  Expiry timestamp.
	 */
	private function signed_token( int $event_id, int $blog_id, int $expires ): string {
		$payload = $event_id . '|' . $blog_id . '|mark|' . $expires;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Match the production URL-safe token format.
		return rtrim( strtr( base64_encode( $payload . '|' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) ), '+/', '-_' ), '=' );
	}

	/** Create a published event on an Events site fixture and return its ID. */
	private function create_published_event(): int {
		$this->events_blog_id = self::factory()->blog->create();
		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		switch_to_blog( $this->events_blog_id );
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type( 'data_machine_events', array( 'public' => true ) );
		}
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		restore_current_blog();
		return (int) $event_id;
	}

	/** A freshly minted intent must outlive page dwell plus a slow signup. */
	public function test_minted_intent_covers_slow_signup_window(): void {
		$this->assertGreaterThanOrEqual( 2 * HOUR_IN_SECONDS, EC_USERS_ATTENDANCE_INTENT_TTL );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Inspect the production token format.
		$parts = explode( '|', base64_decode( strtr( ec_users_create_attendance_intent( 123, 456 ), '-_', '+/' ) ) );
		$this->assertGreaterThanOrEqual( time() + 2 * HOUR_IN_SECONDS - 5, (int) $parts[3] );
	}

	/**
	 * Regression for #453: a page rendered 41 minutes before signup finished
	 * (older than the previous 10-minute TTL) must still resume the RSVP.
	 */
	public function test_intent_older_than_previous_ttl_still_resumes(): void {
		$event_id      = $this->create_published_event();
		$rendered_at   = time() - 41 * MINUTE_IN_SECONDS;
		$_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] = $this->signed_token( $event_id, $this->events_blog_id, $rendered_at + EC_USERS_ATTENDANCE_INTENT_TTL );

		$intent = ec_users_get_attendance_intent( $event_id, $this->events_blog_id );

		$this->assertIsArray( $intent );
		$this->assertSame( $event_id, (int) $intent['event_id'] );
	}

	/** A changed signature must not become pending state. */
	public function test_tampered_intent_fails_closed(): void {
		$token                                    = ec_users_create_attendance_intent( 123, 456 );
		$_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] = substr( $token, 0, -1 ) . ( 'a' === substr( $token, -1 ) ? 'b' : 'a' );

		$this->assertNull( ec_users_get_attendance_intent( 123, 456 ) );
	}

	/** An expired signed token must not be replayable. */
	public function test_expired_intent_fails_closed(): void {
		$_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] = $this->signed_token( 123, 456, time() - 1 );

		$this->assertNull( ec_users_get_attendance_intent( 123, 456 ) );
	}

	/** The signed target must match the page event and canonical blog. */
	public function test_wrong_event_and_blog_do_not_replay_intent(): void {
		$_GET[ EC_USERS_ATTENDANCE_INTENT_PARAM ] = ec_users_create_attendance_intent( 123, 456 );

		$this->assertNull( ec_users_get_attendance_intent( 124, 456 ) );
		$this->assertNull( ec_users_get_attendance_intent( 123, 457 ) );
	}
}
