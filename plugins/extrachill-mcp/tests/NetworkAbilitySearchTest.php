<?php
/**
 * Tests for `extrachill/ability-search`'s network fan-out.
 *
 * Local discovery reads the exposed registry directly, without requiring
 * agents/ability-search. Remote fan-out across `NetworkDispatch::remote_sites()`
 * is fully exercised through `NetworkStub`'s cross-site transport.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Abilities\NetworkAbilitySearch;
use ExtraChillMcp\Tests\Support\NetworkStub;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group network
 */
class NetworkAbilitySearchTest extends WP_UnitTestCase {

	private NetworkAbilitySearch $search;

	public function set_up(): void {
		parent::set_up();

		if ( ! NetworkStub::install() ) {
			$this->markTestSkipped( 'A real extrachill-network registry owns these globals; the stub must not shadow it.' );
		}

		NetworkStub::reset();

		$this->search = new NetworkAbilitySearch( new \ExtraChillMcp\Access() );
	}

	public function tear_down(): void {
		NetworkStub::reset();
		parent::tear_down();
	}

	public function test_fan_out_merges_a_remote_sites_results_and_tags_them_by_site(): void {
		$forced = null;

		// Simulates an ability registered ONLY on the remote site: the forced
		// HTTP loopback is what lets the target site's own registry answer,
		// instead of the in-process transport re-listing this site's registry
		// (Extra-Chill/extrachill-mcp#22).
		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$forced ) {
			$forced = apply_filters( 'ec_cross_site_use_http_loopback', false, $site_key, $method, $path, $args );

			// Core's GET /wp-abilities/v1/abilities collection on the target.
			return array(
				array(
					'name'         => 'extrachill/get-venue',
					'label'        => 'Get Venue',
					'description'  => 'Get a venue.',
					'category'     => 'extrachill-events',
					'input_schema' => array(
						'type'     => 'object',
						'required' => array( 'venue_id' ),
					),
				),
				array(
					'name'        => 'extrachill/list-forums',
					'label'       => 'List Forums',
					'description' => 'List community forums.',
					'category'    => 'extrachill-community',
				),
			);
		};

		$result = $this->search->execute( array( 'query' => 'venue' ) );

		$this->assertSame( array( 'main', 'events' ), $result['sites_queried'] );
		$this->assertCount( 1, $result['abilities'] );
		$this->assertSame( 'extrachill/get-venue', $result['abilities'][0]['name'] );
		$this->assertSame( 'events', $result['abilities'][0]['site'] );
		$this->assertSame( 'Get a venue.', $result['abilities'][0]['summary'] );
		$this->assertSame( array( 'venue_id' ), $result['abilities'][0]['required_fields'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertTrue( $forced, 'The remote search hop must force the target site\'s own bootstrap over HTTP loopback.' );

		$this->assertArrayNotHasKey( 'main', $result['errors'] );
	}

	public function test_fan_out_records_a_remote_site_error_without_failing_the_whole_search(): void {
		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) {
			return new WP_Error( 'http_request_failed', 'timeout' );
		};

		$result = $this->search->execute( array( 'query' => 'nonexistent-network-search-fixture' ) );

		$this->assertSame( array( 'main' ), $result['sites_queried'] );
		$this->assertSame( array(), $result['abilities'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertArrayHasKey( 'events', $result['errors'] );
		$this->assertSame( 'timeout (http_request_failed)', $result['errors']['events'] );
		$this->assertArrayNotHasKey( 'main', $result['errors'] );
	}

	public function test_fan_out_queries_every_remote_site_with_the_same_search_input(): void {
		NetworkStub::$blog_ids = array(
			'main'    => 1,
			'events'  => 7,
			'artists' => 4,
		);

		$queried_sites = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$queried_sites ) {
			$queried_sites[] = $site_key;

			return array();
		};

		$this->search->execute( array( 'query' => 'venue', 'limit' => 5 ) );

		sort( $queried_sites );
		$this->assertSame( array( 'artists', 'events' ), $queried_sites );
	}

	public function test_match_records_follows_the_substrate_query_syntax(): void {
		$records = array(
			array( 'name' => 'datamachine/instagram-account', 'label' => 'Instagram Account', 'description' => 'Check the Instagram connection.', 'category' => 'datamachine-socials' ),
			array( 'name' => 'datamachine/instagram-read', 'label' => 'Instagram Read', 'description' => 'Read recent posts.', 'category' => 'datamachine-socials' ),
			array( 'name' => 'extrachill/community-list-forums', 'label' => 'List Forums', 'description' => '', 'category' => 'extrachill-community' ),
		);

		$names = static fn( array $entries ): array => array_column( $entries, 'name' );

		$this->assertSame( array( 'datamachine/instagram-account', 'datamachine/instagram-read' ), $names( NetworkAbilitySearch::match_records( $records, 'instagram' ) ) );
		$this->assertSame( array( 'datamachine/instagram-read' ), $names( NetworkAbilitySearch::match_records( $records, 'instagram +recent' ) ) );
		$this->assertSame( array( 'extrachill/community-list-forums' ), $names( NetworkAbilitySearch::match_records( $records, 'select:extrachill/community-list-forums' ) ) );
		$this->assertCount( 3, NetworkAbilitySearch::match_records( $records, '' ) );
		$this->assertSame( 'List Forums', NetworkAbilitySearch::match_records( $records, 'forums' )[0]['summary'] );
	}

	public function test_remote_best_match_after_position_100_is_ranked_first(): void {
		$records = self::late_best_records();
		NetworkStub::$cross_site_handler = static function ( string $site, string $method, string $path, array $args ) use ( $records ) {
			return array_slice( $records, ( ( $args['query']['page'] ?? 1 ) - 1 ) * 100, 100 );
		};

		$this->assertCount( 151, NetworkAbilitySearch::match_records( $records, 'event' ) );
		$result = $this->search->execute( array( 'query' => 'event', 'limit' => 1 ) );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'data-machine-events/update-event', $result['abilities'][0]['name'] );
		$this->assertSame( array( 'events' ), $result['abilities'][0]['sites'] );
	}

	public function test_local_best_match_after_position_100_is_ranked_first(): void {
		$records = self::late_best_records();
		if ( ! wp_has_ability_category( 'extrachill-mcp' ) ) {
			add_action(
				'wp_abilities_api_categories_init',
				static function (): void {
					if ( ! wp_has_ability_category( 'extrachill-mcp' ) ) {
						wp_register_ability_category(
							'extrachill-mcp',
							array(
								'label'       => 'Extra Chill MCP',
								'description' => 'Test category.',
							)
						);
					}
				}
			);
			do_action( 'wp_abilities_api_categories_init' );
		}
		$register = static function () use ( $records ): void {
			foreach ( $records as $record ) {
				if ( wp_has_ability( $record['name'] ) ) {
					continue;
				}
				wp_register_ability(
					$record['name'],
					$record + array(
						'category'            => 'extrachill-mcp',
						'execute_callback'    => '__return_empty_array',
						'permission_callback' => '__return_true',
						'meta'                => array( 'mcp' => array( 'public' => true ) ),
					)
				);
			}
		};
		add_action( 'wp_abilities_api_init', $register );
		try {
			do_action( 'wp_abilities_api_init' );
			NetworkStub::$cross_site_handler = static fn() => array();
			$result = $this->search->execute( array( 'query' => 'event', 'limit' => 1 ) );
			$this->assertSame( 1, $result['count'] );
			$this->assertSame( 'data-machine-events/update-event', $result['abilities'][0]['name'] );
			$this->assertSame( array( 'main' ), $result['abilities'][0]['sites'] );
		} finally {
			remove_action( 'wp_abilities_api_init', $register );
			foreach ( $records as $record ) {
				wp_unregister_ability( $record['name'] );
			}
		}
	}

	private static function late_best_records(): array {
		$records = array();
		for ( $i = 0; $i < 150; ++$i ) {
			$records[] = array( 'name' => "extrachill/booking-{$i}", 'label' => 'Booking', 'description' => 'An event booking.' );
		}
		$records[] = array( 'name' => 'data-machine-events/update-event', 'label' => 'Update Event', 'description' => 'Update an existing event.' );
		return $records;
	}

	public function test_dedupe_lists_every_site_once_per_ability(): void {
		$merged = NetworkAbilitySearch::dedupe(
			array(
				array( 'name' => 'agents/list-chat-run-events', 'summary' => 'x', 'required_fields' => array(), 'site' => 'main' ),
				array( 'name' => 'data-machine-events/query-events', 'summary' => 'y', 'required_fields' => array(), 'site' => 'events' ),
				array( 'name' => 'agents/list-chat-run-events', 'summary' => 'x', 'required_fields' => array(), 'site' => 'events' ),
				array( 'name' => 'agents/list-chat-run-events', 'summary' => 'x', 'required_fields' => array(), 'site' => 'events' ),
			)
		);

		$this->assertCount( 2, $merged );
		$this->assertSame( 'agents/list-chat-run-events', $merged[0]['name'] );
		$this->assertSame( 'main', $merged[0]['site'] );
		$this->assertSame( array( 'main', 'events' ), $merged[0]['sites'] );
		$this->assertSame( array( 'events' ), $merged[1]['sites'] );
	}

	public function test_rank_prefers_name_hits_and_domain_abilities_over_shared_plumbing(): void {
		$entries = array(
			array( 'name' => 'extrachill/track-cta-click', 'summary' => 'Record a cta_click event.', 'sites' => array( 'main', 'events', 'shop' ) ),
			array( 'name' => 'agents/list-chat-run-events', 'summary' => 'List lifecycle events.', 'sites' => array( 'main', 'events', 'shop' ) ),
			array( 'name' => 'data-machine-events/update-venue', 'summary' => 'Update a venue.', 'sites' => array( 'events' ) ),
			array( 'name' => 'data-machine-events/query-events', 'summary' => 'Query events by date.', 'sites' => array( 'events' ) ),
		);

		$names = array_column( NetworkAbilitySearch::rank( $entries, 'event', 3 ), 'name' );

		// Local-name hit on a domain ability first; a domain namespace hit
		// next; the same local-name hit on shared plumbing (halved) after;
		// description-only plumbing last.
		$this->assertSame(
			array( 'data-machine-events/query-events', 'data-machine-events/update-venue', 'agents/list-chat-run-events', 'extrachill/track-cta-click' ),
			$names
		);

		$exact = NetworkAbilitySearch::rank( $entries, 'extrachill/track-cta-click', 3 );
		$this->assertSame( 'extrachill/track-cta-click', $exact[0]['name'] );
	}

	public function test_limit_is_global_and_top_hits_carry_a_compact_schema(): void {
		NetworkStub::$blog_ids = array(
			'main'    => 1,
			'events'  => 7,
			'artists' => 4,
		);

		NetworkStub::$cross_site_handler = static function ( string $site_key ) {
			$records = array();
			for ( $i = 1; $i <= 6; $i++ ) {
				$records[] = array(
					'name'         => "{$site_key}/venue-tool-{$i}",
					'label'        => "Venue tool {$i}",
					'description'  => 'Venue helper.',
					'input_schema' => array(
						'type'       => 'object',
						'required'   => array( 'venue' ),
						'properties' => array(
							'venue'    => array( 'type' => 'integer', 'description' => 'Venue term ID.' ),
							'scope'    => array( 'type' => 'string', 'enum' => array( 'upcoming', 'past' ), 'default' => 'upcoming' ),
							'nested'   => array( 'type' => 'object', 'properties' => array( 'deep' => array( 'type' => 'string' ) ) ),
						),
					),
				);
			}
			return $records;
		};

		$result = $this->search->execute( array( 'query' => 'venue', 'limit' => 4 ) );

		$this->assertSame( 4, $result['count'] );
		$this->assertCount( 4, $result['abilities'] );
		$this->assertArrayHasKey( 'input_schema', $result['abilities'][0] );
		$this->assertArrayHasKey( 'input_schema', $result['abilities'][2] );
		$this->assertArrayNotHasKey( 'input_schema', $result['abilities'][3] );

		$schema = $result['abilities'][0]['input_schema'];
		$this->assertSame( array( 'venue' ), $schema['required'] );
		$this->assertSame( array( 'type' => 'integer', 'description' => 'Venue term ID.' ), $schema['properties']['venue'] );
		$this->assertSame( array( 'upcoming', 'past' ), $schema['properties']['scope']['enum'] );
		$this->assertSame( array( 'type' => 'object' ), $schema['properties']['nested'] );

		$selected = $this->search->execute( array( 'query' => 'select:events/venue-tool-5,artists/venue-tool-6' ) );
		$this->assertCount( 2, $selected['abilities'] );
		foreach ( $selected['abilities'] as $entry ) {
			$this->assertArrayHasKey( 'input_schema', $entry );
		}
	}

	public function test_compact_schema_trims_long_descriptions(): void {
		$compact = NetworkAbilitySearch::compact_schema(
			array(
				'properties' => array(
					'q' => array( 'type' => 'string', 'description' => str_repeat( 'word ', 100 ) ),
				),
			)
		);

		$this->assertLessThanOrEqual( NetworkAbilitySearch::SCHEMA_DESCRIPTION_MAX + 3, strlen( $compact['properties']['q']['description'] ) );
		$this->assertArrayNotHasKey( 'required', $compact );
	}
}
