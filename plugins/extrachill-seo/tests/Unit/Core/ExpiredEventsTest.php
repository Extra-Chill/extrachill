<?php
/**
 * Event retention robots and sitemap policy tests.
 *
 * @package ExtraChill\SEO\Tests
 */

namespace DataMachineEvents\Core {
	class EventDatesTable {
		public static $dates;
		public static function get( int $post_id ): ?object {
			return self::$dates;
		}
		public static function table_name(): string {
			return 'wp_7_datamachine_event_dates';
		}
	}
}

namespace DataMachineEvents\Blocks\Calendar\Query {
	class UpcomingFilter {
		// Matches the owning plugin's public SQL contract.
		public static function past_where( string $datetime ): string {
			return "(ed.start_datetime < '{$datetime}' AND (ed.end_datetime < '{$datetime}' OR ed.end_datetime IS NULL))";
		}
	}
}

namespace ExtraChill\SEO\Core {
	function current_datetime() {
		return new \DateTimeImmutable( '2026-11-18 12:00:00', new \DateTimeZone( 'America/New_York' ) );
	}
	function is_date() {
		return false;
	}
}

namespace {
	if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
		abstract class WP_Sitemaps_Provider {}
	}
}

namespace ExtraChill\SEO\Tests\Unit\Core {

	use DataMachineEvents\Core\EventDatesTable;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__, 2 ) . '/bootstrap.php';
	require_once dirname( __DIR__, 3 ) . '/inc/core/robots.php';
	require_once dirname( __DIR__, 3 ) . '/inc/core/sitemap.php';

	class ExpiredEventsTest extends TestCase {
		private $filters;

		protected function setUp(): void {
			$this->filters = $GLOBALS['ec_seo_test_filters'];
			$GLOBALS['ec_seo_test_is_singular'] = true;
			$GLOBALS['ec_seo_test_queried_object'] = new \WP_Post( array( 'ID' => 42 ) );
			EventDatesTable::$dates = null;
		}

		protected function tearDown(): void {
			$GLOBALS['ec_seo_test_filters'] = $this->filters;
			unset( $GLOBALS['ec_seo_test_is_singular'], $GLOBALS['ec_seo_test_queried_object'] );
			EventDatesTable::$dates = null;
		}

		/** @dataProvider eventDates */
		public function test_robots_retention( $start, $end, $expired ): void {
			EventDatesTable::$dates = $start ? (object) array( 'start_datetime' => $start, 'end_datetime' => $end ) : null;
			$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
			$this->assertSame( $expired, $robots['noindex'] ?? false );
			if ( $expired ) {
				$this->assertTrue( $robots['follow'] );
				$this->assertArrayNotHasKey( 'max-image-preview', $robots );
			}
		}

		public static function eventDates(): array {
			return array(
				'upcoming' => array( '2026-12-01 20:00:00', null, false ),
				'recent' => array( '2026-11-01 20:00:00', null, false ),
				'old' => array( '2026-01-01 20:00:00', '2026-01-01 23:00:00', true ),
				'no end' => array( '2026-01-01 20:00:00', null, true ),
				'ongoing' => array( '2026-01-01 20:00:00', '2026-12-01 23:00:00', false ),
				'boundary' => array( '2026-05-22 12:00:00', null, false ),
				'end boundary' => array( '2026-05-21 12:00:00', '2026-05-22 12:00:00', false ),
				'missing' => array( null, null, false ),
			);
		}

		public function test_filter_changes_both_policies(): void {
			add_filter( 'extrachill_seo_event_retention_days', static fn() => 30 );
			EventDatesTable::$dates = (object) array( 'start_datetime' => '2026-09-01 20:00:00', 'end_datetime' => null );
			$robots = apply_filters( 'wp_robots', array( 'index' => true, 'nofollow' => true ) );
			$this->assertTrue( $robots['noindex'] );
			$this->assertArrayNotHasKey( 'index', $robots );
			$this->assertArrayNotHasKey( 'nofollow', $robots );
			$args = apply_filters( 'wp_sitemaps_posts_query_args', array(), 'data_machine_events' );
			$this->assertSame( '2026-10-19 12:00:00', $args['ec_seo_event_retention_cutoff'] );
		}

		public function test_other_single_posts_are_unchanged(): void {
			$GLOBALS['ec_seo_test_queried_object']->post_type = 'post';
			EventDatesTable::$dates = (object) array( 'start_datetime' => '2020-01-01 00:00:00', 'end_datetime' => null );
			$this->assertSame( array(), apply_filters( 'wp_robots', array() ) );
		}

		public function test_sitemap_clause_is_scoped_and_preserves_query_arguments(): void {
			$args = array( 'post_type' => 'data_machine_events', 'posts_per_page' => 2000, 'paged' => 2, 'fields' => 'ids' );
			$filtered = apply_filters( 'wp_sitemaps_posts_query_args', $args, 'data_machine_events' );
			$this->assertSame( 2, $filtered['paged'] );
			$this->assertSame( 2000, $filtered['posts_per_page'] );
			$this->assertFalse( $filtered['suppress_filters'] );
			$this->assertSame( '2026-05-22 12:00:00', $filtered['ec_seo_event_retention_cutoff'] );
			$previous = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['wpdb'] = (object) array( 'posts' => 'wp_7_posts' );
			try {
				$query = new class( $filtered ) {
					private $args;
					public function __construct( $args ) { $this->args = $args; }
					public function get( $key ) { return $this->args[ $key ] ?? ''; }
				};
				$where = apply_filters( 'posts_where', ' AND post_status = \'publish\'', $query );
				$this->assertSame( " AND post_status = 'publish' AND NOT EXISTS (SELECT 1 FROM wp_7_datamachine_event_dates ed WHERE ed.post_id = wp_7_posts.ID AND (ed.start_datetime < '2026-05-22 12:00:00' AND (ed.end_datetime < '2026-05-22 12:00:00' OR ed.end_datetime IS NULL)))", $where );
				$this->assertSame( $where, apply_filters( 'posts_where', ' AND post_status = \'publish\'', $query ), 'Count and URL queries share a stable predicate.' );
				$this->assertSame( array(), apply_filters( 'wp_sitemaps_posts_query_args', array(), 'post' ) );
				$unmarked = new class {
					public function get( $key ) { return 'post_type' === $key ? 'data_machine_events' : ''; }
				};
				$this->assertSame( 'original', apply_filters( 'posts_where', 'original', $unmarked ) );
			} finally {
				$GLOBALS['wpdb'] = $previous;
			}
		}
	}
}
