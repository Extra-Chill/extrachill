<?php
/**
 * Tests for `extrachill/ability-search`'s network fan-out.
 *
 * The local hop reuses the Agents API substrate's own
 * `agents/ability-search` (via `NetworkDispatch::run_local_ability()`),
 * which is not registered in this plugin's isolated test environment, so
 * every test here genuinely observes a local-hop error alongside the
 * remote fan-out — that degraded local leg is real environment behavior,
 * not a test artifact, and these assertions treat it as such rather than
 * hiding it. The remote fan-out across `NetworkDispatch::remote_sites()`
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

		$this->assertSame( array( 'events' ), $result['sites_queried'] );
		$this->assertCount( 1, $result['abilities'] );
		$this->assertSame( 'extrachill/get-venue', $result['abilities'][0]['name'] );
		$this->assertSame( 'events', $result['abilities'][0]['site'] );
		$this->assertSame( 'Get a venue.', $result['abilities'][0]['summary'] );
		$this->assertSame( array( 'venue_id' ), $result['abilities'][0]['required_fields'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertTrue( $forced, 'The remote search hop must force the target site\'s own bootstrap over HTTP loopback.' );

		// The local hop degrades in this isolated environment because
		// agents/ability-search is not registered — proving it was actually
		// attempted, tagged under this site's own key.
		$this->assertArrayHasKey( 'main', $result['errors'] );
	}

	public function test_fan_out_records_a_remote_site_error_without_failing_the_whole_search(): void {
		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) {
			return new WP_Error( 'http_request_failed', 'timeout' );
		};

		$result = $this->search->execute( array() );

		$this->assertSame( array(), $result['sites_queried'] );
		$this->assertSame( array(), $result['abilities'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertArrayHasKey( 'events', $result['errors'] );
		$this->assertSame( 'timeout (http_request_failed)', $result['errors']['events'] );
		$this->assertArrayHasKey( 'main', $result['errors'] );
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

		$this->assertSame( array( 'datamachine/instagram-account', 'datamachine/instagram-read' ), $names( NetworkAbilitySearch::match_records( $records, 'instagram', 20 ) ) );
		$this->assertSame( array( 'datamachine/instagram-read' ), $names( NetworkAbilitySearch::match_records( $records, 'instagram +recent', 20 ) ) );
		$this->assertSame( array( 'extrachill/community-list-forums' ), $names( NetworkAbilitySearch::match_records( $records, 'select:extrachill/community-list-forums', 20 ) ) );
		$this->assertCount( 1, NetworkAbilitySearch::match_records( $records, '', 1 ) );
		$this->assertSame( 'List Forums', NetworkAbilitySearch::match_records( $records, 'forums', 20 )[0]['summary'] );
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
