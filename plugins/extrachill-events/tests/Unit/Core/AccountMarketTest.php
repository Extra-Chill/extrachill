<?php
/**
 * Account market integration tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/wp-stubs.php';
require_once __DIR__ . '/Stubs/class-wpterm.php';

require_once dirname( __DIR__, 3 ) . '/inc/core/router-pages.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/discovery-pages.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/account-market.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/local-scene-digest.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/my-shows-map-filter.php';

/**
 * Verifies the account preference integration and its precedence gates.
 */
final class AccountMarketTest extends TestCase {
	private static int $managed_user_id = 1000;
	private $original_current_user;
	private $original_wp_query;
	private $original_blog_id;
	private $ancestor_filter;
	private $term_link_filter;
	private $original_ability_resolver;
	private array $original_get;

	protected function setUp(): void {
		parent::setUp();
		$this->original_ability_resolver     = $GLOBALS['ec_test_ability_resolver'] ?? null;
		$this->original_get                  = $_GET;
		$GLOBALS['ec_test_ability_resolver'] = static function ( string $name ) {
			if ( 'extrachill/get-user-settings' === $name ) {
				return $GLOBALS['test_account_market_ability'] ?? null;
			}

			return 'extrachill/update-user-settings' === $name ? ( $GLOBALS['test_update_scene_ability'] ?? null ) : null;
		};
		foreach (
			array(
				'test_is_user_logged_in',
				'test_account_market_ability',
				'test_update_scene_ability',
				'test_is_front_page',
				'test_is_all_events_page',
				'test_is_near_me_page',
				'test_page_slug',
				'test_current_user_id',
				'test_is_tax',
				'test_queried_term',
				'test_term_ancestors',
				'test_term_link',
				'test_query_vars',
				'test_nocache_headers',
				'test_status_header',
			) as $key
		) {
			unset( $GLOBALS[ $key ] );
		}
		$_GET                        = array();
		$this->original_current_user = $GLOBALS['current_user'] ?? null;
		$this->original_wp_query     = $GLOBALS['wp_query'] ?? null;
		$this->original_blog_id      = $GLOBALS['blog_id'] ?? null;
		if ( class_exists( 'WP_Abilities_Registry' ) ) {
			$this->register_account_abilities();
			if ( ! taxonomy_exists( 'location' ) ) {
				register_taxonomy( 'location', 'post', array( 'hierarchical' => true ) );
			}
			$this->ancestor_filter  = static function ( $ancestors, $object_id, $object_type ) {
				unset( $object_id );
				return 'location' === $object_type && isset( $GLOBALS['test_term_ancestors'] ) ? $GLOBALS['test_term_ancestors'] : $ancestors;
			};
			$this->term_link_filter = static function ( $url ) {
				return $GLOBALS['test_term_link'] ?? $url;
			};
			add_filter( 'get_ancestors', $this->ancestor_filter, 10, 3 );
			add_filter( 'term_link', $this->term_link_filter );
		}
	}

	protected function tearDown(): void {
		if ( class_exists( 'WP_Abilities_Registry' ) ) {
			remove_filter( 'get_ancestors', $this->ancestor_filter, 10 );
			remove_filter( 'term_link', $this->term_link_filter );
			foreach ( array( 'extrachill/get-user-settings', 'extrachill/update-user-settings' ) as $ability ) {
				if ( wp_has_ability( $ability ) ) {
					wp_unregister_ability( $ability );
				}
			}
			if ( wp_has_ability_category( 'extrachill-events-account-tests' ) ) {
				wp_unregister_ability_category( 'extrachill-events-account-tests' );
			}
		}
		$GLOBALS['current_user'] = $this->original_current_user;
		$GLOBALS['wp_query']     = $this->original_wp_query;
		$GLOBALS['blog_id']      = $this->original_blog_id;
		$_GET                    = $this->original_get;
		if ( null === $this->original_ability_resolver ) {
			unset( $GLOBALS['ec_test_ability_resolver'] );
		} else {
			$GLOBALS['ec_test_ability_resolver'] = $this->original_ability_resolver;
		}
		parent::tearDown();
	}

	private function register_account_abilities(): void {
		foreach ( array( 'extrachill/get-user-settings', 'extrachill/update-user-settings' ) as $ability ) {
			if ( wp_has_ability( $ability ) ) {
				wp_unregister_ability( $ability );
			}
		}
		if ( ! wp_has_ability_category( 'extrachill-events-account-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'extrachill-events-account-tests',
				array(
					'label'       => 'Account market tests',
					'description' => 'Managed account market contract tests.',
				)
			);
		}
		$common = array(
			'category'            => 'extrachill-events-account-tests',
			'permission_callback' => '__return_true',
			'input_schema'        => array( 'type' => 'object' ),
		);
		WP_Abilities_Registry::get_instance()->register(
			'extrachill/get-user-settings',
			array_merge(
				$common,
				array(
					'label'            => 'Get user settings test',
					'description'      => 'Returns the controlled account settings.',
					'execute_callback' => static function () {
						$ability = $GLOBALS['test_account_market_ability'] ?? null;
						return $ability ? $ability->execute() : array();
					},
				)
			)
		);
		WP_Abilities_Registry::get_instance()->register(
			'extrachill/update-user-settings',
			array_merge(
				$common,
				array(
					'label'            => 'Update user settings test',
					'description'      => 'Records the controlled account settings update.',
					'execute_callback' => static function ( array $input ) {
						$ability = $GLOBALS['test_update_scene_ability'] ?? null;
						return $ability ? $ability->execute( $input ) : array();
					},
				)
			)
		);
	}

	private function use_logged_in_user(): void {
		$GLOBALS['test_current_user_id'] = ++self::$managed_user_id;
		if ( class_exists( 'WP_User' ) ) {
			$user                    = new WP_User();
			$user->ID                = $GLOBALS['test_current_user_id'];
			$GLOBALS['current_user'] = $user;
		}
	}

	private function use_anonymous_user(): void {
		$GLOBALS['test_current_user_id'] = 0;
		if ( class_exists( 'WP_User' ) ) {
			$GLOBALS['current_user'] = new WP_User();
		}
	}

	private function use_page_query( string $slug ): void {
		if ( ! isset( $GLOBALS['wp_query'] ) || ! $GLOBALS['wp_query'] instanceof WP_Query ) {
			return;
		}
		$GLOBALS['wp_query']                 = clone $GLOBALS['wp_query'];
		$GLOBALS['wp_query']->is_home        = false;
		$GLOBALS['wp_query']->is_page        = true;
		$GLOBALS['wp_query']->queried_object = new WP_Post(
			(object) array(
				'ID'        => 78,
				'post_name' => $slug,
				'post_type' => 'page',
			)
		);
	}

	private function use_front_page_query(): void {
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query ) {
			$GLOBALS['wp_query']          = clone $GLOBALS['wp_query'];
			$GLOBALS['wp_query']->is_home = true;
			$GLOBALS['wp_query']->is_page = false;
		}
	}

	private function use_all_events_query(): void {
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query ) {
			$GLOBALS['wp_query']             = clone $GLOBALS['wp_query'];
			$GLOBALS['wp_query']->is_home    = false;
			$GLOBALS['wp_query']->query_vars = array( 'ec_events_router' => 'all' );
		}
	}

	private function use_archive_query( WP_Term $term ): void {
		$this->use_events_blog();
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query ) {
			$GLOBALS['wp_query']                    = clone $GLOBALS['wp_query'];
			$GLOBALS['wp_query']->is_home           = false;
			$GLOBALS['wp_query']->is_tax            = true;
			$GLOBALS['wp_query']->queried_object    = $term;
			$GLOBALS['wp_query']->queried_object_id = $term->term_id;
		}
	}
	private function use_events_blog(): void {
		if ( function_exists( 'wp_get_ability' ) ) {
			$GLOBALS['blog_id'] = 7;
		}
	}

	private function all_events_pagination_rule(): array {
		$this->use_events_blog();
		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite ) {
			$GLOBALS['wp_rewrite']->extra_rules_top = array();
			extrachill_events_router_rewrite_rules();
			$query = $GLOBALS['wp_rewrite']->extra_rules_top['^all/page/([0-9]{1,})/?$'] ?? null;
			return null === $query ? array() : array(
				'regex' => '^all/page/([0-9]{1,})/?$',
				'query' => $query,
				'after' => 'top',
			);
		}

		$GLOBALS['test_rewrite_rules'] = array();
		extrachill_events_router_rewrite_rules();
		foreach ( $GLOBALS['test_rewrite_rules'] as $rule ) {
			if ( '^all/page/([0-9]{1,})/?$' === $rule['regex'] ) {
				return $rule;
			}
		}
		return array();
	}

	private function venue_settings_rewrite_rule(): array {
		$this->use_events_blog();
		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite ) {
			$GLOBALS['wp_rewrite']->extra_rules_top = array();
			extrachill_events_router_rewrite_rules();
			$query = $GLOBALS['wp_rewrite']->extra_rules_top['^venue-settings/?$'] ?? null;
			return null === $query ? array() : array(
				'regex' => '^venue-settings/?$',
				'query' => $query,
				'after' => 'top',
			);
		}

		$GLOBALS['test_rewrite_rules'] = array();
		extrachill_events_router_rewrite_rules();
		foreach ( $GLOBALS['test_rewrite_rules'] as $rule ) {
			if ( '^venue-settings/?$' === $rule['regex'] ) {
				return $rule;
			}
		}
		return array();
	}

	private function use_discovery_query( WP_Term $term, string $scope, int $paged ): ?WP_Query {
		$this->use_events_blog();
		if ( ! isset( $GLOBALS['wp_query'] ) || ! $GLOBALS['wp_query'] instanceof WP_Query ) {
			return null;
		}
		if ( ! taxonomy_exists( 'location' ) ) {
			// hierarchical => true matches the real production registration
			// and this class's own setUp() registration (#890) — leaving it
			// off here would silently poison any test that runs later in
			// the same managed PHPUnit process and relies on 'location'
			// behaving hierarchically.
			register_taxonomy( 'location', 'post', array( 'hierarchical' => true ) );
		}

		$original                               = $GLOBALS['wp_query'];
		$GLOBALS['wp_query']                    = clone $original;
		$GLOBALS['wp_query']->is_tax            = true;
		$GLOBALS['wp_query']->query_vars        = array(
			'event_scope' => $scope,
			'paged'       => $paged,
		);
		$GLOBALS['wp_query']->queried_object    = $term;
		$GLOBALS['wp_query']->queried_object_id = $term->term_id;
		return $original;
	}

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
					'taxonomy'         => 'location',
					'description'      => '',
					'parent'           => 0,
					'count'            => 0,
					'filter'           => 'raw',
				)
			);
		}

		return new WP_Term( $term_id, $name, $slug );
	}
	/**
	 */
	public function test_resolves_coordinates_from_user_ability(): void {
		$this->use_logged_in_user();
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'Charleston SC',
						'term_id'     => 1618,
						'name'        => 'Charleston',
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
						'hierarchy'   => array( 'label' => 'Charleston, South Carolina' ),
						'url'         => 'https://events.example/location/charleston-sc/',
					),
				);
			}
		};

		$this->assertSame(
			array(
				'lat'     => 32.7765,
				'lon'     => -79.9311,
				'slug'    => 'charleston-sc',
				'term_id' => 1618,
				'name'    => 'Charleston',
				'label'   => 'Charleston, South Carolina',
				'url'     => 'https://events.example/location/charleston-sc/',
			),
			extrachill_events_get_account_market()
		);
	}

	/**
	 */
	public function test_fails_open_when_ability_is_unavailable(): void {
		$this->use_logged_in_user();
		$GLOBALS['test_is_user_logged_in'] = true;

		$this->assertNull( extrachill_events_get_account_market() );
	}

	/**
	 */
	public function test_adds_taxonomy_default_without_mutating_request_globals(): void {
		$this->use_logged_in_user();
		$this->use_events_blog();
		$this->use_front_page_query();
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_front_page']          = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};
		$_GET                                   = array();
		$result                                 = extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) );

		$this->assertSame( array( 'location' => array( 1618 ) ), $result['tax_filter'] );
		$this->assertSame( array(), $_GET );
	}

	public function test_explicit_geo_and_taxonomy_filters_win(): void {
		$_GET = array(
			'lat' => '40.7',
			'lng' => '-74.0',
		);
		$this->assertTrue( extrachill_events_has_explicit_market() );

		$_GET = array( 'tax_filter' => array( 'location' => array( 42 ) ) );
		$this->assertTrue( extrachill_events_has_explicit_market() );

		$explicit_geo = array(
			'lat' => '40.7',
			'lng' => '-74.0',
		);
		$this->assertSame(
			$explicit_geo,
			extrachill_events_calendar_account_market_defaults( $explicit_geo, array( 'archive_term' => null ) )
		);

		$explicit_taxonomy = array( 'tax_filter' => array( 'location' => array( 42 ) ) );
		$this->assertSame(
			$explicit_taxonomy,
			extrachill_events_calendar_account_market_defaults( $explicit_taxonomy, array( 'archive_term' => null ) )
		);
	}

	/**
	 */
	public function test_explore_all_suppresses_fallback_without_mutating_preference(): void {
		$this->use_logged_in_user();
		$this->use_events_blog();
		$this->use_all_events_query();
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_all_events_page']     = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};
		$_GET                                   = array( 'explore_all' => '1' );

		$this->assertTrue( extrachill_events_is_exploring_all_markets() );
		$this->assertSame( array(), extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) ) );
		$this->assertSame( 1618, extrachill_events_get_account_market()['term_id'] );
		$this->assertSame( array( 'explore_all' => '1' ), $_GET );

		ob_start();
		extrachill_events_render_account_market_context();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Exploring all locations', $output );
		$this->assertStringContainsString( 'Use my Local Scene', $output );
	}

	public function test_explore_all_requires_exact_sanitized_flag(): void {
		$_GET = array( 'explore_all' => 'true' );
		$this->assertFalse( extrachill_events_is_exploring_all_markets() );

		$_GET = array( 'explore_all' => array( '1' ) );
		$this->assertFalse( extrachill_events_is_exploring_all_markets() );
	}

	/**
	 */
	public function test_near_me_adds_geo_defaults_without_taxonomy_filter(): void {
		$this->use_logged_in_user();
		$this->use_events_blog();
		$this->use_page_query( 'near-me' );
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_near_me_page']        = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};

		$result = extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) );

		$this->assertSame( 32.7765, $result['lat'] );
		$this->assertSame( -79.9311, $result['lng'] );
		$this->assertArrayNotHasKey( 'tax_filter', $result );
	}

	public function test_anonymous_request_does_not_resolve_account_market(): void {
		$GLOBALS['test_is_user_logged_in'] = false;

		$this->assertNull( extrachill_events_get_account_market() );
	}

	public function test_supported_surfaces_are_limited_to_primary_discovery_pages(): void {
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query ) {
			$this->use_events_blog();
			$original_query               = $GLOBALS['wp_query'];
			$GLOBALS['wp_query']          = clone $original_query;
			$GLOBALS['wp_query']->is_home = true;
			$GLOBALS['wp_query']->is_page = false;
			$this->assertTrue( extrachill_events_supports_account_market() );

			$GLOBALS['wp_query']->is_home    = false;
			$GLOBALS['wp_query']->query_vars = array( 'ec_events_router' => 'all' );
			$this->assertTrue( extrachill_events_supports_account_market() );

			$GLOBALS['wp_query']->query_vars     = array();
			$GLOBALS['wp_query']->is_page        = true;
			$GLOBALS['wp_query']->queried_object = new WP_Post(
				(object) array(
					'ID'        => 77,
					'post_name' => 'near-me',
					'post_type' => 'page',
				)
			);
			$this->assertTrue( extrachill_events_supports_account_market() );

			$GLOBALS['wp_query']->is_page        = false;
			$GLOBALS['wp_query']->queried_object = null;
			$this->assertFalse( extrachill_events_supports_account_market() );
			$GLOBALS['wp_query'] = $original_query;
			return;
		}

		$GLOBALS['test_is_front_page'] = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_front_page']      = false;
		$GLOBALS['test_is_all_events_page'] = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_all_events_page'] = false;
		$GLOBALS['test_is_near_me_page']    = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_near_me_page'] = false;
		$this->assertFalse( extrachill_events_supports_account_market() );
	}

	/**
	 */
	public function test_my_shows_route_map_uses_account_market_center(): void {
		$this->use_logged_in_user();
		$this->use_page_query( 'my-shows' );
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_current_user_id']        = 42;
		$GLOBALS['test_page_slug']              = 'my-shows';
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};
		$context                                = array(
			'attributes' => array( 'chronologicalRouteMode' => true ),
		);

		$this->assertSame(
			array(
				'lat' => 32.7765,
				'lon' => -79.9311,
			),
			ec_events_my_shows_map_account_center( null, $context )
		);
	}

	public function test_my_shows_center_preserves_stronger_context_and_other_maps(): void {
		$existing = array(
			'lat' => 40.7128,
			'lon' => -74.0060,
		);

		$this->assertSame(
			$existing,
			ec_events_my_shows_map_account_center(
				$existing,
				array( 'attributes' => array( 'chronologicalRouteMode' => true ) )
			)
		);
		$this->assertNull( ec_events_my_shows_map_account_center( null, array() ) );
	}

	public function test_location_directory_is_enabled_by_default(): void {
		$this->assertTrue( extrachill_events_location_directory_enabled() );
	}

	public function test_all_events_pagination_rewrite_routes_upcoming_page_two(): void {
		$this->assertSame(
			array(
				'regex' => '^all/page/([0-9]{1,})/?$',
				'query' => 'index.php?ec_events_router=all&paged=$matches[1]',
				'after' => 'top',
			),
			$this->all_events_pagination_rule()
		);
	}

	public function test_venue_settings_rewrite_uses_existing_router_surface(): void {
		$this->assertSame(
			array(
				'regex' => '^venue-settings/?$',
				'query' => 'index.php?ec_events_router=venue-settings',
				'after' => 'top',
			),
			$this->venue_settings_rewrite_rule()
		);
	}

	public function test_all_events_pagination_rewrite_keeps_past_requests_on_router_page(): void {
		$pagination_rule = $this->all_events_pagination_rule();
		$this->assertNotEmpty( $pagination_rule );
		$this->assertSame( 'index.php?ec_events_router=all&paged=$matches[1]', $pagination_rule['query'] );
		$this->assertSame( 'past=1', (string) wp_parse_url( 'https://events.example/all/page/2/?past=1', PHP_URL_QUERY ) );
	}

	public function test_scoped_query_pagination_preserves_serving_url_identity(): void {
		$GLOBALS['ec_locations_blog_id'] = 7;
		$GLOBALS['test_is_tax']          = true;
		$GLOBALS['test_query_vars']      = array(
			'event_scope' => 'this-weekend',
			'paged'       => 2,
		);
		$GLOBALS['test_queried_term']    = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_link']       = 'https://events.example/location/usa/south-carolina/charleston/';
		$_GET                            = array(
			'paged'      => '2',
			'past'       => '1',
			'utm_source' => 'newsletter',
		);

		$original_query = $this->use_discovery_query( $GLOBALS['test_queried_term'], 'this-weekend', 2 );
		$term_link      = static fn() => $GLOBALS['test_term_link'];
		if ( null !== $original_query ) {
			add_filter( 'term_link', $term_link );
		}
		$canonical = extrachill_events_discovery_canonical( 'https://events.example/?paged=2' );
		if ( null !== $original_query ) {
			remove_filter( 'term_link', $term_link );
			$GLOBALS['wp_query'] = $original_query;
		}
		unset( $GLOBALS['ec_locations_blog_id'], $GLOBALS['test_term_link'] );
		$_GET = array();

		$this->assertSame( 'https://events.example/location/usa/south-carolina/charleston/this-weekend?paged=2', $canonical );
	}

	public function test_scoped_page_one_canonical_matches_slashless_serving_url(): void {
		$GLOBALS['ec_locations_blog_id'] = 7;
		$GLOBALS['test_is_tax']          = true;
		$GLOBALS['test_query_vars']      = array(
			'event_scope' => 'tonight',
			'paged'       => 1,
		);
		$GLOBALS['test_queried_term']    = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_link']       = 'https://events.example/location/usa/south-carolina/charleston/';
		$_GET                            = array(
			'paged'  => '1',
			'fbclid' => 'tracking-value',
		);

		$original_query = $this->use_discovery_query( $GLOBALS['test_queried_term'], 'tonight', 1 );
		$term_link      = static fn() => $GLOBALS['test_term_link'];
		if ( null !== $original_query ) {
			add_filter( 'term_link', $term_link );
		}
		$canonical = extrachill_events_discovery_canonical( 'https://events.example/?paged=1' );
		if ( null !== $original_query ) {
			remove_filter( 'term_link', $term_link );
			$GLOBALS['wp_query'] = $original_query;
		}
		unset( $GLOBALS['ec_locations_blog_id'], $GLOBALS['test_term_link'] );
		$_GET = array();

		$this->assertSame(
			'https://events.example/location/usa/south-carolina/charleston/tonight',
			$canonical
		);
	}

	public function test_discovery_canonical_and_open_graph_preserve_other_pages(): void {
		$GLOBALS['test_is_tax']     = false;
		$GLOBALS['test_query_vars'] = array();
		$og_data                    = array(
			'og:url'   => 'https://events.example/original/',
			'og:title' => 'Original',
		);

		$this->assertSame( 'https://events.example/original/', extrachill_events_discovery_canonical( 'https://events.example/original/' ) );
		$this->assertSame( $og_data, extrachill_events_discovery_og_data( $og_data ) );
	}

	public function test_router_query_flags_use_the_query_being_parsed(): void {
		$this->use_events_blog();
		$query = new class( array( 'ec_events_router' => 'all' ) ) {
			public bool $is_404     = true;
			public bool $is_archive = false;
			public bool $is_home    = true;
			private array $vars;

			public function __construct( array $vars ) {
				$this->vars = $vars;
			}

			public function get( $key, $default = '' ) {
				return $this->vars[ $key ] ?? $default;
			}

			public function is_main_query(): bool {
				return true;
			}
		};

		extrachill_events_router_query_flags( $query );

		$this->assertFalse( $query->is_404 );
		$this->assertTrue( $query->is_archive );
		$this->assertFalse( $query->is_home );
	}

	public function test_venue_settings_query_uses_router_archive_flags(): void {
		$this->use_events_blog();
		$query = new class( array( 'ec_events_router' => 'venue-settings' ) ) {
			public bool $is_404     = true;
			public bool $is_archive = false;
			public bool $is_home    = true;
			private array $vars;

			public function __construct( array $vars ) {
				$this->vars = $vars;
			}

			public function get( $key, $default = '' ) {
				return $this->vars[ $key ] ?? $default;
			}

			public function is_main_query(): bool {
				return true;
			}
		};

		extrachill_events_router_query_flags( $query );

		$this->assertFalse( $query->is_404 );
		$this->assertTrue( $query->is_archive );
		$this->assertFalse( $query->is_home );
	}

	public function test_router_pages_preempt_core_404_handling(): void {
		$this->use_events_blog();
		$query = new class() {
			public function get( $key, $default = '' ) {
				return 'ec_events_location_index' === $key ? '1' : $default;
			}
		};

		$status_code = null;
		$capture     = static function ( $header, $code ) use ( &$status_code ) {
			unset( $header );
			$status_code = (int) $code;
			return 'HTTP/1.1 200 OK';
		};
		if ( isset( $GLOBALS['wp_filter'] ) ) {
			add_filter( 'status_header', $capture, 10, 2 );
		}

		$this->assertTrue( extrachill_events_router_pre_handle_404( false, $query ) );
		$this->assertSame( 200, null !== $status_code ? $status_code : $GLOBALS['test_status_header'] );
		if ( isset( $GLOBALS['wp_filter'] ) ) {
			remove_filter( 'status_header', $capture, 10 );
		}
	}

	public function test_unrelated_queries_preserve_core_404_handling(): void {
		$query = new class() {
			public function get( $key, $default = '' ) {
				return $default;
			}
		};

		$this->assertNull( extrachill_events_router_pre_handle_404( null, $query ) );
	}

	/**
	 */
	public function test_active_market_context_escapes_label_and_links_to_account_details(): void {
		$this->use_logged_in_user();
		$this->use_events_blog();
		$this->use_all_events_query();
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_all_events_page']     = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'      => 'charleston',
						'term_id'   => 1618,
						'hierarchy' => array( 'label' => 'Charleston<script>alert(1)</script>' ),
					),
				);
			}
		};

		ob_start();
		extrachill_events_render_account_market_context();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Showing events for Charleston', $output );
		$this->assertStringNotContainsString( 'alert(1)', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( '</script>', $output );
		$this->assertStringContainsString( '/settings/#tab-account-details', $output );
		$this->assertStringContainsString( 'explore_all=1', $output );
	}

	/**
	 */
	public function test_logged_in_without_market_and_anonymous_prompts(): void {
		$this->use_logged_in_user();
		$this->use_events_blog();
		$this->use_front_page_query();
		$GLOBALS['test_is_front_page']     = true;
		$GLOBALS['test_is_user_logged_in'] = true;

		ob_start();
		extrachill_events_render_home_market_router( array() );
		$logged_in = (string) ob_get_clean();
		$this->assertStringContainsString( 'Set Local Scene', $logged_in );

		$this->use_anonymous_user();
		$GLOBALS['test_is_user_logged_in'] = false;
		ob_start();
		extrachill_events_render_home_market_router( array() );
		$anonymous = (string) ob_get_clean();
		$this->assertStringContainsString( 'Search without an account', $anonymous );
		$this->assertStringContainsString( 'Sign in', $anonymous );
	}

	/**
	 */
	public function test_homepage_promotes_saved_market_as_primary_city_route(): void {
		$this->use_logged_in_user();
		$this->use_events_blog();
		$this->use_front_page_query();
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_front_page']          = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'      => 'charleston',
						'term_id'   => 1618,
						'hierarchy' => array( 'label' => 'Charleston, South Carolina' ),
						'url'       => 'https://events.example/location/charleston/',
					),
				);
			}
		};

		ob_start();
		extrachill_events_render_home_market_router(
			array(
				array(
					'term_id' => 1618,
					'name'    => 'Charleston',
					'label'   => 'Charleston, South Carolina',
					'slug'    => 'charleston',
					'count'   => 883,
					'url'     => 'https://events.example/location/charleston/',
				),
				array(
					'term_id' => 42,
					'name'    => 'Austin',
					'label'   => 'Austin, Texas',
					'slug'    => 'austin',
					'count'   => 1458,
					'url'     => 'https://events.example/location/austin/',
				),
			)
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Your Local Scene', $output );
		$this->assertStringContainsString( 'class="taxonomy-badge location-badge location-charleston"', $output );
		$this->assertStringContainsString( '>Charleston</a>', $output );
		$this->assertDoesNotMatchRegularExpression( '/<h3>\s*<a[^>]+location-charleston/s', $output );
		$this->assertStringNotContainsString( 'Charleston, South Carolina', $output );
		$this->assertStringContainsString( 'href="https://events.example/location/charleston/tonight/"', $output );
		$this->assertStringContainsString( 'href="https://events.example/location/charleston/this-weekend/"', $output );
		$this->assertStringContainsString( 'href="https://events.example/location/charleston/">City calendar</a>', $output );
		$this->assertStringContainsString( '883 upcoming events', $output );
		$this->assertStringContainsString( 'Austin (1,458)', $output );
		$this->assertStringNotContainsString( 'Austin, Texas', $output );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/near-me/' ) ) . '">Shows near me', $output );
		$this->assertStringContainsString( 'Browse all locations', $output );
		$this->assertStringNotContainsString( 'Showing events for', $output );
	}

	/**
	 */
	public function test_reserved_root_scope_does_not_guess_an_event_permalink(): void {
		$this->use_events_blog();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserving the test environment verbatim.
		$original_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/tonight/';

		try {
			$this->assertFalse( extrachill_events_prevent_root_scope_permalink_guess( null ) );
			$_SERVER['REQUEST_URI'] = '/events/tonight-at-the-improv/';
			$this->assertNull( extrachill_events_prevent_root_scope_permalink_guess( null ) );
		} finally {
			if ( null === $original_request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original_request_uri;
			}
		}
	}

	/**
	 */
	public function test_archive_cta_only_renders_for_selectable_city(): void {
		$GLOBALS['test_is_tax']         = true;
		$GLOBALS['test_queried_term']   = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors'] = array( 22 );
		$GLOBALS['test_term_link']      = 'https://events.example/location/charleston/';
		$this->use_archive_query( $GLOBALS['test_queried_term'] );

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$this->assertSame( '', (string) ob_get_clean() );

		$GLOBALS['test_term_ancestors'] = array( 22, 1 );
		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Get Charleston shows in your inbox', $output );
		$this->assertStringContainsString( rawurlencode( 'https://events.example/location/charleston/' ), $output );
	}

	/**
	 */
	public function test_archive_cta_prompts_logged_out_once_with_both_affordances(): void {
		$GLOBALS['test_is_tax']         = true;
		$GLOBALS['test_queried_term']   = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors'] = array( 22, 1 );
		$GLOBALS['test_term_link']      = 'https://events.example/location/charleston/';
		$this->use_archive_query( $GLOBALS['test_queried_term'] );

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();

		// One compact panel, one sign-in ask — never two stacked prompts.
		$this->assertSame( 1, substr_count( $output, '<aside class="events-market-context' ) );
		$this->assertSame( 1, substr_count( $output, 'Sign in' ) );
		$this->assertStringContainsString( 'Sign in for the weekly email', $output );
		$this->assertStringContainsString( 'Save this city', $output );

		// Both underlying capabilities survive the merge as distinct intents.
		$digest_url = $this->intent_redirect_from_output( $output, 'Sign in for the weekly email' );
		$save_url   = $this->intent_redirect_from_output( $output, 'Save this city' );
		$this->assertStringContainsString( 'ec_events_intent=subscribe_digest', $digest_url );
		$this->assertStringContainsString( 'ec_events_intent=save_scene', $save_url );
	}

	/** Extract and decode the signed redirect carried by one prompt action. */
	private function intent_redirect_from_output( string $output, string $anchor ): string {
		$this->assertStringContainsString( $anchor, $output );
		$this->assertSame(
			1,
			preg_match( '/href="([^"]+)">[^<]*' . preg_quote( $anchor, '/' ) . '/', $output, $matches ),
			"No anchor link found for: $anchor"
		);
		parse_str( (string) parse_url( $matches[1], PHP_URL_QUERY ), $login_query );
		return (string) ( $login_query['redirect_to'] ?? '' );
	}

	/**
	 */
	public function test_archive_cta_shows_save_form_or_current_confirmation(): void {
		$this->use_logged_in_user();
		$GLOBALS['test_is_tax']            = true;
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_queried_term']      = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors']    = array( 22, 1 );
		$GLOBALS['test_term_link']         = 'https://events.example/location/charleston/';
		$this->use_archive_query( $GLOBALS['test_queried_term'] );

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Make this my city', $output );
		$this->assertStringContainsString( 'extrachill_events_scene_nonce', $output );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	/**
	 */
	public function test_archive_cta_confirms_current_scene_without_save_form(): void {
		$this->use_logged_in_user();
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_is_tax']            = true;
		$GLOBALS['test_queried_term']      = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors']    = array( 22, 1 );
		$GLOBALS['test_term_link']         = 'https://events.example/location/charleston/';
		$this->use_archive_query( $GLOBALS['test_queried_term'] );
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'    => 'charleston',
						'term_id' => 1618,
					),
				);
			}
		};

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'This city is saved to your account.', $output );
		$this->assertStringContainsString( 'data-local-scene-digest-control', $output );
		$this->assertStringContainsString( 'data-local-scene-digest', $output );
		$this->assertStringContainsString( 'Checking your subscription', $output );
		$this->assertStringNotContainsString( 'Make this my city', $output );
	}

	/** Outcome flashes stay inside the single merged panel with the live toggle. */
	public function test_archive_flash_statuses_render_outcome_inside_single_panel(): void {
		$this->use_logged_in_user();
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_is_tax']            = true;
		$GLOBALS['test_queried_term']      = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors']    = array( 22, 1 );
		$GLOBALS['test_term_link']         = 'https://events.example/location/charleston/';
		$this->use_archive_query( $GLOBALS['test_queried_term'] );
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'local_scene' => array(
						'slug'    => 'charleston',
						'term_id' => 1618,
					),
				);
			}
		};

		$_GET['scene_status'] = 'subscribed';
		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $output, '<aside class="events-market-context' ) );
		$this->assertStringContainsString( 'Weekly email and in-app updates are on for this city.', $output );

		$_GET['scene_status'] = 'scene_saved';
		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'The weekly email could not be turned on. Please try subscribing again.', $output );

		$_GET['scene_status'] = 'failed';
		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'We could not complete that update. Nothing was changed. Please try again.', $output );
		$this->assertStringContainsString( 'data-local-scene-digest-control', $output );
		unset( $_GET['scene_status'] );
	}

	/** A valid post-auth subscribe continuation renders one explicit confirmation. */
	public function test_archive_subscribe_intent_renders_accessible_confirmation(): void {
		$this->use_logged_in_user();
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_is_tax']            = true;
		$GLOBALS['test_queried_term']      = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors']    = array( 22, 1 );
		$GLOBALS['test_term_link']         = 'https://events.example/location/charleston/';
		$this->use_archive_query( $GLOBALS['test_queried_term'] );

		$url = extrachill_events_archive_intent_login_url( $GLOBALS['test_queried_term'], 'subscribe_digest' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $login_query );
		parse_str( (string) parse_url( rawurldecode( $login_query['redirect_to'] ), PHP_URL_QUERY ), $_GET );

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Turn on the weekly email for Charleston?', $output );
		$this->assertStringContainsString( 'Nothing changes until you confirm.', $output );
		$this->assertStringContainsString( 'Confirm: save and subscribe', $output );
		$this->assertStringContainsString( 'autofocus', $output );
		// The confirm affordance posts the digest intent through the merged scene form.
		$this->assertStringContainsString( 'name="extrachill_events_scene_action" value="subscribe_digest"', $output );
		$this->assertStringContainsString( 'name="extrachill_events_scene_nonce"', $output );
		$this->assertSame( 1, substr_count( $output, '<aside class="events-market-context' ) );
	}

	/**
	 */
	public function test_archive_update_requires_login_and_nonce_and_uses_settings_ability(): void {
		$term                                 = $this->term( 1618, 'Charleston', 'charleston' );
		$valid_nonce                          = class_exists( 'WP_Abilities_Registry' ) ? wp_create_nonce( 'extrachill_events_save_scene_' . $term->term_id ) : 'nonce-extrachill_events_save_scene_1618';
		$calls                                = new ArrayObject();
		$GLOBALS['test_update_scene_ability'] = new class( $calls ) {
			private ArrayObject $calls;
			public function __construct( ArrayObject $calls ) {
				$this->calls = $calls;
			}
			public function execute( array $input ): array {
				$this->calls[] = $input;
				return array( 'local_scene' => $input['local_scene'] );
			}
		};

		$this->assertFalse( extrachill_events_update_archive_scene( $term, $valid_nonce ) );
		$this->use_logged_in_user();
		$GLOBALS['test_is_user_logged_in'] = true;
		$this->assertFalse( extrachill_events_update_archive_scene( $term, 'wrong' ) );
		$valid_nonce = class_exists( 'WP_Abilities_Registry' ) ? wp_create_nonce( 'extrachill_events_save_scene_' . $term->term_id ) : $valid_nonce;
		$this->assertTrue( extrachill_events_update_archive_scene( $term, $valid_nonce ) );
		$this->assertSame( array( array( 'local_scene' => 'charleston' ) ), $calls->getArrayCopy() );
	}

	/** Signed continuations preserve distinct save and subscription actions. */
	public function test_archive_auth_intents_are_distinct_and_signed(): void {
		$term       = $this->term( 1618, 'Charleston', 'charleston' );
		$save_url   = extrachill_events_archive_intent_login_url( $term, 'save_scene' );
		$digest_url = extrachill_events_archive_intent_login_url( $term, 'subscribe_digest' );

		$this->assertStringContainsString( 'ec_events_intent=save_scene', rawurldecode( $save_url ) );
		$this->assertStringContainsString( 'ec_events_intent=subscribe_digest', rawurldecode( $digest_url ) );
		$this->assertNotSame( $save_url, $digest_url );
	}

	/** Tampered and expired archive continuations fail closed. */
	public function test_archive_auth_intent_fails_closed_when_tampered_or_expired(): void {
		$term = $this->term( 1618, 'Charleston', 'charleston' );
		$url  = extrachill_events_archive_intent_login_url( $term, 'save_scene' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $login_query );
		parse_str( (string) parse_url( rawurldecode( $login_query['redirect_to'] ), PHP_URL_QUERY ), $_GET );
		$this->assertSame( 'save_scene', extrachill_events_get_archive_auth_intent( $term ) );

		$_GET['ec_events_intent'] = 'subscribe_digest';
		$this->assertNull( extrachill_events_get_archive_auth_intent( $term ) );
		$_GET['ec_events_intent']         = 'save_scene';
		$_GET['ec_events_intent_expires'] = time() - 1;
		$this->assertNull( extrachill_events_get_archive_auth_intent( $term ) );
	}

	/** A valid post-auth save continuation renders one explicit confirmation. */
	public function test_archive_save_intent_renders_accessible_confirmation(): void {
		$this->use_logged_in_user();
		$GLOBALS['test_is_user_logged_in'] = true;
		$GLOBALS['test_is_tax']            = true;
		$GLOBALS['test_queried_term']      = $this->term( 1618, 'Charleston', 'charleston' );
		$GLOBALS['test_term_ancestors']    = array( 22, 1 );
		$GLOBALS['test_term_link']         = 'https://events.example/location/charleston/';
		$this->use_archive_query( $GLOBALS['test_queried_term'] );

		$url = extrachill_events_archive_intent_login_url( $GLOBALS['test_queried_term'], 'save_scene' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $login_query );
		parse_str( (string) parse_url( rawurldecode( $login_query['redirect_to'] ), PHP_URL_QUERY ), $_GET );

		ob_start();
		extrachill_events_render_archive_scene_cta();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'You asked to save this city before signing in.', $output );
		$this->assertStringContainsString( 'Confirm: save this city', $output );
		$this->assertStringContainsString( 'autofocus', $output );
		$this->assertStringContainsString( 'role="status"', $output );
		$this->assertStringNotContainsString( 'Make this my city', $output );
	}
}
