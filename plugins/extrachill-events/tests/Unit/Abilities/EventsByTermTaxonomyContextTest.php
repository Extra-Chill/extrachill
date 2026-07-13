<?php
/**
 * Events-by-term taxonomy context tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress test doubles.

use PHPUnit\Framework\TestCase;

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

final class EventsByTermTaxonomyContextTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_events_by_term_relationships'] = array();
	}

	public function test_enriches_assigned_relationships_without_changing_existing_row_fields(): void {
		$GLOBALS['ec_events_by_term_relationships'][123] = array(
			'venue'    => array( new WP_Term( 10, 'The Royal American', 'royal-american' ) ),
			'location' => array( new WP_Term( 11, 'Charleston', 'charleston-sc' ) ),
			'festival' => array( new WP_Term( 12, 'High Water Festival', 'high-water-festival' ) ),
		);
		$result = extrachill_events_add_events_by_term_taxonomy_context(
			array(
				'upcoming' => array(
					array(
						'event_id'   => 123,
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
				'url'  => 'https://events.example/location/royal-american/',
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
