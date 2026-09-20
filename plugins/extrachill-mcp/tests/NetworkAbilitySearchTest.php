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
		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) {
			return array(
				'abilities' => array(
					array(
						'name'            => 'extrachill/get-venue',
						'summary'         => 'Get a venue.',
						'required_fields' => array( 'venue_id' ),
					),
				),
			);
		};

		$result = $this->search->execute( array( 'query' => 'venue' ) );

		$this->assertSame( array( 'events' ), $result['sites_queried'] );
		$this->assertCount( 1, $result['abilities'] );
		$this->assertSame( 'extrachill/get-venue', $result['abilities'][0]['name'] );
		$this->assertSame( 'events', $result['abilities'][0]['site'] );
		$this->assertSame( 1, $result['count'] );

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
		$this->assertSame( 'timeout', $result['errors']['events'] );
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

			return array( 'abilities' => array() );
		};

		$this->search->execute( array( 'query' => 'venue', 'limit' => 5 ) );

		sort( $queried_sites );
		$this->assertSame( array( 'artists', 'events' ), $queried_sites );
	}
}
