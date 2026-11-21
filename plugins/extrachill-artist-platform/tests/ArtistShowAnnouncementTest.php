<?php
/**
 * New-show announcements to an artist's mailing list (#1725).
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistShowAnnouncementTest extends EC_Artist_Platform_TestCase {
	/** @var int */
	private $profile_id;

	/** @var int */
	private $term_id;

	/** @var array[] Captured queued emails. */
	private $sent = array();

	/** @var bool[] Queue results to return, consumed in order (default success). */
	private $queue_results = array();

	protected function setUp(): void {
		parent::setUp();

		$artist           = $this->create_bound_artist( 'Futurebirds' );
		$this->profile_id = $artist['profile_id'];
		$this->term_id    = $artist['term_id'];
		$this->sent       = array();

		// Run batches inline so each test observes the whole send.
		$this->ec_inject_filter( 'extrachill_artist_show_announcement_async', '__return_false' );
		$this->ec_inject_filter(
			'extrachill_artist_show_announcement_now',
			static function () {
				return strtotime( '2030-01-01 12:00:00 UTC' );
			}
		);

		$test = $this;
		$this->ec_register_test_ability(
			'datamachine/send-email-queued',
			array(
				'label'               => 'Test queued email',
				'description'         => 'Captures queued email for assertions.',
				'category'            => 'extrachill-artist-platform',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) use ( $test ) {
					return $test->capture_email( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	protected function tearDown(): void {
		// The test mail ability must never outlive a test.
		$registry = WP_Abilities_Registry::get_instance();
		if ( $registry->is_registered( 'datamachine/send-email-queued' ) ) {
			$registry->unregister( 'datamachine/send-email-queued' );
		}
		parent::tearDown();
	}

	/**
	 * Record one queued email and return the configured result.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function capture_email( array $input ): array {
		$success = empty( $this->queue_results ) ? true : array_shift( $this->queue_results );
		if ( $success ) {
			$this->sent[] = $input;
		}
		return array( 'success' => $success );
	}

	/** Run a callback on the artist blog with the subscriber table installed. */
	private function on_artist_blog( callable $callback ) {
		switch_to_blog( $this->artist_blog_id() );
		extrachill_artist_create_subscribers_table();
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/** Insert a subscriber row directly and return its ID. */
	private function add_subscriber( string $email, string $source = 'artist_subscribe_form', int $artist_id = 0 ): int {
		$artist_id = $artist_id ? $artist_id : $this->profile_id;
		return (int) $this->on_artist_blog(
			static function () use ( $email, $source, $artist_id ) {
				global $wpdb;
				$wpdb->insert(
					$wpdb->prefix . 'artist_subscribers',
					array(
						'artist_profile_id' => $artist_id,
						'subscriber_email'  => $email,
						'source'            => $source,
						'subscribed_at'     => current_time( 'mysql', true ),
					)
				);
				return $wpdb->insert_id;
			}
		);
	}

	/** A canonical show signal as Events emits it. */
	private function signal( array $overrides = array() ): array {
		return array_merge(
			array(
				'event_key'       => '7:123',
				'title'           => 'Futurebirds with Friends',
				'url'             => 'https://events.extrachill.test/events/futurebirds-with-friends/',
				'venue'           => 'The Basement',
				'starts_at'       => '2030-02-14T20:00:00-06:00',
				'has_time'        => true,
				'status'          => 'EventScheduled',
				'ticket_url'      => 'https://tickets.example.test/futurebirds',
				'artist_term_ids' => array( $this->term_id ),
			),
			$overrides
		);
	}

	/** Deliver a signal on the artist blog. */
	private function publish( array $signal ): array {
		return $this->on_artist_blog(
			static function () use ( $signal ) {
				return extrachill_artist_handle_show_published( $signal );
			}
		);
	}

	/** Recipient addresses of captured emails. */
	private function recipients(): array {
		$to = array_column( $this->sent, 'to' );
		sort( $to );
		return $to;
	}

	public function test_sends_one_email_per_subscriber_and_never_resends_on_replay(): void {
		$this->add_subscriber( 'one@example.com' );
		$this->add_subscriber( 'two@example.com' );
		$this->add_subscriber( 'follower@example.com', 'platform_follow_consent' );

		$first = $this->publish( $this->signal() );

		$this->assertSame( 1, $first['announcements'] );
		$this->assertSame( array( 'one@example.com', 'two@example.com' ), $this->recipients() );

		$email = $this->sent[0];
		$this->assertSame( 'New show: Futurebirds at The Basement, Feb 14', $email['subject'] );
		$this->assertSame( 'extrachill/branded', $email['template'] );
		$this->assertStringContainsString( 'Futurebirds mailing list', $email['context']['body_html'] );
		$this->assertStringContainsString( 'artists/subscribers/unsubscribe', $email['context']['body_html'] );
		$this->assertStringContainsString( 'Unsubscribe from Futurebirds', $email['context']['body_html'] );
		$this->assertStringContainsString( 'tickets.example.test/futurebirds', $email['context']['body_html'] );
		$this->assertStringContainsString( 'events.extrachill.test/events/futurebirds-with-friends/', $email['context']['cta_url'] );

		// Edits, re-imports, and re-publishes re-signal the same event: no resend,
		// even for someone who joined the list after the announcement.
		$this->add_subscriber( 'late@example.com' );
		$replay = $this->publish( $this->signal( array( 'title' => 'Futurebirds (updated)' ) ) );
		$this->assertSame( 0, $replay['announcements'] );
		$this->assertSame( 1, $replay['existing'] );
		$this->assertCount( 2, $this->sent );

		// A replayed batch skips everyone already emailed.
		$batch = $this->on_artist_blog(
			function () {
				return extrachill_artist_run_show_announcement_batch( $this->profile_id, $this->signal(), 0 );
			}
		);
		$this->assertSame( 2, $batch['existing'] );
		$this->assertCount( 3, $this->sent );
		$this->assertSame( 'late@example.com', $this->sent[2]['to'] );
	}

	public function test_batches_page_through_the_whole_list(): void {
		$this->ec_inject_filter(
			'extrachill_artist_show_announcement_batch_size',
			static function () {
				return 2;
			}
		);
		foreach ( array( 'a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com' ) as $email ) {
			$this->add_subscriber( $email );
		}

		$this->publish( $this->signal() );

		$this->assertSame( array( 'a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com' ), $this->recipients() );
	}

	public function test_batches_queue_through_action_scheduler(): void {
		require_once __DIR__ . '/support/action-scheduler-recorder.php';
		remove_all_filters( 'extrachill_artist_show_announcement_async' );
		$GLOBALS['ec_artist_platform_test_queued_actions'] = array();
		$this->add_subscriber( 'one@example.com' );

		$this->publish( $this->signal() );

		if ( ! empty( $GLOBALS['ec_artist_platform_test_queued_actions'] ) ) {
			$queued = $GLOBALS['ec_artist_platform_test_queued_actions'][0];
			$this->assertSame( EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_BATCH_HOOK, $queued['hook'] );
			$this->assertSame( EXTRACHILL_ARTIST_SHOW_ANNOUNCEMENT_GROUP, $queued['group'] );
			$this->assertSame( $this->profile_id, $queued['args'][0] );
		}
		$this->assertSame( array(), $this->sent, 'The signal handler itself never sends; batches do.' );
	}

	public function test_past_and_cancelled_shows_never_send(): void {
		$this->add_subscriber( 'one@example.com' );

		$past = $this->publish( $this->signal( array( 'starts_at' => '2029-12-31T20:00:00-06:00' ) ) );
		$this->assertSame( 'not_announceable', $past['status'] );

		foreach ( array( 'EventCancelled', 'EventPostponed' ) as $index => $status ) {
			$result = $this->publish(
				$this->signal(
					array(
						'event_key' => '7:' . ( 200 + $index ),
						'status'    => $status,
					)
				)
			);
			$this->assertSame( 'not_announceable', $result['status'] );
		}

		$this->assertSame( array(), $this->sent );

		// A date-only show stays announceable through the end of its day.
		$today = $this->publish(
			$this->signal(
				array(
					'event_key' => '7:300',
					'starts_at' => '2030-01-01T00:00:00+00:00',
					'has_time'  => false,
				)
			)
		);
		$this->assertSame( 1, $today['announcements'] );
	}

	public function test_unsubscribed_people_are_not_emailed(): void {
		$gone = $this->add_subscriber( 'gone@example.com' );
		$this->add_subscriber( 'stays@example.com' );
		$this->on_artist_blog(
			static function () use ( $gone ) {
				return extrachill_artist_delete_subscriber( $gone );
			}
		);

		$this->publish( $this->signal() );

		$this->assertSame( array( 'stays@example.com' ), $this->recipients() );
	}

	public function test_failed_enqueue_releases_the_receipt_for_retry(): void {
		$this->add_subscriber( 'one@example.com' );
		$this->queue_results = array( false );

		$this->publish( $this->signal() );
		$this->assertSame( array(), $this->sent );

		$retry = $this->on_artist_blog(
			function () {
				return extrachill_artist_run_show_announcement_batch( $this->profile_id, $this->signal(), 0 );
			}
		);
		$this->assertSame( 1, $retry['sent'] );
		$this->assertSame( array( 'one@example.com' ), $this->recipients() );
	}

	public function test_unbound_artist_terms_are_skipped(): void {
		$this->add_subscriber( 'one@example.com' );
		$unbound = $this->create_artist_term( 'nobody-band' );

		$result = $this->publish( $this->signal( array( 'artist_term_ids' => array( $unbound ) ) ) );

		$this->assertSame( 0, $result['announcements'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array(), $this->sent );
	}

	public function test_invalid_signal_is_rejected(): void {
		$this->assertSame( 'invalid', $this->publish( $this->signal( array( 'event_key' => 'nope' ) ) )['status'] );
		$this->assertSame( 'invalid', $this->publish( $this->signal( array( 'url' => 'javascript:alert(1)' ) ) )['status'] );
	}
}
