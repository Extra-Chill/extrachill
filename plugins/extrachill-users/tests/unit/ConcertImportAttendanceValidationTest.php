<?php
/**
 * Tests for attendance failures during concert imports.
 *
 * @package ExtraChill\Users
 */

use ExtraChill\Users\Concert_Import\ExternalEvent;
use ExtraChill\Users\Concert_Import\EventCreator;
use ExtraChill\Users\Concert_Import\ImportOrchestrator;
use ExtraChill\Users\Concert_Import\ImportSource;

/**
 * Verifies import runs truthfully expose rejected attendance writes.
 */
class Test_Concert_Import_Attendance_Validation extends WP_UnitTestCase {

	/**
	 * Canonical Events site fixture ID.
	 *
	 * @var int
	 */
	private int $events_blog_id;

	/**
	 * Authenticated import user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Controlled external event fixture.
	 *
	 * @var ExternalEvent
	 */
	private ExternalEvent $external_event;

	/**
	 * Create import tables, source, user, and Events site fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->events_blog_id = self::factory()->blog->create();
		$this->user_id        = self::factory()->user->create();
		$this->external_event = new ExternalEvent(
			array(
				'date'       => '2025-01-15',
				'venue_name' => 'Fixture Room',
				'headliner'  => 'Fixture Band',
				'source_id'  => 'draft-external-id',
			)
		);

		wp_set_current_user( $this->user_id );
		add_filter( 'extrachill_users_events_blog_id', array( $this, 'filter_events_blog_id' ) );
		add_filter( 'extrachill_concert_import_sources', array( $this, 'replace_sources' ), 999 );
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type( 'data_machine_events', array( 'public' => true ) );
		}

		extrachill_users_install_concert_tracking_table();
		extrachill_users_install_concert_import_runs_table();
		$this->clear_tables();
	}

	/**
	 * Remove shared rows and test filters.
	 */
	protected function tearDown(): void {
		$this->clear_tables();
		remove_filter( 'extrachill_concert_import_sources', array( $this, 'replace_sources' ), 999 );
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
	 * Replace production sources with the controlled source.
	 *
	 * @param array $sources Registered sources.
	 * @return ImportSource[]
	 */
	public function replace_sources( array $sources ): array {
		return array(
			new class( $this->external_event ) implements ImportSource {

				/**
				 * Event returned by the source.
				 *
				 * @var ExternalEvent
				 */
				private ExternalEvent $event;

				/**
				 * Initialize the controlled source.
				 *
				 * @param ExternalEvent $event Event fixture.
				 */
				public function __construct( ExternalEvent $event ) {
					$this->event = $event;
				}

				/** Return the fixture source slug. */
				public function slug(): string {
					return 'attendance-validation';
				}

				/** Return the fixture source label. */
				public function label(): string {
					return 'Attendance Validation';
				}

				/** Return an unrestricted rate limit. */
				public function rate_limit(): array {
					return array();
				}

				/** Report that the fixture is configured. */
				public function is_configured(): bool {
					return true;
				}

				/**
				 * Return a successful preview.
				 *
				 * @param string $username External username.
				 * @return array<string, mixed>
				 */
				public function preview( string $username ) {
					return array(
						'total'    => 1,
						'username' => $username,
					);
				}

				/**
				 * Return the controlled event page.
				 *
				 * @param string $username External username.
				 * @param int    $page Page number.
				 * @return array<string, mixed>
				 */
				public function fetch_page( string $username, int $page ) {
					return array(
						'events'      => array( $this->event ),
						'total_pages' => 1,
						'total'       => 1,
						'page'        => $page,
					);
				}
			},
		);
	}

	/**
	 * An unpublished external-ID match fails instead of claiming attendance.
	 */
	public function test_unpublished_external_id_match_fails_run_truthfully(): void {
		$original_blog_id = get_current_blog_id();
		$event_id         = $this->create_imported_event( 'draft' );
		$start            = ImportOrchestrator::start( $this->user_id, 'attendance-validation', 'fixture-user' );
		$this->assertFalse( is_wp_error( $start ) );

		ImportOrchestrator::process( (int) $start['run_id'] );

		$this->assertSame( $original_blog_id, get_current_blog_id() );
		$this->assertFalse( ec_users_is_event_marked( $this->user_id, $event_id, $this->events_blog_id ) );

		$status_ability = wp_get_ability( 'extrachill/get-concert-import-status' );
		$this->assertNotNull( $status_ability );
		$status = $status_ability->execute( array( 'limit' => 1 ) );
		$run    = $status['runs'][0];

		$this->assertSame( ImportOrchestrator::STATUS_FAILED, $run['status'] );
		$this->assertSame( 1, $run['total_events_seen'] );
		$this->assertSame( 0, $run['total_events_matched'] );
		$this->assertSame( 0, $run['total_events_created'] );
		$this->assertSame( 1, $run['total_events_unmatched'] );
		$this->assertStringContainsString( 'event_not_published', $run['error_message'] );
		$this->assertStringContainsString( $this->external_event->label(), $run['error_message'] );
	}

	/**
	 * Create an event carrying the source's stable import identifiers.
	 *
	 * @param string $status Event post status.
	 * @return int
	 */
	private function create_imported_event( string $status ): int {
		switch_to_blog( $this->events_blog_id );
		try {
			$event_id = self::factory()->post->create(
				array(
					'post_type'   => 'data_machine_events',
					'post_status' => $status,
				)
			);
			update_post_meta( $event_id, EventCreator::META_IMPORT_SOURCE, 'attendance-validation' );
			update_post_meta( $event_id, EventCreator::META_IMPORT_EXTERNAL_ID, $this->external_event->source_id );
			return $event_id;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Clear network import and attendance tables between tests.
	 */
	private function clear_tables(): void {
		global $wpdb;
		$tracking_table = extrachill_users_concert_tracking_table_name();
		$imports_table  = extrachill_users_concert_import_runs_table_name();
		$wpdb->query( "DELETE FROM {$tracking_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
		$wpdb->query( "DELETE FROM {$imports_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
	}
}
