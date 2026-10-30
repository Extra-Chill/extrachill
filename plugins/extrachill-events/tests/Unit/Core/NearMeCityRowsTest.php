<?php
/**
 * Near Me city grid row tests.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

// Test doubles and their consuming test intentionally share this fixture.
// phpcs:disable WordPress.Files.FileName, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Return a non-negative integer for this isolated fixture.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * Minimal term double mirroring the core shape the helper relies on.
	 */
	class WP_Term {
		/**
		 * Term ID.
		 *
		 * @var int
		 */
		public $term_id;

		/**
		 * Term name.
		 *
		 * @var string
		 */
		public $name;

		/**
		 * Term slug.
		 *
		 * @var string
		 */
		public $slug;

		/**
		 * Copy properties from a plain object, matching core's behavior.
		 *
		 * @param object $term Source object.
		 */
		public function __construct( object $term ) {
			foreach ( get_object_vars( $term ) as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! function_exists( 'get_term' ) ) {
	/**
	 * Return the term fixture registered for this test.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return WP_Term|null
	 */
	function get_term( $term_id, $taxonomy = '' ) {
		unset( $taxonomy );
		return $GLOBALS['ec_near_me_test']['terms'][ (int) $term_id ] ?? null;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	/**
	 * Return the location coordinates fixture registered for this test.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	function get_term_meta( $term_id, $key, $single = false ) {
		unset( $single );
		if ( '_location_coordinates' !== $key ) {
			return '';
		}
		return $GLOBALS['ec_near_me_test']['coordinates'][ (int) $term_id ] ?? '';
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	/**
	 * Return a deterministic term link for this isolated fixture.
	 *
	 * @param WP_Term $term Term object.
	 * @return string
	 */
	function get_term_link( $term ) {
		return $GLOBALS['test_term_link'] ?? 'https://events.example/location/' . $term->slug . '/';
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/location-meta.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/near-me-city-grid.php';

/** Verifies the Near Me city grid consumes canonical upcoming-count rows. */
final class NearMeCityRowsTest extends BookingTestCase {
	/**
	 * Register the fixture globals for a test.
	 *
	 * @param array<int,WP_Term> $terms       Terms keyed by term ID.
	 * @param array<int,string>  $coordinates Coordinates keyed by term ID.
	 * @return void
	 */
	private function register_fixture( array $terms, array $coordinates ): void {
		$GLOBALS['ec_near_me_test'] = array(
			'terms'       => $terms,
			'coordinates' => $coordinates,
		);
	}

	/**
	 * Build a term double.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $name    Term name.
	 * @param string $slug    Term slug.
	 * @return WP_Term
	 */
	private function make_term( int $term_id, string $name, string $slug ): WP_Term {
		return new WP_Term(
			(object) array(
				'term_id' => $term_id,
				'name'    => $name,
				'slug'    => $slug,
			)
		);
	}

	/**
	 * Build an upcoming-count row.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $name    Term name.
	 * @param string $slug    Term slug.
	 * @param int    $count   Upcoming event count.
	 * @return array
	 */
	private function make_row( int $term_id, string $name, string $slug, int $count ): array {
		return array(
			'term_id' => $term_id,
			'name'    => $name,
			'slug'    => $slug,
			'count'   => $count,
			'url'     => 'https://events.example/location/' . $slug . '/',
		);
	}

	/** Drop the fixture globals registered for the finished test. */
	protected function tearDown(): void {
		unset( $GLOBALS['ec_near_me_test'] );
		unset( $GLOBALS['test_term_link'] );
		parent::tearDown();
	}

	/** City rows keep the source's upcoming-count ordering and re-resolve links. */
	public function test_preserves_source_ordering_and_resolves_links(): void {
		$this->register_fixture(
			array(
				11 => $this->make_term( 11, 'Chicago', 'chicago' ),
				22 => $this->make_term( 22, 'Austin', 'austin' ),
				33 => $this->make_term( 33, 'Charleston', 'charleston' ),
			),
			array(
				11 => '41.8781,-87.6298',
				22 => '30.2672,-97.7431',
				33 => '32.7765,-79.9311',
			)
		);

		// Source rows arrive ordered by upcoming count DESC from the ability.
		$rows = array(
			$this->make_row( 22, 'Austin', 'austin', 1168 ),
			$this->make_row( 11, 'Chicago', 'chicago', 1254 ),
			$this->make_row( 33, 'Charleston', 'charleston', 686 ),
		);

		$cities = extrachill_events_near_me_city_rows( $rows );

		$this->assertSame( array( 'austin', 'chicago', 'charleston' ), array_column( $cities, 'slug' ) );
		$this->assertSame( array( 1168, 1254, 686 ), array_column( $cities, 'count' ) );
		$this->assertStringContainsString( '/location/charleston/', $cities[2]['url'] );
	}

	/** Terms without coordinates (states, regions) never reach the grid. */
	public function test_skips_terms_without_coordinates(): void {
		$this->register_fixture(
			array(
				11 => $this->make_term( 11, 'Chicago', 'chicago' ),
				44 => $this->make_term( 44, 'South Carolina', 'south-carolina' ),
			),
			array(
				11 => '41.8781,-87.6298',
			)
		);

		$rows = array(
			$this->make_row( 44, 'South Carolina', 'south-carolina', 900 ),
			$this->make_row( 11, 'Chicago', 'chicago', 1254 ),
		);

		$cities = extrachill_events_near_me_city_rows( $rows );

		$this->assertCount( 1, $cities );
		$this->assertSame( 'chicago', $cities[0]['slug'] );
	}

	/** The grid is capped at the configured number of city cards. */
	public function test_limits_to_twenty_city_cards(): void {
		$terms       = array();
		$coordinates = array();
		$rows        = array();

		for ( $i = 1; $i <= 25; $i++ ) {
			$terms[ $i ]       = $this->make_term( $i, 'City ' . $i, 'city-' . $i );
			$coordinates[ $i ] = '10.0000,-20.0000';
			$rows[]            = $this->make_row( $i, 'City ' . $i, 'city-' . $i, 1000 - $i );
		}

		$this->register_fixture( $terms, $coordinates );

		$cities = extrachill_events_near_me_city_rows( $rows );

		$this->assertCount( 20, $cities );
		$this->assertSame( 'city-1', $cities[0]['slug'] );
		$this->assertSame( 'city-20', $cities[19]['slug'] );
	}

	/** Malformed and empty rows are dropped without breaking the walk. */
	public function test_skips_malformed_and_zero_count_rows(): void {
		$this->register_fixture(
			array(
				22 => $this->make_term( 22, 'Austin', 'austin' ),
			),
			array(
				22 => '30.2672,-97.7431',
			)
		);

		$rows = array(
			'not-a-row',
			array(
				'term_id' => 99,
				'count'   => 0,
				'name'    => 'Ghost town',
				'slug'    => 'ghost-town',
			),
			$this->make_row( 22, 'Austin', 'austin', 1168 ),
		);

		$cities = extrachill_events_near_me_city_rows( $rows );

		$this->assertCount( 1, $cities );
		$this->assertSame( 'austin', $cities[0]['slug'] );
	}

	/** Terms deleted after the counts were cached are dropped. */
	public function test_skips_rows_whose_term_no_longer_exists(): void {
		$this->register_fixture(
			array(),
			array(
				11 => '41.8781,-87.6298',
			)
		);

		$rows   = array( $this->make_row( 11, 'Chicago', 'chicago', 1254 ) );
		$cities = extrachill_events_near_me_city_rows( $rows );

		$this->assertSame( array(), $cities );
	}
}
