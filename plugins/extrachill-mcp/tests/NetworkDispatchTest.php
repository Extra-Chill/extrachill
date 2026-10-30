<?php
/**
 * Tests for NetworkDispatch, the sole adapter over extrachill-network's
 * ownership index and cross-site dispatcher, through `NetworkStub`.
 *
 * `NetworkAbilityAbsenceTest` must run before this file and already covers
 * the genuinely-absent path; the tests below cover the same methods once
 * the stub is installed.
 *
 * @package ExtraChillMcp\Tests
 */

declare( strict_types = 1 );

use ExtraChillMcp\Network\NetworkDispatch;
use ExtraChillMcp\Tests\Support\NetworkStub;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group extrachill-mcp
 * @group network
 */
class NetworkDispatchTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! NetworkStub::install() ) {
			$this->markTestSkipped( 'A real extrachill-network registry owns these globals; the stub must not shadow it.' );
		}

		NetworkStub::reset();
	}

	public function tear_down(): void {
		NetworkStub::reset();
		parent::tear_down();
	}

	public function test_available_is_true_once_the_stub_installs(): void {
		$this->assertTrue( NetworkDispatch::available() );
	}

	public function test_local_site_key_resolves_the_current_blog(): void {
		// NetworkStub maps blog id 1 to 'main'; a single-site test run's
		// get_current_blog_id() is 1.
		$this->assertSame( 'main', NetworkDispatch::local_site_key() );
	}

	public function test_remote_sites_excludes_the_current_blog(): void {
		$this->assertSame( array( 'events' ), NetworkDispatch::remote_sites() );
	}

	public function test_resolve_owner_returns_null_when_the_affinity_handler_says_local(): void {
		NetworkStub::$affinity_handler = static function ( string $ability_name ) {
			return null;
		};

		$this->assertNull( NetworkDispatch::resolve_owner( 'extrachill/get-venue' ) );
	}

	public function test_resolve_owner_returns_the_owning_site_key(): void {
		NetworkStub::$affinity_handler = static function ( string $ability_name ) {
			return 'extrachill/get-venue' === $ability_name ? 'events' : null;
		};

		$this->assertSame( 'events', NetworkDispatch::resolve_owner( 'extrachill/get-venue' ) );
	}

	public function test_run_ability_posts_to_the_targets_run_route_by_default(): void {
		$captured = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$captured ) {
			$captured[] = array( $site_key, $method, $path, $args );

			return array( 'ok' => true );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/get-venue', array( 'venue_id' => 4 ) );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'events', $captured[0][0] );
		$this->assertSame( 'POST', $captured[0][1] );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/get-venue/run', $captured[0][2] );
		$this->assertSame( array( 'body' => array( 'input' => array( 'venue_id' => 4 ) ) ), $captured[0][3] );
	}

	public function test_run_ability_retries_with_get_when_the_route_rejects_post(): void {
		$attempts = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$attempts ) {
			$attempts[] = $method;

			if ( 'POST' === $method ) {
				return new WP_Error( 'rest_ability_invalid_method', 'wrong method' );
			}

			return array( 'ok' => true );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/get-venue', array() );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( array( 'POST', 'GET' ), $attempts );
	}

	public function test_run_ability_sends_empty_input_as_the_empty_list_query_marker_on_the_get_retry(): void {
		$captured = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$captured ) {
			$captured[] = array( $method, $args );

			if ( 'POST' === $method ) {
				return new WP_Error( 'rest_ability_invalid_method', 'Read-only abilities require GET method.' );
			}

			return array( 'ok' => true );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/community-list-forums', array() );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( 'GET', $captured[1][0] );

		// WordPress 7.1's run route coerces input to the target's schema, and
		// '' coerces to array() for an object schema; an empty array would be
		// dropped by http_build_query() entirely — see
		// NetworkDispatch::EMPTY_INPUT_QUERY_VALUE (#26).
		$this->assertSame( array( 'query' => array( 'input' => '' ) ), $captured[1][1] );
	}

	public function test_run_ability_retries_empty_input_without_input_when_the_target_takes_none(): void {
		$captured = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$captured ) {
			$captured[] = array( $method, $args );

			if ( 'POST' === $method ) {
				return new WP_Error( 'rest_ability_invalid_method', 'Read-only abilities require GET method.' );
			}

			// A schema-less ability refuses any non-null input.
			if ( isset( $args['query']['input'] ) ) {
				return new WP_Error( 'ability_invalid_input', 'Ability does not accept input.' );
			}

			return array( 'ok' => true );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/get-network-stats', array() );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 3, $captured );
		$this->assertSame( array( 'GET', array() ), $captured[2] );
	}

	public function test_run_ability_does_not_retry_invalid_non_empty_input(): void {
		$attempts = 0;

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$attempts ) {
			++$attempts;

			return 'POST' === $method
				? new WP_Error( 'rest_ability_invalid_method', 'Read-only abilities require GET method.' )
				: new WP_Error( 'ability_invalid_input', 'bad input' );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/community-list-forums', array( 'per_page' => 3 ) );

		$this->assertWPError( $result );
		$this->assertSame( 2, $attempts );
	}

	public function test_list_abilities_reads_cores_collection_route_over_http_loopback_and_paginates(): void {
		$calls = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$calls ) {
			$calls[] = array( $method, $path, $args['query'] ?? array(), apply_filters( 'ec_cross_site_use_http_loopback', false, $site_key, $method, $path, $args ) );
			$page    = (int) ( $args['query']['page'] ?? 1 );
			$count   = 1 === $page ? 100 : 3;
			$records = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$records[] = array( 'name' => "ns/ability-$page-$i" );
			}
			return $records;
		};

		add_filter( 'extrachill_mcp_ability_list_ttl', '__return_zero' );
		$result = NetworkDispatch::list_abilities( 'events', 'venues' );
		remove_filter( 'extrachill_mcp_ability_list_ttl', '__return_zero' );

		$this->assertIsArray( $result );
		$this->assertCount( 103, $result );
		$this->assertCount( 2, $calls );
		$this->assertSame(
			array(
				'GET',
				'/wp-abilities/v1/abilities',
				array(
					'per_page' => 100,
					'page'     => 1,
					'_fields'  => 'name,label,description,category,input_schema.required',
					'category' => 'venues',
				),
				true,
			),
			$calls[0]
		);
		$this->assertFalse( apply_filters( 'ec_cross_site_use_http_loopback', false ), 'The loopback override must not leak past the call.' );
	}

	public function test_run_ability_still_sends_non_empty_input_as_an_array_on_the_get_retry(): void {
		$captured = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$captured ) {
			$captured[] = array( $method, $args );

			if ( 'POST' === $method ) {
				return new WP_Error( 'rest_ability_invalid_method', 'Read-only abilities require GET method.' );
			}

			return array( 'ok' => true );
		};

		NetworkDispatch::run_ability( 'events', 'extrachill/community-list-forums', array( 'archive_only' => false ) );

		$this->assertSame( array( 'query' => array( 'input' => array( 'archive_only' => false ) ) ), $captured[1][1] );
	}

	public function test_run_ability_forces_the_http_loopback_filter_only_for_the_duration_of_the_call(): void {
		$forced = null;

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$forced ) {
			// Mirror the real transport's own decision point.
			$forced = apply_filters( 'ec_cross_site_use_http_loopback', false, $site_key, $method, $path, $args );

			return array( 'ok' => true );
		};

		NetworkDispatch::run_ability( 'events', 'agents/ability-search', array( 'query' => 'venue' ), true );

		$this->assertTrue( $forced, 'The forced hop must see the loopback filter answer true.' );
		$this->assertFalse(
			apply_filters( 'ec_cross_site_use_http_loopback', false ),
			'The filter must be removed again after the call.'
		);

		NetworkDispatch::run_ability( 'events', 'agents/ability-search', array( 'query' => 'venue' ) );

		$this->assertFalse( $forced, 'An unforced hop must leave the loopback decision to the transport.' );
	}

	public function test_owner_candidates_reads_the_network_ownership_index(): void {
		NetworkStub::$owner_index = array(
			'datamachine/instagram-read' => array(
				'main' => true,
				'wire' => true,
			),
		);

		$this->assertSame( array( 'main', 'wire' ), NetworkDispatch::owner_candidates( 'datamachine/instagram-read' ) );
		$this->assertSame( array(), NetworkDispatch::owner_candidates( 'extrachill/unknown' ) );
	}

	public function test_describe_error_appends_the_code_when_the_message_hides_it(): void {
		$this->assertSame(
			'Cross-site request failed (ability_invalid_input)',
			NetworkDispatch::describe_error( new WP_Error( 'ability_invalid_input', 'Cross-site request failed' ) )
		);
		$this->assertSame(
			'ability_invalid_input is not allowed',
			NetworkDispatch::describe_error( new WP_Error( 'ability_invalid_input', 'ability_invalid_input is not allowed' ) )
		);
		$this->assertSame(
			'ability_invalid_input',
			NetworkDispatch::describe_error( new WP_Error( 'ability_invalid_input', '' ) )
		);
	}

	public function test_run_ability_does_not_retry_on_a_different_error(): void {
		$attempts = array();

		NetworkStub::$cross_site_handler = static function ( string $site_key, string $method, string $path, array $args ) use ( &$attempts ) {
			$attempts[] = $method;

			return new WP_Error( 'http_request_failed', 'nope' );
		};

		$result = NetworkDispatch::run_ability( 'events', 'extrachill/get-venue', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertSame( array( 'POST' ), $attempts );
	}

	public function test_list_abilities_caches_a_sites_list_and_does_not_cache_errors(): void {
		$calls = 0;
		$fail  = true;

		NetworkStub::$cross_site_handler = static function () use ( &$calls, &$fail ) {
			++$calls;
			return $fail ? new WP_Error( 'http_request_failed', 'timeout' ) : array( array( 'name' => 'ns/a' ) );
		};

		$this->assertWPError( NetworkDispatch::list_abilities( 'events' ) );

		$fail = false;
		$this->assertSame( array( array( 'name' => 'ns/a' ) ), NetworkDispatch::list_abilities( 'events' ) );
		$this->assertSame( array( array( 'name' => 'ns/a' ) ), NetworkDispatch::list_abilities( 'events' ) );
		$this->assertSame( 2, $calls, 'The error must not be cached; the success must be.' );

		delete_site_transient( 'extrachill_mcp_abilities_' . md5( 'events|' ) );
	}
}
