<?php
/**
 * Behavioral tests for qualified event-source intake.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated pure-unit fixtures intentionally declare WordPress doubles.

require_once dirname( __DIR__, 3 ) . '/inc/Core/ArtistUrlSubmissionsTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueExpansionRunner.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/FlowLocationGuard.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/VenueAddAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/ArtistUrlImportAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/Api/Controllers/ArtistUrlImport.php';
require_once dirname( __DIR__, 3 ) . '/inc/Api/ArtistUrlImportRoutes.php';

use ExtraChillEvents\Abilities\ArtistUrlImportAbilities;
use ExtraChillEvents\Abilities\VenueAddAbilities;
use ExtraChillEvents\Abilities\VenueQualificationAbilities;
use ExtraChillEvents\Api\Controllers\ArtistUrlImport;
use ExtraChillEvents\Core\ArtistUrlSubmissionsTable;
use ExtraChillEvents\Core\VenueExpansionRunner;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id;
		public string $name;
		public int $parent = 0;

		public function __construct( int $term_id, string $name, int $parent = 0 ) {
			$this->term_id = $term_id;
			$this->name    = $name;
			$this->parent  = $parent;
		}
	}
}

if ( ! class_exists( 'DataMachine\Core\Selection\SelectionMode' ) ) {
	class EventSourceSelectionMode {
		public const AI_DECIDES = 'ai_decides';
		public const SKIP = 'skip';
	}
	class_alias( EventSourceSelectionMode::class, 'DataMachine\Core\Selection\SelectionMode' );
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params;
		private array $headers;

		public function __construct( array $params = array(), array $headers = array( 'accept' => 'application/json' ) ) {
			$this->params  = $params;
			$this->headers = $headers;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_header( string $key ): string {
			return (string) ( $this->headers[ $key ] ?? '' );
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public int $status;

		public function __construct( $data, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( $taxonomy, array( 'artist', 'venue', 'location' ), true );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $title ), '-' ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return (string) $email;
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		$key = $taxonomy . ':' . strtolower( (string) $value );
		return $GLOBALS['event_source_terms'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		return $GLOBALS['event_source_term_ids'][ $taxonomy . ':' . (int) $term_id ] ?? null;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		if ( 'location' === ( $args['taxonomy'] ?? '' ) ) {
			return array_values( array_filter( $GLOBALS['event_source_term_ids'], static fn( $term, $key ) => str_starts_with( $key, 'location:' ), ARRAY_FILTER_USE_BOTH ) );
		}
		return array( 72 => 'The Band' );
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key ) {
		return $GLOBALS['event_source_term_meta'][ (int) $term_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
	function data_machine_events_get_venue_data( int $term_id ): ?array {
		return $GLOBALS['event_source_venue_data'][ $term_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 7;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return $GLOBALS['event_source_current_user'] ?? (object) array( 'user_email' => 'person@example.test', 'display_name' => 'Person', 'user_login' => 'person' );
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient() {}
}

if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term_id, $taxonomy ) {
		return 'https://events.test/' . $taxonomy . '/' . $term_id;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://events.test' . $path;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return 'admin_email' === $name ? '' : $default;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail() {
		return true;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo() {
		return 'Events Test';
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		$GLOBALS['event_source_registered_abilities'][ $name ] = $definition;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $definition ) {
		$GLOBALS['event_source_registered_routes'][ $namespace . $route ] = $definition;
	}
}

final class EventSourceWpdb {
	public string $base_prefix = 'wp_';
	public string $prefix = 'wp_';
	public array $submissions = array();
	public array $pipelines = array();
	public array $flows = array();
	public array $updates = array();
	public int $insert_id = 0;

	public function prepare( $query, ...$args ) {
		return array( 'query' => $query, 'args' => $args );
	}

	public function get_row( $prepared, $format = null ) {
		$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['args'] : array();
		if ( str_contains( $query, 'artist_url_submissions' ) ) {
			if ( str_contains( $query, 'url_hash' ) ) {
				foreach ( $this->submissions as $row ) {
					if ( $row['url_hash'] === ( $args[0] ?? '' ) ) {
						return $row;
					}
				}
			} else {
				return $this->submissions[ (int) ( $args[0] ?? 0 ) ] ?? null;
			}
		}
		if ( str_contains( $query, 'datamachine_pipelines' ) ) {
			return $this->pipelines[ (int) ( $args[0] ?? 0 ) ] ?? null;
		}
		return null;
	}

	public function get_results( $prepared, $format = null ): array {
		$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['args'] : array();
		if ( str_contains( $query, 'artist_url_submissions' ) ) {
			return array_values( $this->submissions );
		}
		if ( str_contains( $query, 'datamachine_flows' ) ) {
			$pipeline_id = (int) ( $args[0] ?? 0 );
			return array_values( array_filter( $this->flows, static fn( $row ) => (int) $row['pipeline_id'] === $pipeline_id ) );
		}
		return array();
	}

	public function insert( $table, array $row ) {
		$this->insert_id                = count( $this->submissions ) + 1;
		$row['id']                      = $this->insert_id;
		$this->submissions[ $this->insert_id ] = $row;
		return 1;
	}

	public function update( $table, array $data, array $where ) {
		$id = (int) $where['id'];
		$this->updates[] = compact( 'table', 'data', 'where' );
		$this->submissions[ $id ] = array_merge( $this->submissions[ $id ] ?? array( 'id' => $id ), $data );
		return 1;
	}
}

final class EventSourceAbilityDouble {
	public array $calls = array();
	private $result;

	public function __construct( $result ) {
		$this->result = $result;
	}

	public function execute( array $input ) {
		$this->calls[] = $input;
		return is_callable( $this->result ) ? ( $this->result )( $input ) : $this->result;
	}
}

class TestableEventSourceIntake extends ArtistUrlImportAbilities {
	public array $qualifications = array();

	protected function qualifyForAdmission( string $url, bool $legacy_artist = false ) {
		$result = array_shift( $this->qualifications );
		return is_callable( $result ) ? $result( $url, $legacy_artist ) : $result;
	}
}

final class EventSourceIntakeTest extends BookingTestCase {
	private ArtistUrlImportAbilities $intake;
	private EventSourceWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['event_source_terms'] = array(
			'venue:the room'  => new WP_Term( 41, 'The Room' ),
			'artist:the band' => new WP_Term( 72, 'The Band' ),
			'artist:other artist' => new WP_Term( 73, 'Other Artist' ),
		);
		$GLOBALS['event_source_term_ids'] = array(
			'venue:41'   => $GLOBALS['event_source_terms']['venue:the room'],
			'artist:72'  => $GLOBALS['event_source_terms']['artist:the band'],
			'artist:73'  => $GLOBALS['event_source_terms']['artist:other artist'],
			'location:10'=> new WP_Term( 10, 'Charleston' ),
		);
		$GLOBALS['event_source_venue_data'] = array( 41 => array( 'website' => 'https://venue.test' ) );
		$GLOBALS['event_source_registered_abilities'] = array();
		$GLOBALS['event_source_registered_routes']    = array();
		$GLOBALS['event_source_current_user']         = (object) array( 'user_email' => 'person@example.test', 'display_name' => 'Person', 'user_login' => 'person' );
		$GLOBALS['ec_artist_test']['registered']      = array();
		$this->wpdb = new EventSourceWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->intake = ( new ReflectionClass( ArtistUrlImportAbilities::class ) )->newInstanceWithoutConstructor();
	}

	private function classification( array $events, string $url = 'https://venue.test/events', bool $compatibility_artist = false, string $html = '' ): array {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'classifySource' );
		$method->setAccessible( true );
		return $method->invoke(
			$this->intake,
			$url,
			array(
				'events_found'    => count( $events ),
				'raw_events'      => $events,
				'raw_first_event' => $events[0] ?? array(),
				'page_html'       => $html,
			),
			array( 'fingerprint' => array( 'structured_data' => array( 'event_page_shape' => 'recurring' ) ) ),
			$compatibility_artist
		);
	}

	private function qualification( string $kind = 'artist', bool $eligible = true, string $url = 'https://source.test/events' ): array {
		$term_id = 'venue' === $kind ? 41 : 72;
		$name    = 'venue' === $kind ? 'The Room' : 'The Band';
		return array(
			'success'              => true,
			'qualified'            => true,
			'canonical_events_url' => $url,
			'source_identity_url'  => ArtistUrlSubmissionsTable::normalize_url( $url ),
			'verdict'              => 'qualified_structured',
			'events_found'         => 3,
			'events_preview'       => array(),
			'extraction_method'    => 'json_ld',
			'source_kind'          => $kind,
			'recommended_binding'  => array( 'taxonomy' => $kind, 'term_id' => $term_id, 'name' => $name ),
			'existing_coverage'    => array( 'covered' => false, 'type' => 'none' ),
			'warnings'             => array(),
			'recurring_eligible'   => $eligible,
		);
	}

	private function pending_submission( string $kind ): array {
		return array(
			'id'                       => 1,
			'user_id'                  => 0,
			'url'                      => 'https://source.test/events',
			'url_hash'                 => ArtistUrlSubmissionsTable::url_hash( 'https://source.test/events' ),
			'canonical_url'            => 'https://source.test/events',
			'source_kind'              => $kind,
			'entity_taxonomy'          => $kind,
			'entity_term_id'           => 'venue' === $kind ? 41 : 72,
			'entity_name'              => 'venue' === $kind ? 'The Room' : 'The Band',
			'suggested_artist_term_id' => 'artist' === $kind ? 72 : null,
			'status'                   => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			'events_found_count'       => 3,
		);
	}

	private function legacy_submission(): array {
		return array(
			'id'                       => 1,
			'user_id'                  => 0,
			'url'                      => 'https://source.test/events?view=calendar&utm_source=legacy',
			'url_hash'                 => ArtistUrlSubmissionsTable::url_hash( 'https://source.test/events?view=calendar&utm_source=legacy' ),
			'canonical_url'            => '',
			'source_kind'              => '',
			'entity_taxonomy'          => '',
			'entity_term_id'           => null,
			'entity_name'              => '',
			'suggested_artist_name'    => 'The Band',
			'suggested_artist_term_id' => null,
			'detected_format'           => 'json_ld',
			'events_found_count'       => 1,
			'status'                   => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
		);
	}

	private function safe_legacy_qualification( int $events_found = 1 ): array {
		$fresh                          = $this->qualification( 'unknown', false, 'https://source.test/events?view=calendar&utm_source=legacy' );
		$fresh['success']               = true;
		$fresh['events_found']          = $events_found;
		$fresh['verdict']               = 'extraction_gap';
		$fresh['extraction_method']     = 'json_ld';
		$fresh['recommended_binding']   = array( 'taxonomy' => '', 'term_id' => null, 'name' => '' );
		return $fresh;
	}

	private function artist_flow_abilities( EventSourceAbilityDouble $create_flow ): array {
		return array(
			'datamachine/get-pipeline-configuration' => new EventSourceAbilityDouble(
				array(
					'success'  => true,
					'pipeline' => array( 'pipeline_id' => 42 ),
					'flows'    => array( array( 'flow_id' => 77, 'revision' => 'sha256:' . str_repeat( 'a', 64 ) ) ),
				)
			),
			'datamachine/create-flow' => $create_flow,
			'datamachine/update-step-configuration' => new EventSourceAbilityDouble( array( 'success' => true, 'revision' => 'sha256:' . str_repeat( 'b', 64 ) ) ),
			'datamachine/run-flow' => new EventSourceAbilityDouble( array( 'success' => true ) ),
		);
	}

	private function city_pipeline_fixture(): void {
		$this->wpdb->pipelines[5] = array( 'pipeline_id' => 5, 'pipeline_name' => 'Display Name Is Not Identity' );
		$this->wpdb->flows[]      = array(
			'pipeline_id' => 5,
			'flow_config' => wp_json_encode(
				array(
					'import' => array( 'step_type' => 'event_import' ),
					'upsert' => array(
						'step_type'       => 'upsert',
						'handler_configs' => array( 'upsert_event' => array( 'taxonomy_location_selection' => '10' ) ),
					),
				)
			),
		);
	}

	public function test_venue_requires_official_host_ownership(): void {
		$events = array(
			array( 'venue' => 'The Room', 'performer' => 'Artist A' ),
			array( 'venue' => 'The Room', 'performer' => 'Artist B' ),
		);
		$this->assertSame( 'venue', $this->classification( $events )['source_kind'] );
		$this->assertSame( 'unknown', $this->classification( $events, 'https://promoter.test/calendar' )['source_kind'] );
	}

	public function test_platform_and_aggregator_hosts_remain_unknown(): void {
		$events = array(
			array( 'venue' => 'The Room', 'performer' => 'Artist A' ),
			array( 'venue' => 'The Room', 'performer' => 'Artist B' ),
		);
		$result = $this->classification( $events, 'https://www.eventbrite.com/o/promoter/events' );
		$this->assertSame( 'unknown', $result['source_kind'] );
		$this->assertFalse( $result['scope_evidence']['bounded'] );
	}

	public function test_artist_requires_repeated_single_performer_scope(): void {
		$result = $this->classification(
			array(
				array( 'venue' => 'Room A', 'performer' => 'The Band' ),
				array( 'venue' => 'Room B', 'performer' => 'The Band' ),
			),
			'https://the-band.test/tour'
		);
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertTrue( $result['scope_evidence']['bounded'] );
	}

	public function test_submission_rejects_ineligible_source_without_queueing(): void {
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'artist', false );
		$result = $intake->executeSubmit( array( 'url' => 'https://source.test/events' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'source_not_admissible', $result->get_error_code() );
		$this->assertSame( array(), $this->wpdb->submissions );
	}

	public function test_operational_query_url_is_qualified_and_persisted_while_hash_uses_identity(): void {
		$GLOBALS['event_source_current_user']->user_email = '';
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$seen   = '';
		$operational_url = 'https://source.test/events?view=calendar&utm_source=email';
		$intake->qualifications[] = function ( string $url ) use ( &$seen, $operational_url ) {
			$seen = $url;
			return $this->qualification( 'artist', true, $operational_url );
		};

		$result = $intake->executeSubmit( array( 'url' => $operational_url . '#schedule' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $operational_url, $seen );
		$this->assertSame( $operational_url, $this->wpdb->submissions[1]['url'] );
		$this->assertSame( $operational_url, $this->wpdb->submissions[1]['canonical_url'] );
		$this->assertSame(
			ArtistUrlSubmissionsTable::url_hash( 'https://source.test/events?view=calendar' ),
			$this->wpdb->submissions[1]['url_hash']
		);
	}

	public function test_approval_requalifies_and_blocks_stale_admission(): void {
		$this->wpdb->submissions[1] = $this->pending_submission( 'artist' );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$fresh = $this->qualification( 'artist', false );
		$fresh['existing_coverage'] = array( 'covered' => true, 'type' => 'universal_scraper_flow' );
		$intake->qualifications[] = $fresh;
		$result = $intake->executeApprove( array( 'submission_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'source_no_longer_admissible', $result->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updates );
	}

	public function test_artist_approval_dispatches_only_after_fresh_admission(): void {
		$this->wpdb->submissions[1] = $this->pending_submission( 'artist' );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$operational_url = 'https://source.test/events?view=calendar&utm_source=approval';
		$intake->qualifications[] = $this->qualification( 'artist', true, $operational_url );
		$create_flow = new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) );
		$abilities   = $this->artist_flow_abilities( $create_flow );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 1 ) );

		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( '72', $create_flow->calls[0]['step_configs']['upsert']['handler_config']['taxonomy_artist_selection'] );
		$this->assertSame( $operational_url, $create_flow->calls[0]['step_configs']['event_import']['handler_config']['source_url'] );
	}

	public function test_unmatched_fresh_artist_rejects_unrelated_explicit_term_without_side_effects(): void {
		$row                         = $this->pending_submission( 'artist' );
		$row['entity_term_id']       = null;
		$row['entity_name']          = 'New Artist';
		$this->wpdb->submissions[1] = $row;
		$fresh                       = $this->qualification( 'artist', true );
		$fresh['recommended_binding'] = array( 'taxonomy' => 'artist', 'term_id' => null, 'name' => 'New Artist' );
		$intake                       = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[]     = $fresh;

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'artist_term_id' => 72 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_identity_changed', $result->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updates );
	}

	public function test_legacy_one_event_submission_allows_matching_explicit_artist(): void {
		$this->wpdb->submissions[1] = $this->legacy_submission();
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->safe_legacy_qualification( 1 );
		$create_flow = new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) );
		$abilities   = $this->artist_flow_abilities( $create_flow );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'artist_term_id' => 72 ) );

		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 72, $result['artist_term_id'] );
	}

	public function test_legacy_multiple_same_venue_evidence_remains_approvable(): void {
		$row                             = $this->legacy_submission();
		$row['events_found_count']       = 2;
		$this->wpdb->submissions[1]     = $row;
		$intake                          = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[]        = $this->safe_legacy_qualification( 2 );
		$create_flow                     = new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) );
		$abilities                       = $this->artist_flow_abilities( $create_flow );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'artist_name' => 'The Band' ) );

		$this->assertSame( 'artist', $result['source_kind'] );
	}

	public function test_failed_legacy_identity_can_retry_without_losing_compatibility(): void {
		$this->wpdb->submissions[1] = $this->legacy_submission();
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->safe_legacy_qualification( 1 );
		$intake->qualifications[] = $this->safe_legacy_qualification( 1 );
		$create_flow = new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) );
		$abilities   = $this->artist_flow_abilities( $create_flow );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$failed = $intake->executeApprove( array( 'submission_id' => 1, 'artist_term_id' => 73 ) );
		$this->assertInstanceOf( WP_Error::class, $failed );
		$this->assertSame( array(), $this->wpdb->updates );
		$this->assertSame( '', $this->wpdb->submissions[1]['source_kind'] );

		$retried = $intake->executeApprove( array( 'submission_id' => 1, 'artist_term_id' => 72 ) );
		$this->assertSame( 'artist', $retried['source_kind'] );
	}

	public function test_venue_approval_validates_pipeline_and_dispatches_owner(): void {
		$this->city_pipeline_fixture();
		$this->wpdb->submissions[1] = $this->pending_submission( 'venue' );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'venue', true );
		$add_venue = new EventSourceAbilityDouble( array( 'flow_id' => 88, 'pipeline_id' => 5, 'venue_term_id' => 41 ) );
		$run_flow  = new EventSourceAbilityDouble( array( 'success' => true ) );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => array(
			'extrachill/add-venue'     => $add_venue,
			'datamachine/run-flow'     => $run_flow,
		)[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'pipeline_id' => 5 ) );

		$this->assertSame( 'venue', $result['source_kind'] );
		$this->assertSame( 41, $add_venue->calls[0]['venue_term_id'] );
		$this->assertSame( 5, $add_venue->calls[0]['pipeline_id'] );
	}

	public function test_arbitrary_or_ambiguous_pipeline_is_rejected(): void {
		$this->wpdb->pipelines[9] = array( 'pipeline_id' => 9, 'pipeline_name' => 'Artist Tour Import' );
		$validator = ( new ReflectionClass( VenueAddAbilities::class ) )->newInstanceWithoutConstructor();
		$result    = $validator->validateCityPipeline( 9 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_city_pipeline', $result->get_error_code() );
	}

	public function test_mixed_import_and_located_upsert_flows_do_not_form_a_city_pipeline(): void {
		$this->wpdb->pipelines[11] = array( 'pipeline_id' => 11, 'pipeline_name' => 'Mixed Events' );
		$this->wpdb->flows[] = array(
			'pipeline_id' => 11,
			'flow_config' => wp_json_encode( array( 'import' => array( 'step_type' => 'event_import' ) ) ),
		);
		$this->wpdb->flows[] = array(
			'pipeline_id' => 11,
			'flow_config' => wp_json_encode(
				array(
					'upsert' => array(
						'step_type'       => 'upsert',
						'handler_configs' => array( 'upsert_event' => array( 'taxonomy_location_selection' => '10' ) ),
					),
				)
			),
		);
		$validator = ( new ReflectionClass( VenueAddAbilities::class ) )->newInstanceWithoutConstructor();

		$result = $validator->validateCityPipeline( 11 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_city_pipeline', $result->get_error_code() );
	}

	public function test_city_pipeline_location_comes_from_bound_term_not_name(): void {
		$this->city_pipeline_fixture();
		$validator = ( new ReflectionClass( VenueAddAbilities::class ) )->newInstanceWithoutConstructor();
		$result    = $validator->validateCityPipeline( 5 );

		$this->assertSame( 10, $result['location_term_id'] );
		$this->assertSame( 'Charleston', $result['location']->name );
	}

	public function test_canonical_dedup_reads_legacy_tracking_variants(): void {
		$this->wpdb->submissions[4] = array(
			'id'        => 4,
			'url'       => 'https://venue.test/events/?utm_source=email',
			'url_hash'  => hash( 'sha256', 'legacy-value' ),
			'status'    => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
		);
		$found = ArtistUrlSubmissionsTable::find_tracked_by_url( 'https://venue.test/events#calendar' );

		$this->assertSame( 4, $found['id'] );
		$this->assertSame(
			'https://venue.test/events',
			ArtistUrlSubmissionsTable::normalize_url( 'https://venue.test:443/events/?utm_medium=social#calendar' )
		);
	}

	public function test_only_generic_ability_schemas_and_rest_routes_register(): void {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'registerGenericAbilities' );
		$method->setAccessible( true );
		$method->invoke( $this->intake );
		$registered = array_merge( $GLOBALS['event_source_registered_abilities'], $GLOBALS['ec_artist_test']['registered'] );
		$qualify    = $registered['extrachill/qualify-event-source'];
		$approve    = $registered['extrachill-events/approve-event-source-submission'];
		$this->assertArrayHasKey( 'recurring_eligible', $qualify['output_schema']['properties'] );
		$this->assertCount( 2, $approve['output_schema']['oneOf'] );
		foreach ( array( 'preview', 'submit', 'approve', 'reject' ) as $action ) {
			$this->assertArrayNotHasKey( "extrachill-events/{$action}-artist-url" . ( in_array( $action, array( 'approve', 'reject' ), true ) ? '-submission' : '' ), $registered );
		}

		\ExtraChillEvents\Api\register_event_source_routes_for( 'extrachill/v1' );
		$this->assertArrayHasKey( 'extrachill/v1/event-source/preview', $GLOBALS['event_source_registered_routes'] );
		$this->assertCount( 4, $GLOBALS['event_source_registered_routes'] );
		foreach ( array_keys( $GLOBALS['event_source_registered_routes'] ) as $route ) {
			$this->assertStringNotContainsString( '/artist-url/', $route );
		}
		foreach ( array_keys( $registered ) as $ability_name ) {
			$this->assertStringNotContainsString( 'artist-url', $ability_name );
		}
	}

	public function test_generic_rest_callback_invokes_event_source_ability(): void {
		$ability = new EventSourceAbilityDouble( array( 'success' => true ) );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => 'extrachill-events/preview-event-source' === $name ? $ability : null;
		$response = ( new ArtistUrlImport() )->preview_event_source( new WP_REST_Request( array( 'url' => 'https://artist.test/tour' ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'https://artist.test/tour', $ability->calls[0]['url'] );
	}

	public function test_venue_qualifier_discovers_canonical_navigation_candidate(): void {
		$qualifier = ( new ReflectionClass( VenueQualificationAbilities::class ) )->newInstanceWithoutConstructor();
		$method    = new ReflectionMethod( VenueQualificationAbilities::class, 'buildCandidateUrls' );
		$method->setAccessible( true );
		$candidates = $method->invoke( $qualifier, 'https://venue.test', 'https://venue.test', '<nav><a href="/calendar">Upcoming shows</a></nav>' );
		$this->assertContains( 'https://venue.test/calendar', array_column( $candidates, 'url' ) );
	}

	public function test_existing_coverage_uses_venue_expansion_flow_lookup(): void {
		$runner = new VenueExpansionRunner( null, null, static fn() => array( 'flow_id' => 99, 'flow_name' => 'Existing' ) );
		$this->assertSame( 99, $runner->lookupExistingFlow( 'https://venue.test/calendar' )['flow_id'] );
	}
}
