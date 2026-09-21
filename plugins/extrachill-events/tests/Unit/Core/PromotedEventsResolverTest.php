<?php
/**
 * Promoted-event render surfaces: resolver, caching, and render-nothing
 * contract (#866).
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/promoted-events-stubs.php';
require_once __DIR__ . '/Stubs/promoted-events-dm-event-dates-table-double.php';
require_once __DIR__ . '/Stubs/promoted-events-dm-upcoming-filter-double.php';

if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_DIR' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 3 ) . '/' );
}
if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_URL' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_URL', 'https://events.example/wp-content/plugins/extrachill-events/' );
}
if ( ! defined( 'EXTRACHILL_EVENTS_VERSION' ) ) {
	define( 'EXTRACHILL_EVENTS_VERSION', 'tests' );
}

require_once dirname( __DIR__, 3 ) . '/inc/admin/priority-events.php';

/**
 * Covers extrachill_get_promoted_events_for_location_term(), its
 * self-invalidating cache, and the render-nothing guards on both surfaces.
 */
final class PromotedEventsResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['promoted_events_test_cache'] = array();
		global $wpdb;
		$wpdb = new \PromotedEventsFakeWpdb();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['promoted_events_test_cache'],
			$GLOBALS['test_is_tax'],
			$GLOBALS['test_queried_object'],
			$GLOBALS['test_is_user_logged_in'],
			$GLOBALS['test_current_user_id'],
			$GLOBALS['test_post_titles'],
			$GLOBALS['test_post_venues'],
			$GLOBALS['test_event_dates'],
			$GLOBALS['test_local_scene']
		);
		global $wpdb;
		$wpdb = null;
		parent::tearDown();
	}

	/** No location term ID: return empty without touching cache or $wpdb. */
	public function test_returns_empty_for_invalid_location_term_id(): void {
		$this->seed_priority_ids( array( 486727 ) );

		$this->assertSame( array(), extrachill_get_promoted_events_for_location_term( 0 ) );
		$this->assertSame( array(), extrachill_get_promoted_events_for_location_term( -5 ) );

		global $wpdb;
		$this->assertSame( array(), $wpdb->queries, 'No SQL should run for an invalid term ID.' );
	}

	/** No priority events at all: return empty without touching $wpdb. */
	public function test_returns_empty_when_no_priority_events(): void {
		$this->seed_priority_ids( array() );

		$this->assertSame( array(), extrachill_get_promoted_events_for_location_term( 1618 ) );

		global $wpdb;
		$this->assertSame( array(), $wpdb->queries, 'An empty priority-ID set must short-circuit before any query.' );
	}

	/** The resolver issues one bounded, prepared query scoped to the priority IDs and location. */
	public function test_query_shape_is_bounded_to_priority_ids_and_location(): void {
		$this->seed_priority_ids( array( 486727, 151581 ) );

		global $wpdb;
		$wpdb->seeded_post_ids = array( 486727 );

		$result = extrachill_get_promoted_events_for_location_term( 1618, 3 );

		$this->assertSame( array( 486727 ), $result );
		$this->assertCount( 1, $wpdb->queries );

		$sql = $wpdb->queries[0];
		$this->assertStringContainsString( 'IN (486727,151581)', $sql, 'Only the two known priority IDs are ever eligible — never an unbounded scan.' );
		$this->assertStringContainsString( "p.post_status = 'publish'", $sql );
		$this->assertStringContainsString( "tt.taxonomy = 'location'", $sql );
		$this->assertStringContainsString( 'tt.term_id = 1618', $sql );
		$this->assertStringContainsString( 'ORDER BY ed.start_datetime ASC', $sql );
		$this->assertStringContainsString( 'LIMIT 3', $sql );
	}

	/** limit is clamped to the 1-3 promotion bound, never a second calendar. */
	public function test_limit_is_clamped_to_one_through_three(): void {
		$this->seed_priority_ids( array( 486727 ) );
		global $wpdb;

		extrachill_get_promoted_events_for_location_term( 1618, 10 );
		$this->assertStringContainsString( 'LIMIT 3', $wpdb->queries[0] );

		$wpdb->queries                         = array();
		$GLOBALS['promoted_events_test_cache'] = array();
		$this->seed_priority_ids( array( 486727 ) );
		extrachill_get_promoted_events_for_location_term( 1618, 0 );
		$this->assertStringContainsString( 'LIMIT 1', $wpdb->queries[0] );
	}

	/** A second call for the same location and the same priority set is served from cache. */
	public function test_repeat_call_is_served_from_cache(): void {
		$this->seed_priority_ids( array( 486727 ) );
		global $wpdb;
		$wpdb->seeded_post_ids = array( 486727 );

		$first  = extrachill_get_promoted_events_for_location_term( 1618 );
		$second = extrachill_get_promoted_events_for_location_term( 1618 );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $wpdb->queries, 'Second call must be served from cache, not re-query.' );
	}

	/**
	 * Self-invalidating cache: when the priority-ID set changes (the same
	 * transition `set-priority-event` and the shop grant already produce by
	 * clearing 'extrachill_priority_event_ids'), the derived cache key
	 * changes with it and the resolver re-queries rather than serving a
	 * stale promotion. This is the exact bug the issue calls out as the
	 * most likely real-world failure mode.
	 */
	public function test_cache_self_invalidates_when_priority_set_changes(): void {
		$this->seed_priority_ids( array( 486727 ) );
		global $wpdb;
		$wpdb->seeded_post_ids = array( 486727 );

		$before = extrachill_get_promoted_events_for_location_term( 1618 );
		$this->assertSame( array( 486727 ), $before );
		$this->assertCount( 1, $wpdb->queries );

		// Simulate un-promotion via the same path every existing invalidation
		// site already uses: clearing 'extrachill_priority_event_ids'.
		$this->seed_priority_ids( array() );

		$after = extrachill_get_promoted_events_for_location_term( 1618 );
		$this->assertSame( array(), $after, 'An un-promoted event must never keep rendering from a prior cache entry.' );
	}

	/** class_exists() guards the data-machine-events soft dependency — asserted at the source level. */
	public function test_resolver_guards_the_data_machine_events_soft_dependency(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/admin/priority-events.php' );

		$this->assertStringContainsString( "class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' )", $source );
		$this->assertStringContainsString( "class_exists( '\\DataMachineEvents\\Blocks\\Calendar\\Query\\UpcomingFilter' )", $source );
	}

	/**
	 * Archive callout: the template re-verifies the queried term itself
	 * (taxonomy === 'location'), mirroring location-venue-badges.php's
	 * defense-in-depth guard behind the actions.php is_tax() check.
	 */
	public function test_archive_template_guards_on_location_taxonomy_and_own_term(): void {
		$GLOBALS['test_queried_object'] = null;

		ob_start();
		include dirname( __DIR__, 3 ) . '/inc/templates/location-promoted-events.php';
		$this->assertSame( '', ob_get_clean(), 'Off a location archive, the template must render nothing.' );

		$this->seed_priority_ids( array( 486727 ) );
		global $wpdb;
		$wpdb->seeded_post_ids = array( 486727 );

		$GLOBALS['test_queried_object'] = (object) array(
			'term_id'  => 1618,
			'taxonomy' => 'location',
		);
		$GLOBALS['test_post_titles']    = array( 486727 => 'Extra Chill Meetup' );

		ob_start();
		include dirname( __DIR__, 3 ) . '/inc/templates/location-promoted-events.php';
		$output = ob_get_clean();

		$this->assertStringContainsString( 'promoted-events--archive', $output );
		$this->assertStringContainsString( 'promoted-event-card', $output );
		$this->assertStringContainsString( 'Extra Chill Meetup', $output );
	}

	/** Archive callout renders nothing when its own term has no promoted upcoming events. */
	public function test_archive_template_renders_nothing_without_promoted_events(): void {
		$this->seed_priority_ids( array() );
		$GLOBALS['test_queried_object'] = (object) array(
			'term_id'  => 1618,
			'taxonomy' => 'location',
		);

		ob_start();
		include dirname( __DIR__, 3 ) . '/inc/templates/location-promoted-events.php';
		$this->assertSame( '', ob_get_clean() );
	}

	/** Homepage card: logged-out visitors get nothing — no geolocation fallback. */
	public function test_homepage_card_renders_nothing_when_logged_out(): void {
		$this->seed_priority_ids( array( 486727 ) );
		$GLOBALS['test_is_user_logged_in'] = false;

		ob_start();
		include dirname( __DIR__, 3 ) . '/inc/home/promoted-event-card.php';
		$this->assertSame( '', ob_get_clean() );
	}

	/** Homepage card: a logged-in user with no Local Scene set gets nothing. */
	public function test_homepage_card_renders_nothing_without_local_scene(): void {
		$this->seed_priority_ids( array( 486727 ) );
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_current_user_id']   = 42;
		$GLOBALS['test_local_scene']       = null;

		ob_start();
		include dirname( __DIR__, 3 ) . '/inc/home/promoted-event-card.php';
		$this->assertSame( '', ob_get_clean() );
	}

	/** Homepage card: Local Scene resolved, but that scene has no promoted events — nothing renders. */
	public function test_homepage_card_renders_nothing_on_local_scene_mismatch(): void {
		$this->seed_priority_ids( array( 486727 ) );
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_current_user_id']   = 42;
		$GLOBALS['test_local_scene']       = array(
			'term_id' => 9999,
			'name'    => 'Chicago',
		);

		global $wpdb;
		// Chicago has no promoted events; the fake $wpdb yields no rows.
		$wpdb->seeded_post_ids = array();

		ob_start();
		include dirname( __DIR__, 3 ) . '/inc/home/promoted-event-card.php';
		$this->assertSame( '', ob_get_clean() );
	}

	/** Homepage card renders for a logged-in user whose Local Scene has a promoted event. */
	public function test_homepage_card_renders_for_matching_local_scene(): void {
		$this->seed_priority_ids( array( 486727 ) );
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_current_user_id']   = 42;
		$GLOBALS['test_post_titles']       = array( 486727 => 'Extra Chill Meetup' );
		$GLOBALS['test_local_scene']       = array(
			'term_id' => 1618,
			'name'    => 'Charleston',
		);

		global $wpdb;
		$wpdb->seeded_post_ids = array( 486727 );

		ob_start();
		include dirname( __DIR__, 3 ) . '/inc/home/promoted-event-card.php';
		$output = ob_get_clean();

		$this->assertStringContainsString( 'promoted-events--home', $output );
		$this->assertStringContainsString( 'Extra Chill Meetup', $output );
		$this->assertStringContainsString( 'Charleston', $output );
	}

	/** Hook priorities: archive callout at 4 (before venue badges at 5); homepage card at 18 (badges 10, stats 15, feature cards 20). */
	public function test_hook_priorities_match_the_issue_spec(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/home/actions.php' );

		$this->assertStringContainsString(
			"add_action( 'extrachill_archive_below_description', 'extrachill_events_location_archive_promoted_events', 4 )",
			$source
		);
		$this->assertStringContainsString(
			"add_action( 'extrachill_events_home_before_calendar', 'extrachill_events_home_promoted_event_card', 18 )",
			$source
		);

		// The promoted-event callout registers before the venue badges (5) in
		// source order, and the homepage card after location badges (10) and
		// stats (15) but before feature cards (20).
		$promoted_archive_pos = strpos( $source, 'extrachill_events_location_archive_promoted_events' );
		$venue_badges_pos     = strpos( $source, 'extrachill_events_location_archive_venue_badges' );
		$this->assertLessThan( $venue_badges_pos, $promoted_archive_pos );

		$stats_pos         = strpos( $source, 'extrachill_events_home_calendar_stats' );
		$promoted_home_pos = strpos( $source, 'extrachill_events_home_promoted_event_card' );
		$feature_cards_pos = strpos( $source, 'extrachill_events_home_feature_cards' );
		$this->assertLessThan( $promoted_home_pos, $stats_pos );
		$this->assertLessThan( $feature_cards_pos, $promoted_home_pos );
	}

	/** Every CSS class this feature introduces exists in the plugin's own stylesheet. */
	public function test_every_introduced_css_class_exists_in_plugin_stylesheet(): void {
		$css = file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/calendar.css' );

		foreach (
			array(
				'.promoted-events',
				'.promoted-events--archive',
				'.promoted-events--home',
				'.promoted-events__heading',
				'.promoted-event-card',
				'.promoted-event-card__label',
				'.promoted-event-card__title',
				'.promoted-event-card__date',
				'.promoted-event-card__venue',
			) as $class
		) {
			$this->assertStringContainsString( $class, $css, "Missing plugin-shipped style for {$class}" );
		}
	}

	/**
	 * Seed the priority-event ID cache directly — the same short-circuit
	 * extrachill_get_priority_event_ids() takes on a cache hit, and the same
	 * key every real invalidation site (admin save, setPriorityEvent(), the
	 * shop grant) already clears.
	 *
	 * @param int[] $ids Priority event post IDs.
	 */
	private function seed_priority_ids( array $ids ): void {
		$GLOBALS['promoted_events_test_cache']['extrachill-events']['extrachill_priority_event_ids'] = $ids;
	}
}
