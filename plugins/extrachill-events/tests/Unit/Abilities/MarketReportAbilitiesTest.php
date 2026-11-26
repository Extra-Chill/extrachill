<?php
/**
 * Market demand attribution tests.
 *
 * @package ExtraChillEvents\Tests
 */

namespace ExtraChillEvents\Abilities;

// Isolated namespaced doubles avoid changing shared WordPress test helpers.
// phpcs:disable WordPress.Files.FileName, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile

/** Return the hierarchy used by the attribution fixture. */
function get_terms( $args ) {
	return $GLOBALS['market_test_terms'];
}

require_once dirname( __DIR__, 3 ) . '/inc/Abilities/MarketReportAbilities.php';

/** Verifies bulk attribution, breakdowns, and combined scoring. */
final class MarketReportAbilitiesTest extends \BookingTestCase {
	/** Invoke a pure report helper without registering WordPress hooks. */
	private function invoke( string $name, ...$args ) {
		$class  = new \ReflectionClass( MarketReportAbilities::class );
		$method = $class->getMethod( $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $class->newInstanceWithoutConstructor(), $args );
	}

	/** Child and directly assigned city terms must not double count an event. */
	public function test_bulk_slug_rollup_and_event_only_demand(): void {
		$GLOBALS['market_test_terms'] = array(
			(object) array(
				'term_id' => 1,
				'parent'  => 0,
				'slug'    => 'ohio',
			),
			(object) array(
				'term_id' => 2,
				'parent'  => 1,
				'slug'    => 'columbus',
			),
			(object) array(
				'term_id' => 3,
				'parent'  => 2,
				'slug'    => 'downtown',
			),
		);
		$previous                     = $GLOBALS['wpdb'] ?? null;
		$db                           = new class() {
			public $posts              = 'wp_posts';
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy      = 'wp_term_taxonomy';
			public $queries            = 0;
			public $slugs              = array();
			public function prepare( $sql, ...$slugs ) {
				$this->slugs = $slugs;
				return $sql;
			}
			public function get_results( $sql ) {
				++$this->queries;
				return array(
					(object) array(
						'post_name' => 'live-show',
						'term_id'   => 3,
					),
					(object) array(
						'post_name' => 'live-show',
						'term_id'   => 2,
					),
				);
			}
		};
		$GLOBALS['wpdb']              = $db;
		try {
			$row     = array(
				'path'        => '/events/live-show/',
				'clicks'      => 114,
				'impressions' => 4906,
				'position'    => 8,
			);
			$markets = $this->invoke( 'getEventMarkets', array( $row, $row, array( 'path' => '/events/missing/' ) ), array( $GLOBALS['market_test_terms'][1] ) );
			$this->assertSame( 1, $db->queries );
			$this->assertSame( array( 'live-show', 'missing' ), $db->slugs );
			$this->assertSame( array( 'live-show' => array( 'columbus' => true ) ), $markets );
			$data = $this->invoke(
				'aggregateDemand',
				array(
					'archive' => array(),
					'events'  => array(
						$row,
						array(
							'path'        => '/events/missing/',
							'impressions' => 999,
						),
					),
				),
				$markets,
				true
			);
			$this->assertSame( 4906, $data['columbus']['impressions'] );
			$this->assertSame( 114, $data['columbus']['events']['clicks'] );
			$this->assertSame( 0, $data['columbus']['archive']['impressions'] );
		} finally {
			$GLOBALS['wpdb'] = $previous;
			unset( $GLOBALS['market_test_terms'] );
		}
	}

	/** Both sources contribute to totals and impression-weighted positions. */
	public function test_archive_and_events_combine_for_search_traffic_and_score(): void {
		$markets = array( 'show' => array( 'columbus' => true ) );
		$rows    = array(
			'archive' => array(
				array(
					'path'            => '/location/columbus/',
					'clicks'          => 2,
					'impressions'     => 100,
					'position'        => 10,
					'sessions'        => 3,
					'screenPageViews' => 4,
				),
			),
			'events'  => array(
				array(
					'path'            => '/events/show/',
					'clicks'          => 8,
					'impressions'     => 300,
					'position'        => 2,
					'sessions'        => 7,
					'screenPageViews' => 9,
				),
			),
		);
		$gsc     = $this->invoke( 'aggregateDemand', $rows, $markets, true )['columbus'];
		$ga      = $this->invoke( 'aggregateDemand', $rows, $markets, false )['columbus'];
		$this->assertSame( 400, $gsc['impressions'] );
		$this->assertSame( 10, $gsc['clicks'] );
		$this->assertSame( 4.0, $gsc['position'] );
		$this->assertSame( 300, $gsc['events']['impressions'] );
		$this->assertSame( 100, $gsc['archive']['impressions'] );
		$this->assertSame( 10, $ga['sessions'] );
		$this->assertSame( 13, $ga['pageviews'] );
		$this->assertSame( 7, $ga['events']['sessions'] );
		$this->assertSame( 1255.0, $this->invoke( 'calculateOpportunityScore', 10, 0, array( 'venue_scrapers' => 1 ), $ga, $gsc ) );
	}

	/** Empty regional parents are excluded, but empty leaf cities remain. */
	public function test_excludes_zero_event_regions_even_with_slug_filter(): void {
		$GLOBALS['market_test_terms'] = array(
			(object) array( 'term_id' => 1, 'parent' => 0, 'slug' => 'world', 'count' => 0 ),
			(object) array( 'term_id' => 2, 'parent' => 1, 'slug' => 'usa', 'count' => 0 ),
			(object) array( 'term_id' => 3, 'parent' => 2, 'slug' => 'california', 'count' => 0 ),
			(object) array( 'term_id' => 4, 'parent' => 3, 'slug' => 'los-angeles', 'count' => 10 ),
			(object) array( 'term_id' => 5, 'parent' => 3, 'slug' => 'empty-city', 'count' => 0 ),
		);
		try {
			$this->assertSame( array( 'los-angeles', 'empty-city' ), array_values( array_map( fn( $t ) => $t->slug, $this->invoke( 'getLocations', null ) ) ) );
			$this->assertSame( array(), $this->invoke( 'getLocations', 'california' ) );
		} finally {
			unset( $GLOBALS['market_test_terms'] );
		}
	}

	/** Failed sub-calls expose coverage and unknown metrics, not zeroes. */
	public function test_failed_request_is_unavailable(): void {
		$ability = new class() {
			public function get_input_schema() {
				return array( 'properties' => array( 'limit' => array( 'maximum' => 500 ) ) );
			}
			public function execute( $args ) {
				return '/location/' === $args['page_filter']
					? new \WP_Error( 'ga_failed', 'GA unavailable.' )
					: array( 'success' => true, 'results' => array() );
			}
		};
		$GLOBALS['ec_test_ability_resolver'] = static fn() => $ability;
		$rows = $this->invoke( 'getDemandRows', 28, false );
		$this->assertSame( 'unavailable', $rows['coverage']['archive']['status'] );
		$this->assertSame( 'GA unavailable.', $rows['coverage']['archive']['message'] );
		$this->assertSame( 'available', $rows['coverage']['events']['status'] );
		$data = $this->invoke( 'applyCoverage', $this->invoke( 'emptyDemand', false ), $rows['coverage'] );
		$this->assertNull( $data['sessions'] );
		$this->assertNull( $data['archive']['sessions'] );
		$this->assertSame( 0, $data['events']['sessions'] );
	}

	/** Queries request both sources at the declared limit. */
	public function test_fetches_archive_and_event_pages(): void {
		$calls                               = array();
		$ability                             = new class( $calls ) {
			private $calls;
			public function __construct( &$calls ) {
				$this->calls =& $calls;
			}
			public function get_input_schema() {
				return array( 'properties' => array( 'limit' => array( 'maximum' => 10000 ) ) );
			}
			public function execute( $args ) {
				if ( $args['limit'] > 10000 ) {
					return new \WP_Error( 'invalid_limit', 'Limit exceeds maximum.' );
				}
				$this->calls[] = $args;
				return array(
					'success' => true,
					'results' => array( array( 'keys' => array( 'https://events.extrachill.com/events/show/?ref=test' ) ) ),
				);
			}
		};
		$GLOBALS['ec_test_ability_resolver'] = static fn() => $ability;
		$rows                                = $this->invoke( 'getDemandRows', 28, true );
		$this->invoke( 'getDemandRows', 28, false );
		$this->assertCount( 4, $calls );
		$this->assertSame( 'events.extrachill.com/events/', $calls[1]['url_filter'] );
		$this->assertSame( '/events/', $calls[3]['page_filter'] );
		$this->assertSame( array( 10000, 10000, 10000, 10000 ), array_column( $calls, 'limit' ) );
		$this->assertSame( '/events/show/', $rows['events'][0]['path'] );
	}
}
