<?php
// phpcs:ignoreFile -- Managed WordPress fixture follows the repository test convention.
/**
 * Tests for the public Events entity projection owner contract.
 *
 * @package ExtraChillEvents\Tests
 */

require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-locations.php';
require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-public-entity-projections.php';

/**
 * Verify exact schemas, owner resolution, visibility, and failures.
 */
final class PublicEntityProjectionsAbilityTest extends WP_UnitTestCase {
	/**
	 * Canonical Events blog fixture.
	 *
	 * @var int
	 */
	private int $blog_id;

	/**
	 * Selectable location fixture.
	 *
	 * @var int
	 */
	private int $city;

	/**
	 * Whether this fixture registered the Events ability category.
	 *
	 * @var bool
	 */
	private bool $registered_category = false;

	/** Register canonical taxonomies and the ability. */
	protected function setUp(): void {
		parent::setUp();

		$this->blog_id = get_current_blog_id();
		add_filter( 'extrachill_events_canonical_blog_id', array( $this, 'canonical_blog_id' ) );

		foreach ( array( 'venue', 'location' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		register_taxonomy(
			'venue',
			'post',
			array(
				'public'  => true,
				'rewrite' => array( 'slug' => 'venue' ),
			)
		);
		register_taxonomy(
			'location',
			'post',
			array(
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => array(
					'slug'         => 'location',
					'hierarchical' => true,
				),
			)
		);

		$usa        = self::factory()->term->create(
			array(
				'taxonomy' => 'location',
				'name'     => 'USA',
				'slug'     => 'usa',
			)
		);
		$state      = self::factory()->term->create(
			array(
				'taxonomy' => 'location',
				'name'     => 'South Carolina',
				'slug'     => 'south-carolina',
				'parent'   => $usa,
			)
		);
		$this->city = self::factory()->term->create(
			array(
				'taxonomy' => 'location',
				'name'     => 'Charleston',
				'slug'     => 'charleston-sc',
				'parent'   => $state,
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'venue',
				'name'     => 'The Royal American',
				'slug'     => 'the-royal-american',
			)
		);

		if ( wp_has_ability( 'extrachill/events-public-entity-projections' ) ) {
			wp_unregister_ability( 'extrachill/events-public-entity-projections' );
		}
		if ( ! wp_has_ability_category( 'extrachill-events' ) ) {
			$this->registered_category = true;
			$this->run_registration_lifecycle(
				'wp_abilities_api_categories_init',
				static function (): void {
					wp_register_ability_category(
						'extrachill-events',
						array(
							'label'       => 'Extra Chill Events',
							'description' => 'Extra Chill Events abilities.',
						)
					);
				}
			);
		}
		$this->run_registration_lifecycle( 'wp_abilities_api_init', 'extrachill_events_register_public_entity_projections_ability' );
	}

	/** Restore filters and public taxonomy registrations. */
	protected function tearDown(): void {
		remove_filter( 'extrachill_events_canonical_blog_id', array( $this, 'canonical_blog_id' ) );
		if ( wp_has_ability( 'extrachill/events-public-entity-projections' ) ) {
			wp_unregister_ability( 'extrachill/events-public-entity-projections' );
		}
		if ( $this->registered_category && wp_has_ability_category( 'extrachill-events' ) ) {
			wp_unregister_ability_category( 'extrachill-events' );
		}
		foreach ( array( 'venue', 'location' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		register_taxonomy(
			'venue',
			'post',
			array( 'public' => true )
		);
		register_taxonomy(
			'location',
			'post',
			array(
				'public'       => true,
				'hierarchical' => true,
			)
		);
		parent::tearDown();
	}

	/** Return the current test blog as the canonical Events site. */
	public function canonical_blog_id(): int {
		return $this->blog_id;
	}

	/** Resolve every supported type while preserving order and owner URLs. */
	public function test_resolves_all_types_in_order_with_owner_urls(): void {
		$input = array(
			'schema_version' => '1',
			'items'          => array(
				array(
					'entity_type' => 'local_scene_digest',
					'slug'        => 'charleston-sc',
				),
				array(
					'entity_type' => 'venue',
					'slug'        => 'the-royal-american',
				),
				array(
					'entity_type' => 'location',
					'slug'        => 'charleston-sc',
				),
				array(
					'entity_type' => 'venue',
					'slug'        => 'missing-venue',
				),
			),
		);

		$result       = $this->ability()->execute( $input );
		$location_url = get_term_link( get_term( $this->city, 'location' ) );

		$this->assertIsArray( $result );
		$this->assertSame( '1', $result['schema_version'] );
		$this->assertSame( array( 'local_scene_digest', 'venue', 'location', 'venue' ), array_column( $result['items'], 'entity_type' ) );
		$this->assertSame( array( 'resolved', 'resolved', 'resolved', 'not_found' ), array_column( $result['items'], 'status' ) );
		$this->assertSame( $location_url, $result['items'][0]['url'] );
		$this->assertSame( $location_url, $result['items'][2]['url'] );
		$this->assertSame( 'The Royal American', $result['items'][1]['name'] );
		$this->assertSame( '', $result['items'][3]['name'] );
		$this->assertSame( '', $result['items'][3]['url'] );
		foreach ( $result['items'] as $item ) {
			$this->assertSame( array( 'entity_type', 'slug', 'status', 'name', 'url' ), array_keys( $item ) );
		}
	}

	/** Hide stale, non-selectable, and non-public terms without partial data. */
	public function test_deleted_nonselectable_and_private_terms_are_not_found(): void {
		$deleted = self::factory()->term->create(
			array(
				'taxonomy' => 'venue',
				'name'     => 'Gone',
				'slug'     => 'gone',
			)
		);
		wp_delete_term( $deleted, 'venue' );

		$result = $this->ability()->execute(
			array(
				'schema_version' => '1',
				'items'          => array(
					array(
						'entity_type' => 'venue',
						'slug'        => 'gone',
					),
					array(
						'entity_type' => 'location',
						'slug'        => 'south-carolina',
					),
				),
			)
		);
		$this->assertSame( array( 'not_found', 'not_found' ), array_column( $result['items'], 'status' ) );

		unregister_taxonomy( 'venue' );
		register_taxonomy( 'venue', 'post', array( 'public' => false ) );
		self::factory()->term->create(
			array(
				'taxonomy' => 'venue',
				'name'     => 'Private Room',
				'slug'     => 'private-room',
			)
		);
		$private = $this->ability()->execute(
			array(
				'schema_version' => '1',
				'items'          => array(
					array(
						'entity_type' => 'venue',
						'slug'        => 'private-room',
					),
				),
			)
		);
		$this->assertSame( 'not_found', $private['items'][0]['status'] );
	}

	/** Enforce exact versioned schemas, canonical slugs, and batch bounds. */
	public function test_schema_is_exact_and_rejects_malformed_inputs_and_bounds(): void {
		$ability = $this->ability();
		$schema  = $ability->get_input_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertFalse( $schema['properties']['items']['items']['additionalProperties'] );
		$this->assertSame( 1, $schema['properties']['items']['minItems'] );
		$this->assertSame( 100, $schema['properties']['items']['maxItems'] );
		$this->assertFalse( $ability->get_output_schema()['additionalProperties'] );
		$this->assertFalse( $ability->get_output_schema()['properties']['items']['items']['additionalProperties'] );
		$this->assertSame( 1, $ability->get_output_schema()['properties']['items']['minItems'] );
		$this->assertSame( array( 'schema_version', 'items' ), $schema['required'] );
		$this->assertSame( array( 'entity_type', 'slug' ), $schema['properties']['items']['items']['required'] );
		$this->assertSame( array( 'entity_type', 'slug', 'status', 'name', 'url' ), $ability->get_output_schema()['properties']['items']['items']['required'] );

		$invalid = array(
			array(
				'schema_version' => '1',
				'items'          => array(),
				'extra'          => true,
			),
			array(
				'schema_version' => '2',
				'items'          => array(
					array(
						'entity_type' => 'venue',
						'slug'        => 'valid',
					),
				),
			),
			array(
				'schema_version' => '1',
				'items'          => array(),
			),
			array(
				'schema_version' => '1',
				'items'          => array_fill(
					0,
					101,
					array(
						'entity_type' => 'venue',
						'slug'        => 'valid',
					)
				),
			),
			array(
				'schema_version' => '1',
				'items'          => array(
					array(
						'entity_type' => 'venue',
						'slug'        => 'Not-Canonical',
					),
				),
			),
			array(
				'schema_version' => '1',
				'items'          => array(
					array(
						'entity_type' => 'venue',
						'slug'        => 'valid',
						'extra'       => true,
					),
				),
			),
		);
		foreach ( $invalid as $input ) {
			$result = $ability->execute( $input );
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		}

		$bounded = $ability->execute(
			array(
				'schema_version' => '1',
				'items'          => array_fill(
					0,
					100,
					array(
						'entity_type' => 'venue',
						'slug'        => 'the-royal-american',
					)
				),
			)
		);
		$this->assertIsArray( $bounded );
		$this->assertCount( 100, $bounded['items'] );
	}

	/** Preserve unavailable owner infrastructure as WP_Error responses. */
	public function test_owner_infrastructure_failures_remain_errors(): void {
		add_filter( 'extrachill_events_canonical_blog_id', '__return_zero', 20 );
		$unavailable = $this->ability()->execute( $this->valid_input() );
		remove_filter( 'extrachill_events_canonical_blog_id', '__return_zero', 20 );

		$this->assertWPError( $unavailable );
		$this->assertSame( 'events_site_unavailable', $unavailable->get_error_code() );

		unregister_taxonomy( 'venue' );
		$taxonomy = $this->ability()->execute( $this->valid_input() );
		$this->assertWPError( $taxonomy );
		$this->assertSame( 'events_taxonomy_unavailable', $taxonomy->get_error_code() );
	}

	/** Return the registered owner ability. */
	private function ability(): WP_Ability {
		$ability = wp_get_ability( 'extrachill/events-public-entity-projections' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		return $ability;
	}

	/** Return one valid venue projection request. */
	private function valid_input(): array {
		return array(
			'schema_version' => '1',
			'items'          => array(
				array(
					'entity_type' => 'venue',
					'slug'        => 'the-royal-american',
				),
			),
		);
	}

	/**
	 * Run one registration callback on its required lifecycle without firing unrelated hooks.
	 *
	 * @param string   $hook     Registration lifecycle hook.
	 * @param callable $callback Registration callback.
	 */
	private function run_registration_lifecycle( string $hook, callable $callback ): void {
		$previous = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null;

		remove_all_actions( $hook );
		add_action( $hook, $callback );
		do_action( $hook );
		remove_all_actions( $hook );

		if ( null === $previous ) {
			unset( $GLOBALS['wp_filter'][ $hook ] );
		} else {
			$GLOBALS['wp_filter'][ $hook ] = $previous;
		}
	}
}
