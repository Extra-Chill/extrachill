<?php
/**
 * Canonical event locations Ability tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress test doubles.

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code, string $message, $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

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

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ) ), '-' );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id() {
		return 7;
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return true;
	}
}
if ( ! function_exists( 'get_site' ) ) {
	function get_site( $blog_id ) {
		return 7 === $blog_id ? (object) array( 'blog_id' => 7 ) : null;
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return $GLOBALS['ec_locations_blog_id'];
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		$GLOBALS['ec_locations_blog_stack'][] = $GLOBALS['ec_locations_blog_id'];
		$GLOBALS['ec_locations_blog_id']      = $blog_id;
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		$GLOBALS['ec_locations_blog_id'] = array_pop( $GLOBALS['ec_locations_blog_stack'] );
		return true;
	}
}
if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists() {
		return true;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id ) {
		return $GLOBALS['ec_locations_terms'][ $term_id ] ?? null;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value ) {
		foreach ( $GLOBALS['ec_locations_terms'] as $term ) {
			if ( 'slug' === $field && $term->slug === $value ) {
				return $term;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $term_id ) {
		$ancestors = array();
		while ( ! empty( $GLOBALS['ec_locations_terms'][ $term_id ]->parent ) ) {
			$term_id     = $GLOBALS['ec_locations_terms'][ $term_id ]->parent;
			$ancestors[] = $term_id;
		}
		return $ancestors;
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args ) {
		$search = strtolower( $args['search'] );
		return array_values(
			array_filter(
				$GLOBALS['ec_locations_terms'],
				static fn( $term ) => false !== strpos( strtolower( $term->name ), $search )
			)
		);
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return 'https://events.example/location/' . $term->slug . '/';
	}
}
if ( ! function_exists( 'extrachill_events_get_location_coordinates' ) ) {
	function extrachill_events_get_location_coordinates( int $term_id ): ?array {
		return $GLOBALS['ec_locations_coordinates'][ $term_id ] ?? null;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-locations.php';

final class CanonicalLocationsAbilityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_locations_blog_id']    = 2;
		$GLOBALS['ec_locations_blog_stack'] = array();
		$GLOBALS['ec_locations_terms']      = array(
			1  => new WP_Term( 1, 'USA', 'usa' ),
			2  => new WP_Term( 2, 'South Carolina', 'south-carolina', 1 ),
			3  => new WP_Term( 3, 'Texas', 'texas', 1 ),
			10 => new WP_Term( 10, 'Charleston', 'charleston-sc', 2 ),
			11 => new WP_Term( 11, 'Charleston', 'charleston-tx', 3 ),
		);
		$GLOBALS['ec_locations_coordinates'] = array(
			10 => array(
				'lat' => 32.7765,
				'lon' => -79.9311,
			),
		);
	}

	public function test_search_returns_only_selectable_cities_and_restores_blog(): void {
		$result = extrachill_events_ability_locations(
			array(
				'mode'   => 'search',
				'search' => 'charleston',
			)
		);

		$this->assertSame( 2, $GLOBALS['ec_locations_blog_id'] );
		$this->assertCount( 2, $result['locations'] );
		$this->assertSame( 'Charleston, South Carolina', $result['locations'][0]['hierarchy']['label'] );
		$this->assertSame( 'Charleston, Texas', $result['locations'][1]['hierarchy']['label'] );
	}

	public function test_search_excludes_region_and_state_terms(): void {
		$result = extrachill_events_ability_locations(
			array(
				'mode'   => 'search',
				'search' => 'south',
			)
		);

		$this->assertSame( array(), $result['locations'] );
	}

	public function test_empty_search_has_explicit_empty_response(): void {
		$result = extrachill_events_ability_locations( array( 'mode' => 'search', 'search' => '' ) );

		$this->assertSame( array(), $result['locations'] );
		$this->assertNull( $result['location'] );
		$this->assertSame( 2, $GLOBALS['ec_locations_blog_id'] );
	}

	public function test_resolve_returns_coordinates_and_hierarchy(): void {
		$result = extrachill_events_ability_locations( array( 'mode' => 'resolve', 'slug' => 'charleston-sc' ) );

		$this->assertSame( 'charleston-sc', $result['location']['slug'] );
		$this->assertSame( 32.7765, $result['location']['coordinates']['lat'] );
		$this->assertSame( 'USA', $result['location']['hierarchy']['region'] );
		$this->assertSame( 'https://events.example/location/charleston-sc/', $result['location']['archive_url'] );
		$this->assertSame( 2, $GLOBALS['ec_locations_blog_id'] );
	}

	public function test_missing_and_nonselectable_slugs_return_not_found(): void {
		$missing = extrachill_events_ability_locations( array( 'mode' => 'resolve', 'slug' => 'missing' ) );
		$state   = extrachill_events_ability_locations( array( 'mode' => 'resolve', 'slug' => 'texas' ) );

		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'No selectable canonical event location matched that slug.', $missing->get_error_message() );
		$this->assertInstanceOf( WP_Error::class, $state );
		$this->assertSame( 2, $GLOBALS['ec_locations_blog_id'] );
	}
}
