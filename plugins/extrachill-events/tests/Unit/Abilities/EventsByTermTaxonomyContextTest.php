<?php
/**
 * Events-by-term taxonomy context tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress test doubles.

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id;
		public string $name;
		public string $slug;
		public int $parent;

		public function __construct( int $term_id, string $name, string $slug, int $parent = 0 ) {
			$this->term_id = $term_id;
			$this->name    = $name;
			$this->slug    = $slug;
			$this->parent  = $parent;
		}
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return false;
	}
}
if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		return $GLOBALS['ec_events_by_term_relationships'][ $post_id ][ $taxonomy ] ?? false;
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return $GLOBALS['ec_events_by_term_term_links'][ $term->term_id ] ?? 'https://events.example/location/' . $term->slug . '/';
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/events-by-term-taxonomy-context.php';

final class EventsByTermTaxonomyContextTest extends WP_UnitTestCase {
	private function term( int $term_id, string $name, string $slug ): WP_Term {
		$constructor = new ReflectionMethod( WP_Term::class, '__construct' );
		if ( 1 === $constructor->getNumberOfParameters() ) {
			return new WP_Term(
				(object) array(
					'term_id'          => $term_id,
					'name'             => $name,
					'slug'             => $slug,
					'term_group'       => 0,
					'term_taxonomy_id' => $term_id,
					'taxonomy'         => 'venue',
					'description'      => '',
					'parent'           => 0,
					'count'            => 0,
					'filter'           => 'raw',
				)
			);
		}

		return new WP_Term( $term_id, $name, $slug );
	}

	protected function setUp(): void {
		parent::setUp();
		register_post_type( 'data_machine_events' );
		foreach ( array( 'venue', 'location', 'festival' ) as $taxonomy ) {
			register_taxonomy( $taxonomy, 'data_machine_events' );
		}
		$GLOBALS['ec_events_by_term_relationships'] = array();
	}

	public function test_enriches_assigned_relationships_without_changing_existing_row_fields(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'data_machine_events' ) );
		$terms   = array();
		foreach (
			array(
				'venue'    => array( 'The Royal American', 'royal-american' ),
				'location' => array( 'Charleston', 'charleston-sc' ),
				'festival' => array( 'High Water Festival', 'high-water-festival' ),
			) as $taxonomy => $term_data
		) {
			$term_id            = self::factory()->term->create(
				array(
					'taxonomy' => $taxonomy,
					'name'     => $term_data[0],
					'slug'     => $term_data[1],
				)
			);
			$terms[ $taxonomy ] = get_term( $term_id, $taxonomy );
			wp_set_object_terms( $post_id, array( $term_id ), $taxonomy );
		}
		$result = extrachill_events_add_events_by_term_taxonomy_context(
			array(
				'upcoming' => array(
					array(
						'event_id'   => $post_id,
						'title'      => 'A Show',
						'venue_name' => 'The Royal American',
					),
				),
				'past'     => array(),
			),
			array()
		);

		$row = $result['upcoming'][0];
		$this->assertSame( 'A Show', $row['title'] );
		$this->assertSame( 'The Royal American', $row['venue_name'] );
		$this->assertSame(
			array(
				'name' => 'The Royal American',
				'slug' => 'royal-american',
				'url'  => get_term_link( $terms['venue'] ),
			),
			$row['relationships']['venue']
		);
		$this->assertSame( 'charleston-sc', $row['relationships']['location']['slug'] );
		$this->assertSame( 'High Water Festival', $row['relationships']['festival']['name'] );
	}

	public function test_returns_null_relationships_when_event_has_no_assigned_terms(): void {
		$result = extrachill_events_add_events_by_term_taxonomy_context(
			array(
				'upcoming' => array( array( 'event_id' => 456 ) ),
				'past'     => array(),
			),
			array()
		);

		$this->assertSame(
			array(
				'venue'    => null,
				'location' => null,
				'festival' => null,
			),
			$result['upcoming'][0]['relationships']
		);
	}

	public function test_declares_relationship_fields_for_both_result_scopes(): void {
		$args = extrachill_events_add_events_by_term_taxonomy_schema(
			array(
				'output_schema' => array(
					'properties' => array(
						'upcoming' => array( 'items' => array( 'type' => 'object' ) ),
						'past'     => array( 'items' => array( 'type' => 'object' ) ),
					),
				),
			),
			'data-machine-events/events-by-term'
		);

		$this->assertSame( array( 'object', 'null' ), $args['output_schema']['properties']['upcoming']['items']['properties']['relationships']['properties']['venue']['type'] );
		$this->assertSame( 'string', $args['output_schema']['properties']['past']['items']['properties']['relationships']['properties']['location']['properties']['display']['type'] );
	}
}
