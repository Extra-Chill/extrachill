<?php
/**
 * Attendance funnel analytics (Extra-Chill/extrachill-users#450).
 *
 * @package ExtraChill\Users
 */

use DataMachineEvents\Core\EventDatesTable;

// Shared fixture helpers (abilities registered on wp_abilities_api_init, mapped sites).
require_once dirname( __DIR__ ) . '/support/fixtures.php';

// Canonical names live in extrachill-analytics (#309). Mirror them so this
// suite also runs against an analytics checkout that predates them.
foreach ( array(
	'EC_ANALYTICS_EVENT_ATTENDANCE_MARKED'         => 'attendance_marked',
	'EC_ANALYTICS_EVENT_ATTENDANCE_UNMARKED'       => 'attendance_unmarked',
	'EC_ANALYTICS_EVENT_ATTENDANCE_INTENT_RESUMED' => 'attendance_intent_resumed',
) as $ec_constant => $ec_value ) {
	if ( ! defined( $ec_constant ) ) {
		define( $ec_constant, $ec_value );
	}
}

/**
 * The interactive set-event-mark path emits one canonical event per transition.
 */
class Test_Attendance_Funnel_Analytics extends WP_UnitTestCase {

	private int $events_blog_id;

	/** @var int[] */
	private array $event_ids = array();

	private int $user_id;

	private bool $registered_fake_analytics = false;

	/**
	 * Owner analytics registration moved aside for the fixture.
	 *
	 * @var WP_Ability|null
	 */
	private ?WP_Ability $displaced_analytics = null;

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
			EventDatesTable::create_table();
			foreach ( array( 1, 2 ) as $n ) {
				$event_id          = self::factory()->post->create(
					array(
						'post_type'   => 'data_machine_events',
						'post_status' => 'publish',
					)
				);
				EventDatesTable::upsert( $event_id, '2099-07-2' . $n . ' 20:00:00', null, 'publish' );
				$this->event_ids[] = $event_id;
			}
		} finally {
			restore_current_blog();
		}

		extrachill_users_install_concert_tracking_table();
		$this->clear_tracking_table();
		wp_set_current_user( $this->user_id );

		$GLOBALS['ec_attendance_funnel_events'] = array();
		// Move any owner registration aside so emits are captured regardless of
		// which plugins are active or which suite ran first.
		$this->displaced_analytics       = ec_users_tests_swap_ability(
			'extrachill/track-analytics-event',
			array(
				'label'               => 'Test analytics',
				'description'         => 'Captures attendance funnel events.',
				'category'            => 'extrachill-users',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'integer' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					$GLOBALS['ec_attendance_funnel_events'][] = $input;
					return count( $GLOBALS['ec_attendance_funnel_events'] );
				},
			)
		);
		$this->registered_fake_analytics = true;
	}

	protected function tearDown(): void {
		$this->clear_tracking_table();
		remove_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		if ( $this->registered_fake_analytics ) {
			ec_users_tests_restore_ability( 'extrachill/track-analytics-event', $this->displaced_analytics );
		}
		unset( $GLOBALS['ec_attendance_funnel_events'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function filter_events_blog_id(): int {
		return $this->events_blog_id;
	}

	public function test_first_mark_emits_marked_with_first_mark_true_and_bounded_payload(): void {
		$result = $this->set( $this->event_ids[0], true );

		$this->assertTrue( $result['first_mark'] );
		$events = $this->events();
		$this->assertCount( 1, $events );
		$this->assertSame( EC_ANALYTICS_EVENT_ATTENDANCE_MARKED, $events[0]['event_type'] );
		$this->assertSame(
			array(
				'user_id'    => $this->user_id,
				'event_id'   => $this->event_ids[0],
				'blog_id'    => $this->events_blog_id,
				'timing'     => 'upcoming',
				'first_mark' => true,
				'source'     => 'button',
			),
			$events[0]['event_data']
		);
	}

	public function test_second_mark_is_not_first_and_noop_remark_emits_nothing(): void {
		$this->set( $this->event_ids[0], true );
		$second = $this->set( $this->event_ids[1], true );
		$noop   = $this->set( $this->event_ids[1], true );

		$this->assertFalse( $second['first_mark'] );
		$this->assertFalse( $noop['changed'] );
		$this->assertFalse( $noop['first_mark'] );
		$events = $this->events();
		$this->assertCount( 2, $events, 'A no-op re-mark must not emit.' );
		$this->assertFalse( $events[1]['event_data']['first_mark'] );
	}

	public function test_unmark_emits_unmarked_only_on_transition(): void {
		$this->set( $this->event_ids[0], true );
		$this->set( $this->event_ids[0], false );
		$this->set( $this->event_ids[0], false );

		$types = wp_list_pluck( $this->events(), 'event_type' );
		$this->assertSame( array( EC_ANALYTICS_EVENT_ATTENDANCE_MARKED, EC_ANALYTICS_EVENT_ATTENDANCE_UNMARKED ), $types );
	}

	public function test_intent_continuation_emits_resumed_then_marked(): void {
		$this->set( $this->event_ids[0], true, 'intent' );

		$events = $this->events();
		$this->assertSame(
			array( EC_ANALYTICS_EVENT_ATTENDANCE_INTENT_RESUMED, EC_ANALYTICS_EVENT_ATTENDANCE_MARKED ),
			wp_list_pluck( $events, 'event_type' )
		);
		$this->assertSame( 'intent', $events[1]['event_data']['source'] );
	}

	public function test_intent_on_already_marked_event_still_records_resume(): void {
		$this->set( $this->event_ids[0], true );
		$this->set( $this->event_ids[0], true, 'intent' );

		$this->assertSame(
			array( EC_ANALYTICS_EVENT_ATTENDANCE_MARKED, EC_ANALYTICS_EVENT_ATTENDANCE_INTENT_RESUMED ),
			wp_list_pluck( $this->events(), 'event_type' )
		);
	}

	public function test_service_layer_marks_used_by_imports_do_not_emit(): void {
		ec_users_mark_event( $this->user_id, $this->event_ids[0], $this->events_blog_id );

		$this->assertSame( array(), $this->events() );
	}

	public function test_unknown_source_is_rejected_by_the_input_schema(): void {
		$result = wp_get_ability( 'extrachill/set-event-mark' )->execute(
			array(
				'event_id' => $this->event_ids[0],
				'blog_id'  => $this->events_blog_id,
				'marked'   => true,
				'source'   => 'email',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->events() );
	}

	private function set( int $event_id, bool $marked, string $source = 'button' ): array {
		$result = wp_get_ability( 'extrachill/set-event-mark' )->execute(
			array(
				'event_id' => $event_id,
				'blog_id'  => $this->events_blog_id,
				'marked'   => $marked,
				'source'   => $source,
			)
		);
		$this->assertIsArray( $result );
		return $result;
	}

	private function events(): array {
		return $GLOBALS['ec_attendance_funnel_events'];
	}

	private function clear_tracking_table(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . extrachill_users_concert_tracking_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table helper in a test.
	}
}
