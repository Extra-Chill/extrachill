<?php
/**
 * Canonical event locations ability tests.
 *
 * @package ExtraChillEvents\Tests
 */

require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-locations.php';

final class CanonicalLocationsAbilityTest extends WP_UnitTestCase {
	private int $original_blog_id;

	protected function setUp(): void {
		parent::setUp();

		$this->original_blog_id = get_current_blog_id();
		wp_cache_set(
			$this->original_blog_id,
			new WP_Site(
				(object) array(
					'blog_id'    => $this->original_blog_id,
					'domain'     => 'example.org',
					'path'       => '/',
					'site_id'    => 1,
					'registered' => current_time( 'mysql' ),
					'last_updated' => current_time( 'mysql' ),
					'public'     => 1,
					'archived'   => 0,
					'mature'     => 0,
					'spam'       => 0,
					'deleted'    => 0,
					'lang_id'    => 0,
				)
			),
			'sites'
		);
		register_taxonomy( 'location', 'post', array( 'public' => true ) );
		add_filter( 'extrachill_events_canonical_blog_id', array( $this, 'canonical_blog_id' ) );

		$usa            = self::factory()->term->create( array( 'taxonomy' => 'location', 'name' => 'USA', 'slug' => 'usa' ) );
		$south_carolina = self::factory()->term->create( array( 'taxonomy' => 'location', 'name' => 'South Carolina', 'slug' => 'south-carolina', 'parent' => $usa ) );
		$texas          = self::factory()->term->create( array( 'taxonomy' => 'location', 'name' => 'Texas', 'slug' => 'texas', 'parent' => $usa ) );
		$charleston_sc  = self::factory()->term->create( array( 'taxonomy' => 'location', 'name' => 'Charleston', 'slug' => 'charleston-sc', 'parent' => $south_carolina ) );
		self::factory()->term->create( array( 'taxonomy' => 'location', 'name' => 'Charleston', 'slug' => 'charleston-tx', 'parent' => $texas ) );

		update_term_meta( $charleston_sc, '_location_coordinates', '32.7765,-79.9311' );
	}

	protected function tearDown(): void {
		remove_filter( 'extrachill_events_canonical_blog_id', array( $this, 'canonical_blog_id' ) );
		parent::tearDown();
	}

	public function canonical_blog_id(): int {
		return $this->original_blog_id;
	}

	public function test_search_returns_only_selectable_cities_and_restores_blog(): void {
		$result = extrachill_events_ability_locations( array( 'mode' => 'search', 'search' => 'charleston' ) );

		$this->assertSame( $this->original_blog_id, get_current_blog_id() );
		$this->assertCount( 2, $result['locations'] );
		$labels = array_column( array_column( $result['locations'], 'hierarchy' ), 'label' );
		sort( $labels );
		$this->assertSame( array( 'Charleston, South Carolina', 'Charleston, Texas' ), $labels );
	}

	public function test_search_excludes_region_and_state_terms(): void {
		$result = extrachill_events_ability_locations( array( 'mode' => 'search', 'search' => 'south' ) );

		$this->assertSame( array(), $result['locations'] );
	}

	public function test_empty_search_has_explicit_empty_response(): void {
		$result = extrachill_events_ability_locations( array( 'mode' => 'search', 'search' => '' ) );

		$this->assertSame( array(), $result['locations'] );
		$this->assertNull( $result['location'] );
		$this->assertSame( $this->original_blog_id, get_current_blog_id() );
	}

	public function test_resolve_returns_coordinates_and_hierarchy(): void {
		$result = extrachill_events_ability_locations( array( 'mode' => 'resolve', 'slug' => 'charleston-sc' ) );

		$this->assertSame( 'charleston-sc', $result['location']['slug'] );
		$this->assertSame( 32.7765, $result['location']['coordinates']['lat'] );
		$this->assertSame( 'USA', $result['location']['hierarchy']['region'] );
		$this->assertSame( get_term_link( get_term_by( 'slug', 'charleston-sc', 'location' ) ), $result['location']['archive_url'] );
		$this->assertSame( $this->original_blog_id, get_current_blog_id() );
	}

	public function test_missing_and_nonselectable_slugs_return_not_found(): void {
		$missing = extrachill_events_ability_locations( array( 'mode' => 'resolve', 'slug' => 'missing' ) );
		$state   = extrachill_events_ability_locations( array( 'mode' => 'resolve', 'slug' => 'texas' ) );

		$this->assertWPError( $missing );
		$this->assertSame( 'No selectable canonical event location matched that slug.', $missing->get_error_message() );
		$this->assertWPError( $state );
		$this->assertSame( $this->original_blog_id, get_current_blog_id() );
	}
}
