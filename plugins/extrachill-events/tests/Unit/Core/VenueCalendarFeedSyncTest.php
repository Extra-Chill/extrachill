<?php
/**
 * Venue calendar feed sync scope tests.
 *
 * The three scope rules are the load-bearing correctness property of feed
 * sync: a sync that could reach outside its own binding would be able to
 * unpublish a scraped event, overwrite a public submission awaiting review, or
 * mutate another venue's calendar.
 *
 * These assert the *query arguments* the runner builds rather than query
 * results, because the scope rules are precisely a statement about which rows
 * the sync is permitted to reach. Building the arguments is pure, so this runs
 * identically with or without WordPress loaded — no function stubs, which
 * cannot bind under the managed bootstrap.
 *
 * @package ExtraChillEvents\Tests
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\VenueCalendarFeed;
use ExtraChillEvents\Core\VenueCalendarFeedSync;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueCalendarFeed.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueCalendarFeedSync.php';

class VenueCalendarFeedSyncTest extends TestCase {

	private function query_args( string $method, array $args ): array {
		$reflection = new \ReflectionMethod( VenueCalendarFeedSync::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	private function meta_pairs( array $meta_query ): array {
		$pairs = array();
		foreach ( $meta_query as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'] ) ) {
				$pairs[ $clause['key'] ] = $clause['value'] ?? null;
			}
		}
		return $pairs;
	}

	/**
	 * Scope rule 1. Without all three constraints together, a sync could adopt
	 * an event created by a scraper or by a different venue's feed.
	 */
	public function test_owned_event_query_is_scoped_by_source_identity_and_venue(): void {
		$args  = $this->query_args( 'owned_event_query', array( 1524, 'uid-abc::2026-09-10' ) );
		$pairs = $this->meta_pairs( $args['meta_query'] );

		$this->assertSame( 'AND', $args['meta_query']['relation'] );
		$this->assertSame( VenueCalendarFeed::SOURCE_NAME, $pairs['_datamachine_event_source'] );
		$this->assertSame( 'uid-abc::2026-09-10', $pairs['_datamachine_event_source_id'] );
		$this->assertSame( 1524, $pairs[ VenueCalendarFeedSync::META_FEED_VENUE ] );
	}

	/**
	 * Scope rule 3. Public submissions land pending for human review; a feed
	 * sync adopting one would bypass that review entirely.
	 */
	public function test_owned_event_query_never_matches_pending_events(): void {
		$args = $this->query_args( 'owned_event_query', array( 1524, 'uid-abc::2026-09-10' ) );

		$this->assertNotContains( 'pending', $args['post_status'] );
	}

	public function test_owned_event_query_is_limited_to_a_single_row(): void {
		$args = $this->query_args( 'owned_event_query', array( 1524, 'uid-abc::2026-09-10' ) );

		$this->assertSame( 1, $args['numberposts'] );
		$this->assertSame( 'ids', $args['fields'] );
	}

	/**
	 * The cancellation scan must be scoped identically to the lookup, or a
	 * sync could draft another venue's events.
	 */
	public function test_cancellation_query_is_scoped_to_this_venue_feed(): void {
		$args  = $this->query_args( 'owned_events_query', array( 1524 ) );
		$pairs = $this->meta_pairs( $args['meta_query'] );

		$this->assertSame( 'AND', $args['meta_query']['relation'] );
		$this->assertSame( VenueCalendarFeed::SOURCE_NAME, $pairs['_datamachine_event_source'] );
		$this->assertSame( 1524, $pairs[ VenueCalendarFeedSync::META_FEED_VENUE ] );
	}

	/**
	 * Only published events are candidates for cancellation. Drafts and
	 * pending posts are deliberately out of reach.
	 */
	public function test_cancellation_query_considers_only_published_events(): void {
		$args = $this->query_args( 'owned_events_query', array( 1524 ) );

		$this->assertSame( 'publish', $args['post_status'] );
	}

	public function test_cancellation_query_is_bounded(): void {
		$args = $this->query_args( 'owned_events_query', array( 1524 ) );

		$this->assertSame( VenueCalendarFeedSync::MAX_TRACKED_EVENTS, $args['numberposts'] );
	}

	public function test_both_queries_target_the_event_post_type(): void {
		$single = $this->query_args( 'owned_event_query', array( 1524, 'x' ) );
		$many   = $this->query_args( 'owned_events_query', array( 1524 ) );

		$this->assertSame( 'data_machine_events', $single['post_type'] );
		$this->assertSame( 'data_machine_events', $many['post_type'] );
	}

	/**
	 * A past event ageing out of a rolling feed window is normal and must not
	 * be retroactively cancelled; a future event that disappeared upstream
	 * should be.
	 */
	public function test_only_future_events_are_cancellation_candidates(): void {
		$reflection = new \ReflectionMethod( VenueCalendarFeedSync::class, 'starts_after' );
		$reflection->setAccessible( true );

		$this->assertTrue( $reflection->invoke( null, '2026-12-01 20:00:00', '2026-09-03 12:00:00' ) );
		$this->assertFalse( $reflection->invoke( null, '2026-01-01 20:00:00', '2026-09-03 12:00:00' ) );
	}

	/**
	 * An event with no recorded identity cannot be proven absent from a feed,
	 * so it is never a cancellation candidate.
	 */
	public function test_event_without_identity_is_never_a_candidate(): void {
		$reflection = new \ReflectionMethod( VenueCalendarFeedSync::class, 'starts_after' );
		$reflection->setAccessible( true );

		$this->assertFalse( $reflection->invoke( null, '', '2026-09-03 12:00:00' ) );
	}

	/**
	 * The source name pairs with the ICS occurrence identity to form a
	 * namespace distinct from every other ingestion path.
	 */
	public function test_source_name_is_distinct(): void {
		$this->assertSame( 'venue_calendar_feed', VenueCalendarFeed::SOURCE_NAME );
	}

	public function test_failure_threshold_parks_only_after_repeated_failures(): void {
		$this->assertGreaterThan( 1, VenueCalendarFeed::MAX_CONSECUTIVE_FAILURES );
	}
}
