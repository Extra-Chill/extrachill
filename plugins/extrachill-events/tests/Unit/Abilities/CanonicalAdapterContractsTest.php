<?php
/**
 * Canonical event and venue adapter contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress test doubles.

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id;
		public string $name;
		public string $slug;

		public function __construct( int $term_id, string $name, string $slug ) {
			$this->term_id = $term_id;
			$this->name    = $name;
			$this->slug    = $slug;
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
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return $GLOBALS['ec_adapter_abilities'][ $name ] ?? null;
	}
}
if ( ! function_exists( 'update_termmeta_cache' ) ) {
	function update_termmeta_cache( $term_ids ) {
		$GLOBALS['ec_adapter_primed_ids'] = $term_ids;
	}
}
if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
	function data_machine_events_get_venue_data( int $term_id ): ?array {
		$GLOBALS['ec_adapter_venue_reads'][] = array(
			'term_id' => $term_id,
			'primed'  => in_array( $term_id, $GLOBALS['ec_adapter_primed_ids'] ?? array(), true ),
		);

		return $GLOBALS['ec_adapter_venues'][ $term_id ] ?? null;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy ) {
		return $GLOBALS['ec_adapter_relationships'][ $post_id ][ $taxonomy ] ?? array();
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term, $taxonomy = '' ) {
		return 'https://events.example/' . $taxonomy . '/' . $term->slug . '/';
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key ) {
		$GLOBALS['ec_adapter_meta_reads'][] = array( $post_id, $key );

		return $GLOBALS['ec_adapter_post_meta'][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return 'https://events.example/events/' . $post_id . '/';
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-get-venue.php';
require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-list-venues.php';
require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-check-venue-duplicate.php';
require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-calendar.php';

final class CanonicalAdapterContractsTest extends TestCase {
	private const DME_CONTRACT_NAME = 'data-machine-events/calendar-occurrence';

	private const DME_CONTRACT_VERSION = 1;

	private const DME_CONTRACT_HASH = 'fd1cbb79a52c405f747eaacf30b161f444c210c75c5189d9e446623d2badc84f';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['ec_adapter_abilities']     = array();
		$GLOBALS['ec_adapter_primed_ids']    = array();
		$GLOBALS['ec_adapter_venue_reads']   = array();
		$GLOBALS['ec_adapter_relationships'] = array();
		$GLOBALS['ec_adapter_meta_reads']    = array();
		$GLOBALS['ec_adapter_post_meta']     = array();
		$GLOBALS['ec_adapter_venues']        = array(
			44 => array(
				'term_id'     => 44,
				'name'        => 'The Royal American',
				'slug'        => 'the-royal-american',
				'description' => 'Neighborhood music venue.',
				'address'     => '970 Morrison Dr',
				'city'        => 'Charleston',
				'state'       => 'SC',
				'zip'         => '29403',
				'country'     => 'US',
				'coordinates' => '32.8007,-79.9362',
				'timezone'    => 'America/New_York',
				'website'     => 'https://theroyalamerican.com',
				'phone'       => '843-817-6925',
				'capacity'    => '250',
			),
		);
	}

	public function test_venue_list_primes_metadata_and_matches_detail_identity_fields(): void {
		$list = extrachill_events_transform_venue_list(
			array(
				array(
					'term_id'     => 44,
					'name'        => 'The Royal American',
					'slug'        => 'the-royal-american',
					'lat'         => 32.8007,
					'lon'         => -79.9362,
					'address'     => '970 Morrison Dr, Charleston, SC, 29403',
					'url'         => 'https://events.example/venue/the-royal-american/',
					'event_count' => 7,
				),
			)
		);

		$detail = extrachill_events_ability_get_venue( array( 'id' => 44 ) );

		$this->assertTrue( $GLOBALS['ec_adapter_venue_reads'][0]['primed'] );
		foreach ( array( 'id', 'name', 'slug', 'address', 'city', 'state', 'zip', 'country', 'latitude', 'longitude', 'coordinates', 'timezone', 'website' ) as $field ) {
			$this->assertSame( $detail[ $field ], $list[0][ $field ], $field );
		}
		$this->assertSame( '970 Morrison Dr, Charleston, SC, 29403', $list[0]['formatted_address'] );
		$this->assertSame( 7, $list[0]['event_count'] );
		$this->assertSame( '', $detail['url'] );
	}

	public function test_duplicate_check_delegates_all_identity_evidence_and_returns_canonical_venue(): void {
		$resolver = function ( string $name, array $venue_data ): array {
			$GLOBALS['ec_adapter_duplicate_input'] = array_merge( array( 'name' => $name ), $venue_data );

			return array(
				'term_id'      => 44,
				'match_status' => 'matched',
			);
		};

		$result = extrachill_events_find_duplicate_venues(
			array(
				'name'    => 'Royal American',
				'address' => '970 Morrison Dr',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			),
			$resolver
		);

		$this->assertSame(
			array(
				'name'    => 'Royal American',
				'address' => '970 Morrison Dr',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			),
			$GLOBALS['ec_adapter_duplicate_input']
		);
		$this->assertSame( 44, $result[0]['id'] );
		$this->assertSame( 'America/New_York', $result[0]['timezone'] );
		$this->assertSame(
			array(),
			extrachill_events_find_duplicate_venues(
				array( 'name' => 'The Foundry', 'city' => 'Atlanta' ),
				static fn() => array(
					'term_id'      => null,
					'match_status' => 'ambiguous',
				)
			)
		);
	}

	public function test_calendar_adapter_preserves_canonical_event_and_occurrence_contracts(): void {
		$fixture = $this->load_pinned_dme_contract();
		$GLOBALS['ec_adapter_post_meta'][ $fixture['event']['id'] ]['_datamachine_ticket_url'] = 'https://tickets.example/fallback';
		$event   = extrachill_events_transform_calendar_event( $fixture );

		$this->assertSame( $fixture['event']['performer'], $event['performer'] );
		$this->assertSame( $fixture['event']['organizer'], $event['organizer'] );
		$this->assertSame( $fixture['event']['status'], $event['status'] );
		$this->assertSame( $fixture['occurrence']['display_context'], $event['occurrence_context'] );
		$this->assertSame( $fixture['occurrence']['display'], $event['occurrence_display'] );
		$this->assertSame( $fixture['event']['ticket']['url'], $event['ticket_url'] );
		$this->assertSame( array(), $GLOBALS['ec_adapter_meta_reads'], 'Canonical ticket URL must avoid the legacy meta fallback.' );
		$this->assertSame( $fixture['event']['id'], $event['id'] );
		$this->assertSame(
			$fixture['event']['date']['start_date'] . 'T' . $fixture['event']['date']['start_time'],
			$event['datetime']
		);
		$this->assertSame(
			$fixture['event']['date']['end_date'] . 'T' . $fixture['event']['date']['end_time'],
			$event['end_datetime']
		);
		foreach ( array( 'artist', 'location', 'promoter' ) as $taxonomy ) {
			$this->assertSame( $fixture['event']['taxonomies'][ $taxonomy ], $event['taxonomies'][ $taxonomy ] );
			$this->assertNotEmpty( $event['taxonomies'][ $taxonomy ] );
			foreach ( $event['taxonomies'][ $taxonomy ] as $term ) {
				$this->assertSame( array( 'term_id', 'name', 'slug' ), array_keys( $term ) );
			}
		}
		foreach ( array( 'address', 'formatted_address', 'city', 'state', 'zip', 'country', 'coordinates', 'timezone', 'website' ) as $field ) {
			$this->assertSame( $fixture['event']['venue'][ $field ], $event['venue'][ $field ], $field );
		}

		// Existing API callers retain their original transport fields.
		foreach ( array( 'id', 'title', 'datetime', 'end_datetime', 'venue', 'occurrence_display', 'ticket_url', 'permalink' ) as $field ) {
			$this->assertArrayHasKey( $field, $event );
		}
	}

	public function test_calendar_adapter_falls_back_to_legacy_ticket_meta_when_canonical_url_is_empty(): void {
		$fixture = $this->load_pinned_dme_contract();
		$post_id = $fixture['event']['id'];
		$fixture['event']['ticket']['url'] = '';
		$GLOBALS['ec_adapter_post_meta'][ $post_id ]['_datamachine_ticket_url'] = 'https://tickets.example/fallback';

		$event = extrachill_events_transform_calendar_event( $fixture );

		$this->assertSame( 'https://tickets.example/fallback', $event['ticket_url'] );
		$this->assertSame( array( array( $post_id, '_datamachine_ticket_url' ) ), $GLOBALS['ec_adapter_meta_reads'] );
	}

	public function test_calendar_contract_pin_rejects_producer_version_and_hash_drift(): void {
		$this->assertTrue( $this->verify_dme_contract_pin( self::DME_CONTRACT_VERSION, self::DME_CONTRACT_HASH ) );
		$this->assertFalse( $this->verify_dme_contract_pin( self::DME_CONTRACT_VERSION + 1, self::DME_CONTRACT_HASH ) );
		$this->assertFalse( $this->verify_dme_contract_pin( self::DME_CONTRACT_VERSION, str_repeat( '0', 64 ) ) );
	}

	private function load_pinned_dme_contract(): array {
		$this->assertTrue( $this->verify_dme_contract_pin( self::DME_CONTRACT_VERSION, self::DME_CONTRACT_HASH ) );
		$root = $this->dme_contract_root();

		return json_decode( (string) file_get_contents( $root . '/calendar-occurrence-v1.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function verify_dme_contract_pin( int $version, string $hash ): bool {
		$root          = $this->dme_contract_root();
		$manifest_path = $root . '/calendar-occurrence-v1.manifest.json';
		$manifest      = json_decode( (string) file_get_contents( $manifest_path ), true, 512, JSON_THROW_ON_ERROR );
		$artifact_path = $root . '/' . $manifest['file'];

		return self::DME_CONTRACT_NAME === $manifest['name']
			&& $version === $manifest['version']
			&& hash_equals( $hash, $manifest['sha256'] )
			&& hash_equals( $manifest['sha256'], hash_file( 'sha256', $artifact_path ) );
	}

	private function dme_contract_root(): string {
		$root = getenv( 'DME_CALENDAR_CONTRACT_ROOT' );
		if ( false === $root || '' === $root ) {
			$root = defined( 'DATA_MACHINE_EVENTS_PATH' ) ? DATA_MACHINE_EVENTS_PATH . 'contracts' : '';
		}
		$this->assertDirectoryExists( $root, 'A composed Data Machine Events contract source is required.' );

		return rtrim( $root, '/' );
	}
}
