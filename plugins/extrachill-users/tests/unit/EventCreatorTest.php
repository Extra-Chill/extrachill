<?php
/**
 * Unit tests for EventCreator's idempotency lookup + (where feasible without
 * a full events-blog fixture) basic event-creation behaviour.
 *
 * These tests target the new "create on miss" path added in #54. The full
 * end-to-end create flow needs the events subsite with venue/artist
 * taxonomies registered — exercising that here would require booting the
 * data-machine-events plugin and switching to the events blog. Instead we:
 *
 *  1. Exercise `EventCreator::find_by_external_id()` against an event we
 *     stamp directly with the audit/idempotency meta.
 *  2. Exercise `EventCreator::create()` happy path in the current blog, then
 *     verify the audit meta was written. The taxonomies-not-registered
 *     branches degrade gracefully (no terms set) — that's intentional so the
 *     orchestrator still records the import even when run outside the events
 *     blog in test contexts.
 */

use ExtraChill\Users\Concert_Import\EventCreator;
use ExtraChill\Users\Concert_Import\ExternalEvent;

class Test_Event_Creator extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type(
				'data_machine_events',
				array(
					'public'  => false,
					'show_ui' => false,
				)
			);
		}
	}

	public function test_find_by_external_id_returns_null_when_no_match_exists(): void {
		$this->assertNull(
			EventCreator::find_by_external_id( 'setlist-fm', 'nonexistent-id' )
		);
	}

	public function test_find_by_external_id_returns_null_for_empty_inputs(): void {
		$this->assertNull( EventCreator::find_by_external_id( '', 'whatever' ) );
		$this->assertNull( EventCreator::find_by_external_id( 'setlist-fm', '' ) );
	}

	public function test_find_by_external_id_locates_a_previously_imported_event(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
				'post_title'  => 'Imported Show',
			)
		);
		update_post_meta( $post_id, EventCreator::META_IMPORT_SOURCE, 'setlist-fm' );
		update_post_meta( $post_id, EventCreator::META_IMPORT_EXTERNAL_ID, 'abc-123' );

		$this->assertSame(
			$post_id,
			EventCreator::find_by_external_id( 'setlist-fm', 'abc-123' )
		);
	}

	public function test_find_by_external_id_scopes_to_the_correct_source(): void {
		// A phish.net show with the same external_id as a hypothetical
		// setlist.fm show must not collide — sources have independent
		// id namespaces.
		$phish_post = self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $phish_post, EventCreator::META_IMPORT_SOURCE, 'phish-net' );
		update_post_meta( $phish_post, EventCreator::META_IMPORT_EXTERNAL_ID, 'shared-id' );

		$this->assertSame(
			$phish_post,
			EventCreator::find_by_external_id( 'phish-net', 'shared-id' )
		);
		$this->assertNull(
			EventCreator::find_by_external_id( 'setlist-fm', 'shared-id' ),
			'External ids are scoped per source and must not cross-match.'
		);
	}

	public function test_create_returns_null_for_unmatchable_payload(): void {
		// Missing venue_name → not matchable → no creation.
		$event = new ExternalEvent(
			array(
				'date'      => '2024-06-01',
				'headliner' => 'Some Band',
				'source_id' => 'no-venue',
			)
		);
		$this->assertNull( EventCreator::create( $event, 'setlist-fm' ) );
	}

	public function test_create_writes_audit_meta_and_returns_post_id(): void {
		$event = new ExternalEvent(
			array(
				'date'       => '2024-06-15',
				'venue_name' => 'Test Venue',
				'city'       => 'Charleston',
				'state'      => 'SC',
				'country'    => 'United States',
				'headliner'  => 'Test Band',
				'source_id'  => 'created-by-test-123',
			)
		);

		$post_id = EventCreator::create( $event, 'setlist-fm' );
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		$this->assertSame(
			'setlist-fm',
			get_post_meta( $post_id, EventCreator::META_IMPORT_SOURCE, true )
		);
		$this->assertSame(
			'created-by-test-123',
			get_post_meta( $post_id, EventCreator::META_IMPORT_EXTERNAL_ID, true )
		);

		// The newly-stamped event must now be discoverable via external_id —
		// this is the idempotency contract that prevents re-imports from
		// duplicating events.
		$this->assertSame(
			$post_id,
			EventCreator::find_by_external_id( 'setlist-fm', 'created-by-test-123' )
		);
	}
}
