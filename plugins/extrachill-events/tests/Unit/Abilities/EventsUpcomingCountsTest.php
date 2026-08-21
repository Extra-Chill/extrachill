<?php
/**
 * Events upcoming-count tests.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

// Test doubles and their consuming test intentionally share this fixture.
// phpcs:disable WordPress.Files.FileName,Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Return a normalized title for this isolated fixture.
	 *
	 * @param mixed $title Raw title.
	 * @return string
	 */
	function sanitize_title( $title ) {
		return strtolower( trim( (string) $title ) );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	/**
	 * Report whether the requested public event taxonomy exists.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	function taxonomy_exists( $taxonomy ) {
		return in_array( $taxonomy, array( 'venue', 'location', 'artist', 'festival' ), true );
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	/**
	 * Return the location fixture used by location-scoped venue requests.
	 *
	 * @param string $field    Lookup field.
	 * @param string $value    Lookup value.
	 * @param string $taxonomy Taxonomy slug.
	 * @return object
	 */
	function get_term_by( $field, $value, $taxonomy ) {
		unset( $field, $value, $taxonomy );
		return (object) array( 'term_id' => 42 );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-upcoming-counts.php';

/** Verifies public upcoming counts always reflect the owning ability. */
final class EventsUpcomingCountsTest extends BookingTestCase {
	/**
	 * Every supported bulk request must read through to current DME inventory.
	 *
	 * @param array $input         Extra Chill ability input.
	 * @param array $expected_args Expected owning ability input.
	 *
	 * @dataProvider bulkRequestProvider
	 */
	public function test_bulk_requests_do_not_reuse_stale_results( array $input, array $expected_args ): void {
		$executions = array();
		$ability    = new class( $executions ) {
			/**
			 * Owning ability executions.
			 *
			 * @var array<int,array>
			 */
			private array $executions;

			/**
			 * Store executions by reference for assertions.
			 *
			 * @param array $executions Execution log.
			 */
			public function __construct( array &$executions ) {
				$this->executions =& $executions;
			}

			/**
			 * Return inventory that changes on every execution.
			 *
			 * @param array $args Owning ability arguments.
			 * @return array
			 */
			public function execute( array $args ): array {
				$this->executions[] = $args;
				return array(
					'terms' => array(
						array(
							'term_id' => 7,
							'name'    => 'Current inventory',
							'slug'    => 'current-inventory',
							'count'   => count( $this->executions ),
							'url'     => 'https://events.example/current-inventory/',
						),
					),
				);
			}
		};

		$GLOBALS['ec_test_ability_resolver'] = static function ( string $name ) use ( $ability ) {
			return 'data-machine-events/get-upcoming-counts' === $name ? $ability : null;
		};

		$first  = extrachill_events_ability_upcoming_counts( $input );
		$second = extrachill_events_ability_upcoming_counts( $input );

		$this->assertSame( 1, $first[0]['count'] );
		$this->assertSame( 2, $second[0]['count'] );
		$this->assertSame( array( $expected_args, $expected_args ), $executions );
	}

	/** Provide every supported bulk result shape. */
	public static function bulkRequestProvider(): array {
		return array(
			'location'              => array(
				array( 'taxonomy' => 'location' ),
				array( 'taxonomy' => 'location' ),
			),
			'venue'                 => array(
				array( 'taxonomy' => 'venue' ),
				array( 'taxonomy' => 'venue' ),
			),
			'artist'                => array(
				array( 'taxonomy' => 'artist' ),
				array( 'taxonomy' => 'artist' ),
			),
			'festival'              => array(
				array( 'taxonomy' => 'festival' ),
				array( 'taxonomy' => 'festival' ),
			),
			'location rollup'       => array(
				array(
					'taxonomy' => 'location',
					'rollup'   => true,
				),
				array(
					'taxonomy' => 'location',
					'rollup'   => true,
				),
			),
			'location-scoped venue' => array(
				array(
					'taxonomy'      => 'venue',
					'location_slug' => 'charleston-sc',
				),
				array(
					'taxonomy'        => 'venue',
					'filter_taxonomy' => 'location',
					'filter_term_id'  => 42,
				),
			),
		);
	}
}
