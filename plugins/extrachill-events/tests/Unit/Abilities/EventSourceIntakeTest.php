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

use ExtraChillEvents\Abilities\ArtistUrlImportAbilities;
use ExtraChillEvents\Abilities\VenueAddAbilities;
use ExtraChillEvents\Abilities\VenueQualificationAbilities;
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

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( $taxonomy, array( 'artist', 'venue', 'location' ), true );
	}
}

if ( ! function_exists( 'remove_accents' ) ) {
	function remove_accents( $text ) {
		return $text;
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
			$terms = array_values( array_filter( $GLOBALS['event_source_term_ids'], static fn( $term, $key ) => str_starts_with( $key, 'location:' ), ARRAY_FILTER_USE_BOTH ) );
			if ( isset( $args['name'] ) ) {
				$terms = array_values( array_filter( $terms, static fn( $term ) => 0 === strcasecmp( $term->name, (string) $args['name'] ) ) );
			}
			return isset( $args['number'] ) && (int) $args['number'] > 0 ? array_slice( $terms, 0, (int) $args['number'] ) : $terms;
		}
		$terms = array();
		foreach ( $GLOBALS['event_source_term_ids'] as $key => $term ) {
			if ( str_starts_with( $key, 'artist:' ) ) {
				$terms[ $term->term_id ] = $term->name;
			}
		}
		return $terms;
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

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability ) {
		return ! empty( $GLOBALS['ec_artist_test']['user_caps'][ (int) $user_id ][ $capability ] );
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

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return 0 < (int) $user_id ? (object) array( 'ID' => (int) $user_id ) : false;
	}
}

// Same globals as ArtistUrlImportNotificationTest so either definition serves both.
if ( ! function_exists( 'ec_users_notify_with_receipts' ) ) {
	function ec_users_notify_with_receipts( $user_ids, array $data ) {
		$GLOBALS['artist_import_notification_calls'][] = compact( 'user_ids', 'data' );
		return $GLOBALS['artist_import_notification_receipt'] ?? array( 'requested' => 1, 'inserted' => 1, 'existing' => 0, 'failed' => 0, 'recipients' => array() );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		$GLOBALS['event_source_registered_abilities'][ $name ] = $definition;
	}
}

// HTTP doubles for the homepage fetch qualification performs (#1427).
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['event_source_http_calls'][] = $url;
		$page = $GLOBALS['event_source_http'][ $url ] ?? array( 'code' => 404, 'body' => '' );
		return array( 'response' => array( 'code' => $page['code'] ), 'body' => $page['body'], 'headers' => array() );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		return $response['headers'][ $header ] ?? '';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
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
			if ( str_contains( $query, 'universal_web_scraper' ) ) {
				return $this->flows;
			}
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
	private string $name;

	public function __construct( $result, string $name = '' ) {
		$this->result = $result;
		$this->name   = $name;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function execute( array $input ) {
		$this->calls[] = $input;
		return is_callable( $this->result ) ? ( $this->result )( $input ) : $this->result;
	}
}

class TestableEventSourceIntake extends ArtistUrlImportAbilities {
	public array $qualifications = array();

	protected function qualifyForAdmission( string $url ) {
		$result = array_shift( $this->qualifications );
		return is_callable( $result ) ? $result( $url ) : $result;
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
		$GLOBALS['event_source_current_user']         = (object) array( 'user_email' => 'person@example.test', 'display_name' => 'Person', 'user_login' => 'person' );
		$GLOBALS['ec_artist_test']['registered']      = array();
		$GLOBALS['ec_artist_test']['user_caps'][7]    = array( 'manage_options' => false, 'access_events_admin' => false );
		$this->wpdb = new EventSourceWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->intake = ( new ReflectionClass( ArtistUrlImportAbilities::class ) )->newInstanceWithoutConstructor();
	}

	private function classification( array $events, string $url = 'https://venue.test/events', string $html = '', string $format = '' ): array {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'classifySource' );
		$method->setAccessible( true );
		return $method->invoke(
			$this->intake,
			$url,
			array(
				'detected_format' => $format,
				'events_found'    => count( $events ),
				'raw_events'      => $events,
				'raw_first_event' => $events[0] ?? array(),
				'page_html'       => $html,
			),
			array( 'fingerprint' => array( 'structured_data' => array( 'event_page_shape' => 'recurring' ) ) )
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

	public function test_preview_returns_covered_source_with_zero_events(): void {
		$intake                        = new TestableEventSourceIntake();
		$covered                       = $this->qualification( 'venue', false, 'https://www.ticketmaster.com/venue/1' );
		$covered['events_found']       = 0;
		$covered['existing_coverage']  = array( 'covered' => true, 'type' => 'ticketmaster' );
		$intake->qualifications        = array( $covered );
		$result                        = $intake->executePreview( array( 'url' => 'https://www.ticketmaster.com/venue/1' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['existing_coverage']['covered'] );

		$uncovered                    = $this->qualification( 'venue', false, 'https://venue.test/empty' );
		$uncovered['events_found']     = 0;
		$intake->qualifications        = array( $uncovered );
		$error                         = $intake->executePreview( array( 'url' => 'https://venue.test/empty' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'no_events_found', $error->get_error_code() );
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

	public function test_tounds_lineup_overrides_branding_and_reuses_existing_term(): void {
		$term = new WP_Term( 1672, 'Tounds' );
		$GLOBALS['event_source_terms']['artist:tounds'] = $term;
		$GLOBALS['event_source_term_ids']['artist:1672'] = $term;
		$result = $this->classification(
			array(
				array( 'venue' => 'Room A', 'artists' => array( array( 'title' => 'Tounds' ), array( 'title' => 'Support' ) ) ),
				array( 'venue' => 'Room B', 'artists' => array( array( 'title' => 'Tounds' ) ) ),
			),
			'https://tounds.com/',
			'<title>TOUNDS, a band</title>'
		);
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( array( 'taxonomy' => 'artist', 'term_id' => 1672, 'name' => 'Tounds' ), $result['binding'] );
		$resolve = new ReflectionMethod( ArtistUrlImportAbilities::class, 'resolveArtistTerm' );
		$resolve->setAccessible( true );
		// No wp_insert_term double exists: attempting creation fails this test.
		$this->assertSame( 1672, $resolve->invoke( $this->intake, 0, $result['binding']['name'], 0 ) );

		$GLOBALS['ec_artist_test']['user_caps'][7]['access_events_admin'] = true;
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$qualification = $this->qualification( 'artist', true, 'https://tounds.com/' );
		$qualification['recommended_binding'] = $result['binding'];
		$qualification['suggested_artist_name'] = 'Tounds';
		$qualification['suggested_artist_term_id'] = 1672;
		$intake->qualifications = array( $qualification, $qualification );
		$create_flow = new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) );
		$abilities = $this->artist_flow_abilities( $create_flow );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;
		$submission = $intake->executeSubmit( array( 'url' => 'https://tounds.com/' ) );
		$this->assertTrue( $submission['auto_approved'] );
		$this->assertSame( '1672', $create_flow->calls[0]['step_configs']['upsert']['handler_config']['taxonomy_artist_selection'] );
	}

	public function test_artist_normalized_identity_is_reused_before_creation(): void {
		$term = new WP_Term( 1672, 'The Tounds!' );
		$GLOBALS['event_source_term_ids']['artist:1672'] = $term;
		$resolve = new ReflectionMethod( ArtistUrlImportAbilities::class, 'resolveArtistTerm' );
		$resolve->setAccessible( true );
		$this->assertSame( 1672, $resolve->invoke( $this->intake, 0, 'TOUNDS', 0 ) );
		$result = $this->classification(
			array(
				array( 'venue' => 'Room A', 'performer' => array( array( 'name' => 'Tounds' ) ) ),
				array( 'venue' => 'Room B', 'lineup' => array( 'TOUNDS' ) ),
			),
			'https://tounds.com/'
		);
		$this->assertSame( 1672, $result['binding']['term_id'] );
	}

	public function test_site_title_decorations_are_removed_without_performers(): void {
		foreach ( array( 'TOUNDS, a band', 'TOUNDS, band', 'TOUNDS | Official Site', 'TOUNDS - Home', 'TOUNDS — Official Website', 'TOUNDS, a band | Official Site' ) as $title ) {
			$result = $this->classification( $this->performerless_tour(), 'https://tounds.com/', '<title>' . $title . '</title>' );
			$this->assertSame( 'artist', $result['source_kind'], $title );
			$this->assertSame( 'TOUNDS', $result['binding']['name'], $title );
		}
	}

	public function test_conflicting_headliners_do_not_establish_artist_scope(): void {
		$result = $this->classification(
			array(
				array( 'venue' => 'Room A', 'artists' => array( array( 'title' => 'Tounds' ) ) ),
				array( 'venue' => 'Room B', 'artists' => array( array( 'title' => 'Other Artist' ) ) ),
			),
			'https://tounds.com/',
			'<title>TOUNDS, a band</title>'
		);
		$this->assertSame( 'unknown', $result['source_kind'] );
	}

	/** Tour widgets (e.g. Seated) list dates without per-event performers (#927). */
	private function performerless_tour(): array {
		return array(
			array( 'venue' => 'Room A' ),
			array( 'venue' => 'Room B' ),
			array( 'venue' => 'Room C' ),
		);
	}

	public function test_seated_titles_bind_existing_artist_instead_of_generic_page_title(): void {
		foreach ( array( 'Moon Taxi' => 'Official Site', 'The 502s' => 'Official Website' ) as $artist => $label ) {
			$GLOBALS['event_source_terms'][ 'artist:' . strtolower( $artist ) ] = new WP_Term( 80, $artist );
			$events = array();
			foreach ( range( 1, 8 ) as $number ) {
				$events[] = array( 'title' => $artist, 'venue' => 'Room ' . $number );
			}
			$result = $this->classification( $events, 'https://ridethemoontaxi.com/', '<title>' . $label . '</title>', 'seated' );
			$this->assertSame( 'artist', $result['source_kind'] );
			$this->assertSame( $artist, $result['binding']['name'] );
			$this->assertSame( 80, $result['binding']['term_id'] );
			$this->assertSame( 'high', $result['confidence'] );
		}
	}

	public function test_only_consistent_seated_titles_are_structured_artist_candidates(): void {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'suggestArtist' );
		$method->setAccessible( true );
		foreach ( array(
			array( 'seated', array_fill( 0, 3, array( 'title' => 'New Band' ) ), 'New Band', 'structured' ),
			array( 'json_ld', array_fill( 0, 3, array( 'title' => 'New Band' ) ), 'Fallback', 'page' ),
			array( 'seated', array( array( 'title' => 'New Band' ), array( 'title' => 'Other Show' ) ), 'Fallback', 'page' ),
			array( 'seated', array( array( 'title' => 'New Band' ), array() ), 'Fallback', 'page' ),
			array( 'seated', array(), 'Fallback', 'page' ),
		) as list( $format, $events, $name, $source ) ) {
			$result = $method->invoke( $this->intake, 'https://source.test', array( 'detected_format' => $format, 'raw_events' => $events, 'page_html' => '<title>Fallback</title>' ) );
			$this->assertSame( $name, $result['name'] );
			$this->assertSame( $source, $result['source'] );
		}
	}

	public function test_generic_site_labels_are_rejected_case_insensitively(): void {
		$generic = new ReflectionMethod( ArtistUrlImportAbilities::class, 'isGenericSourceLabel' );
		$strip   = new ReflectionMethod( ArtistUrlImportAbilities::class, 'stripSiteTokens' );
		$generic->setAccessible( true );
		$strip->setAccessible( true );
		foreach ( array( 'Official Site', 'Official Website', 'Home', 'Tour', 'Tour Dates', 'Shows', 'Events', 'Live', 'Music' ) as $label ) {
			$this->assertTrue( $generic->invoke( $this->intake, strtoupper( $label ) ), $label );
			$this->assertSame( '', $strip->invoke( $this->intake, $label . ' | Home' ), $label );
			$this->assertSame( 'The Band', $strip->invoke( $this->intake, 'The Band | ' . $label ), $label );
			$result = $this->classification( $this->performerless_tour(), 'https://the-band.test', '<title>' . $label . '</title>' );
			$this->assertSame( 72, $result['binding']['term_id'], $label );
		}
	}

	public function test_matched_candidate_wins_over_unmatched_structured_or_page_candidate(): void {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'suggestArtist' );
		$method->setAccessible( true );
		foreach ( array( array(), array( 'performer' => 'Unrelated Performer' ) ) as $first ) {
			$result = $method->invoke( $this->intake, 'https://source.test', array(
				'raw_first_event' => $first,
				'page_html' => '<meta property="og:title" content="Unrelated Label"><title>The Band | Official Website</title>',
			) );
			$this->assertSame( 'The Band', $result['name'] );
			$this->assertSame( 72, $result['term_id'] );
			$this->assertSame( 'page', $result['source'] );
		}
	}

	public function test_performerless_tour_page_with_matched_artist_term_is_artist(): void {
		$result = $this->classification(
			$this->performerless_tour(),
			'https://www.the-band.test/tour',
			'<html><head><title>Tour &mdash; The Band</title></head></html>'
		);
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 'high', $result['confidence'] );
		$this->assertSame( 72, $result['binding']['term_id'] );
		$this->assertSame( 'The Band', $result['binding']['name'] );
		$this->assertTrue( $result['scope_evidence']['bounded'] );
		$this->assertSame( 'site_artist_multiple_venues', $result['scope_evidence']['type'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_performerless_tour_page_with_unmatched_page_title_is_medium_artist(): void {
		$result = $this->classification(
			$this->performerless_tour(),
			'https://nightmoves.test/tour',
			'<html><head><title>Night Moves | Tour</title></head></html>'
		);
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 'medium', $result['confidence'] );
		$this->assertNull( $result['binding']['term_id'] );
		$this->assertSame( 'Night Moves', $result['binding']['name'] );
	}

	public function test_generic_tour_title_falls_through_to_matched_domain_name(): void {
		$result = $this->classification(
			$this->performerless_tour(),
			'https://www.the-band.test/tour',
			'<html><head><title>Tour Dates</title></head><body><h1>Upcoming Shows</h1></body></html>'
		);
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 72, $result['binding']['term_id'] );
		$this->assertNotSame( 'Tour Dates', $result['binding']['name'] );
	}

	public function test_generic_tour_title_with_unmatched_domain_stays_unknown(): void {
		$result = $this->classification(
			$this->performerless_tour(),
			'https://www.somesite.test/tour',
			'<html><head><title>Tour Dates</title></head></html>'
		);
		$this->assertSame( 'unknown', $result['source_kind'] );
		$this->assertFalse( $result['scope_evidence']['bounded'] );
	}

	public function test_performerless_single_event_page_stays_unknown(): void {
		$result = $this->classification(
			array( array( 'venue' => 'Room A' ) ),
			'https://www.the-band.test/tour',
			'<html><head><title>The Band</title></head></html>'
		);
		$this->assertSame( 'unknown', $result['source_kind'] );
	}

	public function test_performerless_page_on_aggregator_host_stays_unknown(): void {
		$result = $this->classification(
			$this->performerless_tour(),
			'https://www.eventbrite.com/o/the-band/events',
			'<html><head><title>The Band</title></head></html>'
		);
		$this->assertSame( 'unknown', $result['source_kind'] );
	}

	public function test_shared_title_binds_existing_artist_without_site_metadata(): void {
		$GLOBALS['event_source_terms']['artist:will overman'] = new WP_Term( 4424, 'Will Overman' );
		$events = array();
		foreach ( array( 'Kings', '118 North', 'The Music Hall', 'The Cardinal Music Hall', 'The Listening Room', 'Another Room' ) as $venue ) {
			$events[] = array( 'title' => 'Will Overman', 'venue' => $venue );
		}
		$result = $this->classification( $events, 'https://willovermanmusic.com' );
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( array( 'taxonomy' => 'artist', 'term_id' => 4424, 'name' => 'Will Overman' ), $result['binding'] );
		$this->assertSame( 'shared_title_multiple_venues', $result['scope_evidence']['type'] );
		$this->assertSame( $result['binding']['term_id'], $result['candidates'][0]['term_id'] );
		$this->assertSame( array(), $result['warnings'] );

		// One outlier is allowed, but a minority title cannot establish scope.
		$events[5]['title'] = 'Special Show';
		$this->assertSame( 'artist', $this->classification( $events, 'https://willovermanmusic.com' )['source_kind'] );
		$events[4]['title'] = 'Another Show';
		$this->assertSame( 'unknown', $this->classification( $events, 'https://willovermanmusic.com' )['source_kind'] );
	}

	public function test_shared_title_can_corroborate_site_candidate_without_term(): void {
		$result = $this->classification(
			array(
				array( 'title' => 'Night Moves', 'venue' => 'Room A' ),
				array( 'title' => 'Night Moves', 'venue' => 'Room B' ),
			),
			'https://night-moves.test'
		);
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 'Night Moves', $result['binding']['name'] );
		$this->assertNull( $result['binding']['term_id'] );
	}

	public function test_performerless_venue_calendar_does_not_classify_as_artist(): void {
		$result = $this->classification(
			array(
				array( 'title' => 'The Band', 'venue' => 'The Room' ),
				array( 'title' => 'Other Artist', 'venue' => 'The Room' ),
				array( 'title' => 'Third Artist', 'venue' => 'The Room' ),
			),
			'https://venue.test/calendar'
		);
		$this->assertSame( 'unknown', $result['source_kind'] );
	}

	public function test_unmatched_shared_title_does_not_establish_artist_identity(): void {
		$result = $this->classification(
			array(
				array( 'title' => 'Special Show', 'venue' => 'Room A' ),
				array( 'title' => 'Special Show', 'venue' => 'Room B' ),
			),
			'https://unrelated.test'
		);
		$this->assertSame( 'unknown', $result['source_kind'] );
	}

	/** Modeled on mosplaceradio.com/bandschedules (#1347). */
	private function mosplace_listing_events(): array {
		return array(
			array( 'title' => 'Carl Chloros', 'venue' => 'L Street Tavern' ),
			array( 'title' => 'Chad Lamarsh', 'venue' => 'The Windham Restaurant' ),
			array( 'title' => 'Open Mic', 'venue' => 'Far From The Tree' ),
			array( 'title' => 'Joe Buford', 'venue' => 'Tour Golf & Grill' ),
			array( 'title' => 'Karaoke', 'venue' => 'The Hong Kong' ),
		);
	}

	public function test_performerless_multi_artist_listing_is_recommended_as_listing(): void {
		// A station's band schedule: every title a different act, every date a
		// different venue. Not an artist or venue; routed as a listing (#1515).
		$result = $this->classification(
			$this->mosplace_listing_events(),
			'https://mosplaceradio.com/bandschedules',
			'<html><head><title>Live Music &amp; Band Schedule | Merrimack Valley, Boston &amp; NH</title><meta property="og:site_name" content="Mo\'s Place Radio"></head></html>',
			'jsonld'
		);
		$this->assertSame( 'listing', $result['source_kind'] );
		$this->assertSame( 'medium', $result['confidence'] );
		$this->assertTrue( $result['scope_evidence']['bounded'] );
		$this->assertSame( 'multi_artist_listing', $result['scope_evidence']['type'] );
		$this->assertSame( array( 'taxonomy' => '', 'term_id' => null, 'name' => "Mo's Place Radio" ), $result['binding'] );
		$this->assertStringContainsString( 'multi-artist listings', implode( ' ', $result['warnings'] ) );
	}

	public function test_listing_source_name_falls_back_to_host_without_site_branding(): void {
		$result = $this->classification(
			$this->mosplace_listing_events(),
			'https://www.mosplaceradio.com/bandschedules',
			'<html><head><title>Live Music &amp; Band Schedule</title></head></html>'
		);
		$this->assertSame( 'listing', $result['source_kind'] );
		$this->assertSame( 'Mosplaceradio', $result['binding']['name'] );
	}

	public function test_page_dominated_by_one_act_is_not_a_listing(): void {
		$events = array();
		foreach ( array( 'Room A', 'Room B', 'Room C', 'Room D' ) as $venue ) {
			$events[] = array( 'title' => 'Will Overman', 'venue' => $venue );
		}
		$events[] = array( 'title' => 'Special Show', 'venue' => 'Room E' );
		$events[] = array( 'title' => 'Another Show', 'venue' => 'Room F' );
		$this->assertNotSame( 'listing', $this->classification( $events, 'https://unrelated-site.test' )['source_kind'] );
	}

	public function test_performerless_tour_with_venue_or_artist_titles_stays_artist(): void {
		// Tour widgets title dates with the venue or the artist; a couple of
		// specially named shows must not flip the page to a listing.
		$result = $this->classification(
			array(
				array( 'title' => 'Room A', 'venue' => 'Room A' ),
				array( 'title' => 'Night Moves at Room B', 'venue' => 'Room B' ),
				array( 'title' => 'Album Release Party', 'venue' => 'Room C' ),
				array( 'title' => 'Holiday Spectacular', 'venue' => 'Room D' ),
				array( 'venue' => 'Room E' ),
			),
			'https://nightmoves.test/tour',
			'<html><head><title>Night Moves | Tour</title></head></html>'
		);
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 'Night Moves', $result['binding']['name'] );
		$this->assertSame( 'site_artist_multiple_venues', $result['scope_evidence']['type'] );
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

	public function test_staff_submission_is_approved_without_review(): void {
		$GLOBALS['ec_artist_test']['user_caps'][7]['access_events_admin'] = true;
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'artist' );
		$intake->qualifications[] = $this->qualification( 'artist' );
		$create_flow = new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) );
		$abilities   = $this->artist_flow_abilities( $create_flow );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeSubmit( array( 'url' => 'https://source.test/events' ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['auto_approved'] );
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_APPROVED, $result['status'] );
		$this->assertSame( 77, $result['flow_id'] );
		$this->assertCount( 1, $create_flow->calls );
		$this->assertSame( '72', $create_flow->calls[0]['step_configs']['upsert']['handler_config']['taxonomy_artist_selection'] );
	}

	public function test_administrator_submission_is_approved_without_review(): void {
		$GLOBALS['ec_artist_test']['user_caps'][7]['manage_options'] = true;
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'artist' );
		$intake->qualifications[] = $this->qualification( 'artist' );
		$create_flow = new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) );
		$abilities   = $this->artist_flow_abilities( $create_flow );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeSubmit( array( 'url' => 'https://source.test/events' ) );

		$this->assertTrue( $result['auto_approved'] );
		$this->assertCount( 1, $create_flow->calls );
	}

	public function test_non_staff_submission_waits_for_review(): void {
		$GLOBALS['event_source_current_user']->user_email = '';
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'artist' );
		$create_flow = new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) );
		$abilities   = $this->artist_flow_abilities( $create_flow );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeSubmit( array( 'url' => 'https://source.test/events' ) );

		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW, $result['status'] );
		$this->assertArrayNotHasKey( 'auto_approved', $result );
		$this->assertSame( array(), $create_flow->calls );
	}

	public function test_staff_submission_falls_back_to_review_when_approval_fails(): void {
		$GLOBALS['event_source_current_user']->user_email = '';
		$GLOBALS['ec_artist_test']['user_caps'][7]['access_events_admin'] = true;
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'artist' );
		$intake->qualifications[] = $this->qualification( 'artist', false );

		$result = $intake->executeSubmit( array( 'url' => 'https://source.test/events' ) );

		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW, $result['status'] );
		$this->assertSame( 'source_no_longer_admissible', $result['auto_approve_error'] );
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW, $this->wpdb->submissions[1]['status'] );
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

	public function test_artist_row_no_longer_recurring_eligible_is_rejected_without_writes(): void {
		$this->wpdb->submissions[1] = $this->pending_submission( 'artist' );
		$intake                     = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[]   = $this->qualification( 'artist', false );

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'artist_term_id' => 72 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'source_no_longer_admissible', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->wpdb->updates );
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

	public function test_city_pipeline_normalizes_mixed_location_representations(): void {
		$GLOBALS['event_source_term_ids']['location:7682'] = new WP_Term( 7682, 'Phoenix' );
		$this->wpdb->pipelines[41]                         = array( 'pipeline_id' => 41, 'pipeline_name' => 'Phoenix Events' );
		foreach ( array( 7682, '7682', 'Phoenix' ) as $value ) {
			$this->wpdb->flows[] = array(
				'pipeline_id' => 41,
				'flow_config' => wp_json_encode(
					array(
						'import' => array( 'step_type' => 'event_import' ),
						'upsert' => array(
							'step_type'       => 'upsert',
							'handler_configs' => array( 'upsert_event' => array( 'taxonomy_location_selection' => $value ) ),
						),
					)
				),
			);
		}

		$validator = ( new ReflectionClass( VenueAddAbilities::class ) )->newInstanceWithoutConstructor();
		$result    = $validator->validateCityPipeline( 41 );

		$this->assertSame( 7682, $result['location_term_id'] );
		$this->assertSame( 'Phoenix', $result['location']->name );
	}

	public function test_city_pipeline_rejects_conflicting_canonical_locations(): void {
		$GLOBALS['event_source_term_ids']['location:7682'] = new WP_Term( 7682, 'Phoenix' );
		$GLOBALS['event_source_term_ids']['location:7683'] = new WP_Term( 7683, 'Tucson' );
		$this->wpdb->pipelines[41]                         = array( 'pipeline_id' => 41, 'pipeline_name' => 'Arizona Events' );
		foreach ( array( 'Phoenix', 7683 ) as $value ) {
			$this->wpdb->flows[] = array(
				'pipeline_id' => 41,
				'flow_config' => wp_json_encode(
					array(
						'import' => array( 'step_type' => 'event_import' ),
						'upsert' => array(
							'step_type'       => 'upsert',
							'handler_configs' => array( 'upsert_event' => array( 'taxonomy_location_selection' => $value ) ),
						),
					)
				),
			);
		}

		$validator = ( new ReflectionClass( VenueAddAbilities::class ) )->newInstanceWithoutConstructor();
		$result    = $validator->validateCityPipeline( 41 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_city_pipeline', $result->get_error_code() );
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

	public function test_only_generic_ability_schemas_register(): void {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'registerGenericAbilities' );
		$method->setAccessible( true );
		$method->invoke( $this->intake );
		$registered = array_merge( $GLOBALS['event_source_registered_abilities'], $GLOBALS['ec_artist_test']['registered'] );
		$qualify    = $registered['extrachill/qualify-event-source'];
		$approve    = $registered['extrachill-events/approve-event-source-submission'];
		$this->assertArrayHasKey( 'recurring_eligible', $qualify['output_schema']['properties'] );
		$this->assertCount( 3, $approve['output_schema']['oneOf'] );
		$this->assertContains( 'listing', $approve['input_schema']['properties']['source_kind']['enum'] );
		$this->assertContains( 'listing', $qualify['output_schema']['properties']['source_kind']['enum'] );
		$this->assertContains( 'listing', $registered['extrachill-events/submit-event-source']['output_schema']['properties']['source_kind']['enum'] );
		$listing_branch = $approve['output_schema']['oneOf'][2];
		$this->assertSame( array( 'listing' ), $listing_branch['properties']['source_kind']['enum'] );
		$this->assertSame( array( 'success', 'pipeline_id', 'flow_id', 'source_kind' ), $listing_branch['required'] );
		foreach ( array( 'preview', 'submit', 'approve', 'reject' ) as $action ) {
			$this->assertArrayNotHasKey( "extrachill-events/{$action}-artist-url" . ( in_array( $action, array( 'approve', 'reject' ), true ) ? '-submission' : '' ), $registered );
		}

		foreach ( array_keys( $registered ) as $ability_name ) {
			$this->assertStringNotContainsString( 'artist-url', $ability_name );
		}
	}

	public function test_venue_qualifier_discovers_canonical_navigation_candidate(): void {
		$qualifier = ( new ReflectionClass( VenueQualificationAbilities::class ) )->newInstanceWithoutConstructor();
		$method    = new ReflectionMethod( VenueQualificationAbilities::class, 'buildCandidateUrls' );
		$method->setAccessible( true );
		$candidates = $method->invoke( $qualifier, 'https://venue.test', 'https://venue.test', '<nav><a href="/calendar">Upcoming shows</a></nav>' );
		$this->assertContains( 'https://venue.test/calendar', array_column( $candidates, 'url' ) );
	}

	private function scraper_flow( string $url ): void {
		$this->wpdb->flows = array(
			array(
				'flow_id' => 798,
				'flow_name' => 'Easy Honey — Tour URL',
				'flow_config' => wp_json_encode(
					array(
						array(
							'handler_config' => array( 'source_url' => $url ),
							'handler' => 'universal_web_scraper',
						),
					)
				),
			),
		);
	}

	public function test_artist_host_coverage_ignores_scheme_www_and_path_and_submit_rejects(): void {
		$this->scraper_flow( 'https://www.easyhoneymusic.com/tour/' );
		$runner = new VenueExpansionRunner();
		$url = 'http://easyhoneymusic.com';
		$this->assertNull( $runner->lookupExistingFlow( $url ) );
		$flow = $runner->lookupExistingFlow( $url, true );
		$this->assertSame( 798, $flow['flow_id'] );
		$this->assertSame( 'Easy Honey — Tour URL', $flow['flow_name'] );
		$this->assertNull( $runner->lookupExistingFlow( 'https://other.easyhoneymusic.com', true ) );

		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$fresh = $this->qualification( 'artist', false, $url );
		$fresh['existing_coverage'] = array_merge( array( 'covered' => true, 'type' => 'universal_scraper_flow' ), $flow );
		$intake->qualifications = array( $fresh );
		$result = $intake->executeSubmit( array( 'url' => $url ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'source_already_covered', $result->get_error_code() );
		$this->assertSame( array(), $this->wpdb->submissions );
	}

	public function test_shared_hosts_keep_exact_url_matching_even_for_artists(): void {
		foreach ( array( 'artist.bandcamp.com', 'linktr.ee', 'www.ticketmaster.com', 'eventbrite.com', 'dice.fm', 'axs.com' ) as $host ) {
			$url = 'https://' . $host . '/artist-one';
			$this->scraper_flow( $url );
			$runner = new VenueExpansionRunner();
			$this->assertNull( $runner->lookupExistingFlow( 'https://' . $host . '/artist-two', true ), $host );
			$this->assertSame( 798, $runner->lookupExistingFlow( $url, true )['flow_id'], $host );
		}
	}

	public function test_existing_coverage_uses_venue_expansion_flow_lookup(): void {
		$runner = new VenueExpansionRunner( null, null, static fn() => array( 'flow_id' => 99, 'flow_name' => 'Existing' ) );
		$this->assertSame( 99, $runner->lookupExistingFlow( 'https://venue.test/calendar' )['flow_id'] );
	}

	public function test_page_html_reuses_the_page_qualification_already_fetched(): void {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'fetchPageHtml' );
		$method->setAccessible( true );
		$page = array(
			'url'         => 'https://venue.test',
			'final_url'   => 'https://www.venue.test/',
			'http_status' => 200,
			'html'        => '<title>The Room</title>',
		);

		// No HTTP double is defined in this suite, so a second fetch would fatal.
		$this->assertSame( '<title>The Room</title>', $method->invoke( $this->intake, 'https://venue.test', $page ) );
		$this->assertSame( '<title>The Room</title>', $method->invoke( $this->intake, 'https://www.venue.test/', $page ) );

		$page['http_status'] = 404;
		$this->assertSame( '', $method->invoke( $this->intake, 'https://venue.test', $page ) );
	}

	/**
	 * Raw-mode test-event-scraper double: every submitted URL qualifies at
	 * the direct candidate with three complete events.
	 */
	private function raw_scraper_double(): EventSourceAbilityDouble {
		$events = array(
			array( 'title' => 'The Band at The Room', 'startDate' => '2030-01-01', 'startTime' => '20:00', 'venue' => 'The Room', 'ticketUrl' => 'https://tix.test/1', 'performer' => 'The Band' ),
			array( 'title' => 'The Band at The Hall', 'startDate' => '2030-01-02', 'startTime' => '20:00', 'venue' => 'The Hall', 'ticketUrl' => 'https://tix.test/2', 'performer' => 'The Band' ),
			array( 'title' => 'The Band at The Barn', 'startDate' => '2030-01-03', 'startTime' => '20:00', 'venue' => 'The Barn', 'ticketUrl' => 'https://tix.test/3', 'performer' => 'The Band' ),
		);
		return new EventSourceAbilityDouble(
			static function ( array $input ) use ( $events ) {
				if ( 'https://source.test/events' !== $input['target_url'] ) {
					return new WP_Error( 'scraper_failed', 'Scraper returned no results.' );
				}
				$packets = array();
				foreach ( $events as $event ) {
					$packets[] = array(
						'type'      => 'event_import',
						'timestamp' => 0,
						'data'      => array( 'body' => wp_json_encode( array( 'event' => $event ) ) ),
						'metadata'  => array( 'source_type' => 'universal_web_scraper', 'extraction_method' => 'jsonld' ),
					);
				}
				$result = array(
					'success'         => true,
					'status'          => 'ok',
					'target_url'      => $input['target_url'],
					'event_data'      => array( 'title' => $events[0]['title'], 'items' => $events, 'event_count' => 3 ),
					'extraction_info' => array(
						'source_type'               => 'universal_web_scraper',
						'extraction_method'         => 'jsonld',
						'payload_type'              => 'event',
						'event_count'               => 3,
						'extracted_packet_count'    => 3,
						'unique_source_event_count' => 3,
					),
				);
				if ( 'raw' === ( $input['output_mode'] ?? '' ) ) {
					$result += array(
						'output_mode'          => 'raw',
						'raw_events'           => $events,
						'packets'              => $packets,
						'packet_count'         => 3,
						'omitted_packet_count' => 0,
					);
				}
				return $result;
			},
			'data-machine-events/test-event-scraper'
		);
	}

	public function test_qualification_scrapes_each_submitted_url_once_and_probe_reuses_it(): void {
		$scraper                             = $this->raw_scraper_double();
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => 'data-machine-events/test-event-scraper' === $name ? $scraper : null;
		$GLOBALS['event_source_http']        = array(
			'https://source.test/events' => array( 'code' => 200, 'body' => '<html><head><title>The Band</title></head><body>Tour</body></html>' ),
		);
		$GLOBALS['event_source_http_calls']  = array();

		try {
			$result = $this->intake->executeQualifyEventSource( array( 'url' => 'https://source.test/events' ) );
		} finally {
			unset( $GLOBALS['ec_test_ability_resolver'], $GLOBALS['event_source_http'] );
		}

		$this->assertIsArray( $result );
		$this->assertCount( 1, $scraper->calls, 'The submitted URL must be scraped exactly once.' );
		$this->assertSame( 'raw', $scraper->calls[0]['output_mode'] );
		$this->assertSame( array( 'https://source.test/events' ), $GLOBALS['event_source_http_calls'], 'The page fetched by qualification is reused.' );

		$this->assertTrue( $result['qualified'] );
		$this->assertSame( 3, $result['events_found'] );
		$this->assertSame( 'jsonld', $result['detected_format'] );
		$this->assertSame( 'jsonld', $result['extraction_method'] );
		$this->assertSame( 'universal_web_scraper', $result['source_metadata']['source_type'] );
		$this->assertCount( 3, $result['events_preview'] );
		$this->assertSame(
			array( 'title' => 'The Band at The Room', 'startDate' => '2030-01-01', 'startTime' => '20:00', 'venue' => 'The Room', 'ticketUrl' => 'https://tix.test/1' ),
			$result['events_preview'][0]
		);
		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 'The Band', $result['recommended_binding']['name'] );
		$this->assertArrayNotHasKey( 'scrape', $result );
		$this->assertArrayNotHasKey( 'page', $result );
		$this->assertStringNotContainsString( '"_scrape"', (string) wp_json_encode( $result ) );
	}

	public function test_qualification_scrape_pass_through_stays_out_of_fingerprint_and_public_output(): void {
		$scraper                             = $this->raw_scraper_double();
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => 'data-machine-events/test-event-scraper' === $name ? $scraper : null;
		$GLOBALS['event_source_http']        = array(
			'https://source.test/events' => array( 'code' => 200, 'body' => '<html><body>Tour</body></html>' ),
		);
		$qualifier = ( new ReflectionClass( VenueQualificationAbilities::class ) )->newInstanceWithoutConstructor();
		$input     = array( 'url' => 'https://source.test/events', 'persist_verdict' => false );

		try {
			$internal = $qualifier->qualifyWithPage( $input );
			$public   = $qualifier->executeQualifyVenue( $input );
		} finally {
			unset( $GLOBALS['ec_test_ability_resolver'], $GLOBALS['event_source_http'] );
		}

		$this->assertSame( 'https://source.test/events', $internal['scrape']['url'] );
		$this->assertCount( 3, $internal['scrape']['raw_events'] );
		$this->assertSame( 'jsonld', $internal['scrape']['source_metadata']['extraction_method'] );

		// The fingerprint is the persisted verdict payload: it keeps its shape.
		foreach ( array( $internal, $public ) as $result ) {
			foreach ( $result['fingerprint']['extractor_attempts'] as $attempt ) {
				$this->assertArrayNotHasKey( '_scrape', $attempt );
				$this->assertArrayNotHasKey( '_diagnostic_identifiers', $attempt );
			}
			$this->assertSame( 3, $result['event_count'] );
		}
		$this->assertArrayNotHasKey( 'scrape', $public );
		$this->assertArrayNotHasKey( 'page', $public );
		$this->assertCount( 2, $scraper->calls );
	}

	public function test_probe_is_empty_when_qualification_did_not_scrape_the_events_url(): void {
		$scraper                             = $this->raw_scraper_double();
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => 'data-machine-events/test-event-scraper' === $name ? $scraper : null;
		$method                              = new ReflectionMethod( ArtistUrlImportAbilities::class, 'probeUrl' );
		$method->setAccessible( true );
		$scrape = array(
			'url'             => 'https://source.test/other',
			'raw_events'      => array( array( 'title' => 'Elsewhere' ) ),
			'source_metadata' => array( 'extraction_method' => 'jsonld' ),
		);

		try {
			$mismatched = $method->invoke( $this->intake, 'https://source.test/events', array(), $scrape );
			$missing    = $method->invoke( $this->intake, 'https://source.test/events', array(), array() );
		} finally {
			unset( $GLOBALS['ec_test_ability_resolver'] );
		}

		foreach ( array( $mismatched, $missing ) as $probe ) {
			$this->assertSame( 0, $probe['events_found'] );
			$this->assertSame( '', $probe['detected_format'] );
			$this->assertSame( array(), $probe['raw_events'] );
		}
		$this->assertCount( 0, $scraper->calls, 'The probe never scrapes on its own.' );
	}

	public function test_probe_reports_unavailable_scraper(): void {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'probeUrl' );
		$method->setAccessible( true );

		$probe = $method->invoke( $this->intake, 'https://source.test/events', array(), array() );

		$this->assertInstanceOf( WP_Error::class, $probe );
		$this->assertSame( 'scraper_unavailable', $probe->get_error_code() );
	}

	// ── Listings sources (#1515) ───────────────────────────────────────────

	private const LISTING_URL = 'https://mosplaceradio.com/bandschedules';

	private function listing_qualification( bool $eligible = true, array $coverage = array( 'covered' => false, 'type' => 'none' ), string $url = self::LISTING_URL ): array {
		return array(
			'success'              => true,
			'qualified'            => true,
			'canonical_events_url' => $url,
			'source_identity_url'  => ArtistUrlSubmissionsTable::normalize_url( $url ),
			'verdict'              => 'qualified_structured',
			'events_found'         => 258,
			'events_preview'       => array(),
			'extraction_method'    => 'jsonld',
			'source_kind'          => 'listing',
			'recommended_binding'  => array( 'taxonomy' => '', 'term_id' => null, 'name' => "Mo's Place Radio" ),
			'existing_coverage'    => $coverage,
			'warnings'             => array(),
			'recurring_eligible'   => $eligible,
		);
	}

	private function pending_listing_submission( int $id, string $url = self::LISTING_URL, string $stored_kind = 'unknown' ): array {
		return array(
			'id'                 => $id,
			'user_id'            => 1543,
			'url'                => $url,
			'url_hash'           => ArtistUrlSubmissionsTable::url_hash( $url ),
			'canonical_url'      => $url,
			'source_kind'        => $stored_kind,
			'entity_taxonomy'    => '',
			'entity_term_id'     => null,
			'entity_name'        => '',
			'status'             => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			'events_found_count' => 258,
		);
	}

	/**
	 * Stateful Data Machine doubles: an exact-name pipeline lookup, pipeline
	 * creation, flow creation (flows become visible to later lookups), and
	 * revisioned step updates.
	 */
	private function listing_abilities( array &$state ): array {
		$state += array( 'pipeline_id' => null, 'flows' => array(), 'next_flow_id' => 1001 );
		$revision = 'sha256:' . str_repeat( 'c', 64 );
		return array(
			'datamachine/get-pipeline-configuration' => new EventSourceAbilityDouble(
				static function ( array $input ) use ( &$state, $revision ) {
					if ( isset( $input['pipeline_name'] ) && ( 'Regional Listings Import' !== $input['pipeline_name'] || null === $state['pipeline_id'] ) ) {
						return array( 'success' => false, 'error_code' => 'pipeline_not_found', 'error' => 'Pipeline not found', 'status' => 404 );
					}
					return array(
						'success'  => true,
						'pipeline' => array( 'pipeline_id' => $state['pipeline_id'], 'pipeline_name' => 'Regional Listings Import', 'revision' => $revision ),
						'flows'    => array_values( $state['flows'] ),
					);
				}
			),
			'datamachine/create-pipeline' => new EventSourceAbilityDouble(
				static function () use ( &$state ) {
					$state['pipeline_id'] = 230;
					return array( 'success' => true, 'pipeline_id' => 230 );
				}
			),
			'datamachine/create-flow' => new EventSourceAbilityDouble(
				static function ( array $input ) use ( &$state, $revision ) {
					$flow_id                    = $state['next_flow_id']++;
					$state['flows'][ $flow_id ] = array(
						'flow_id'  => $flow_id,
						'revision' => $revision,
						'steps'    => array(
							array(
								'step_type'       => 'event_import',
								'handler_configs' => array( 'universal_web_scraper' => $input['step_configs']['event_import']['handler_config'] ),
							),
						),
					);
					return array( 'success' => true, 'flow_id' => $flow_id );
				}
			),
			'datamachine/update-step-configuration' => new EventSourceAbilityDouble( array( 'success' => true, 'revision' => 'sha256:' . str_repeat( 'd', 64 ) ) ),
			'datamachine/run-flow' => new EventSourceAbilityDouble( array( 'success' => true ) ),
		);
	}

	private function existing_listing_flow( int $flow_id, string $source_url ): array {
		return array(
			'flow_id'  => $flow_id,
			'revision' => 'sha256:' . str_repeat( 'e', 64 ),
			'steps'    => array(
				array( 'step_type' => 'event_import', 'handler_configs' => array( 'universal_web_scraper' => array( 'source_url' => $source_url, 'search' => '', 'exclude_keywords' => '' ) ) ),
				array( 'step_type' => 'ai', 'prompt_queue' => array() ),
				array( 'step_type' => 'upsert', 'handler_configs' => array( 'upsert_event' => array( 'post_author' => 32 ) ) ),
			),
		);
	}

	public function test_listing_approval_creates_shared_pipeline_and_configured_flow(): void {
		$GLOBALS['artist_import_notification_calls'] = array();
		$this->wpdb->submissions[1] = $this->pending_listing_submission( 1 );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->listing_qualification();
		$state     = array();
		$abilities = $this->listing_abilities( $state );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'source_kind' => 'listing' ) );

		$this->assertSame(
			array( 'success' => true, 'pipeline_id' => 230, 'flow_id' => 1001, 'source_kind' => 'listing', 'linked_existing_flow' => false ),
			$result
		);

		// Pipeline: created once by its stable name with the three-step scaffold.
		$create_pipeline = $abilities['datamachine/create-pipeline']->calls;
		$this->assertCount( 1, $create_pipeline );
		$this->assertSame( 'Regional Listings Import', $create_pipeline[0]['pipeline_name'] );
		$this->assertSame( array( 'event_import', 'ai', 'upsert' ), array_column( $create_pipeline[0]['steps'], 'step_type' ) );

		// Pipeline AI system prompt: region-neutral and set once on creation.
		$updates         = $abilities['datamachine/update-step-configuration']->calls;
		$pipeline_update = array_values( array_filter( $updates, static fn( $u ) => 'pipeline' === $u['target'] ) );
		$this->assertCount( 1, $pipeline_update );
		$this->assertSame( 230, $pipeline_update[0]['pipeline_id'] );
		$this->assertSame( 'ai', $pipeline_update[0]['step_type'] );
		$this->assertSame(
			"You process events from regional listings sources for the Extra Chill Events feed. A listings source is a curated gig list (a radio station's band schedule, a regional calendar) covering many venues across many towns, so each event can be at any venue in the region the source covers. No venue or city is pre-selected: identify the venue, city/location, performing artist(s), and festival (if any) for each event from the event data. Keep venue and city spelling exactly as extracted. Skip WordPress categories and post tags entirely.\n\nTitles: write the title as a reader would want to see it on a calendar, led by the performing act(s). Fold in support acts when the source gives them.",
			$pipeline_update[0]['configuration']['system_prompt']
		);

		// Flow: scraper → AI → upsert with nothing pinned, daily by default.
		$create_flow = $abilities['datamachine/create-flow']->calls;
		$this->assertCount( 1, $create_flow );
		$flow = $create_flow[0];
		$this->assertSame( 230, $flow['pipeline_id'] );
		$this->assertSame( "Mo's Place Radio — Listings", $flow['flow_name'] );
		$this->assertSame( array( 'interval' => 'daily' ), $flow['scheduling_config'] );
		$this->assertSame( 'universal_web_scraper', $flow['step_configs']['event_import']['handler_slug'] );
		$this->assertSame( array( 'source_url' => self::LISTING_URL, 'search' => '', 'exclude_keywords' => '' ), $flow['step_configs']['event_import']['handler_config'] );
		$this->assertSame( 'upsert_event', $flow['step_configs']['upsert']['handler_slug'] );
		$this->assertSame(
			array(
				'post_status'                 => 'publish',
				'include_images'              => false,
				'post_author'                 => 32,
				'taxonomy_location_selection' => 'ai_decides',
				'taxonomy_artist_selection'   => 'ai_decides',
				'taxonomy_festival_selection' => 'ai_decides',
				'taxonomy_genre_selection'    => 'skip',
				'taxonomy_promoter_selection' => 'skip',
			),
			$flow['step_configs']['upsert']['handler_config']
		);
		$message = $flow['step_configs']['ai']['user_message'];
		$this->assertStringContainsString( "Mo's Place Radio", $message );
		foreach ( array( 'venue', 'city', 'artist', 'festival' ) as $part ) {
			$this->assertStringContainsString( $part, $message );
		}

		// Flow settings go through the revisioned owner contract too.
		$flow_updates = array_values( array_filter( $updates, static fn( $u ) => 'flow' === $u['target'] ) );
		$this->assertSame( array( 'event_import', 'upsert', 'ai' ), array_column( $flow_updates, 'step_type' ) );
		$this->assertSame( 1001, $flow_updates[0]['flow_id'] );

		// Submission row: approved and linked.
		$row = $this->wpdb->submissions[1];
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_APPROVED, $row['status'] );
		$this->assertSame( 230, $row['pipeline_id'] );
		$this->assertSame( 1001, $row['flow_id'] );
		$this->assertSame( 'listing', $row['source_kind'] );
		$this->assertSame( "Mo's Place Radio", $row['entity_name'] );
		$this->assertSame( '', $row['entity_taxonomy'] );
		$this->assertNull( $row['entity_term_id'] );

		// First scrape and submitter notification, like the other kinds.
		$this->assertSame( array( array( 'flow_id' => 1001 ) ), $abilities['datamachine/run-flow']->calls );
		$this->assertCount( 1, $GLOBALS['artist_import_notification_calls'] );
		$notice = $GLOBALS['artist_import_notification_calls'][0];
		$this->assertSame( array( 1543 ), $notice['user_ids'] );
		$this->assertSame( 'event_source_approved', $notice['data']['type'] );
		$this->assertSame( 'submission:1:event_source_approved', $notice['data']['idempotency_key'] );
		$this->assertStringContainsString( "Mo's Place Radio", $notice['data']['title'] );
	}

	public function test_second_listing_reuses_the_shared_pipeline(): void {
		$other_url = 'https://regional-calendar.test/gigs';
		$this->wpdb->submissions[1] = $this->pending_listing_submission( 1 );
		$this->wpdb->submissions[2] = $this->pending_listing_submission( 2, $other_url, 'listing' );
		$second = $this->listing_qualification( true, array( 'covered' => false, 'type' => 'none' ), $other_url );
		$second['recommended_binding']['name'] = 'Regional Calendar';
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications = array( $this->listing_qualification(), $second );
		$state     = array();
		$abilities = $this->listing_abilities( $state );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$first  = $intake->executeApprove( array( 'submission_id' => 1, 'source_kind' => 'listing' ) );
		$result = $intake->executeApprove( array( 'submission_id' => 2, 'schedule_interval' => 'weekly' ) );

		$this->assertSame( 230, $first['pipeline_id'] );
		$this->assertSame( 230, $result['pipeline_id'] );
		$this->assertSame( 1002, $result['flow_id'] );
		$this->assertFalse( $result['linked_existing_flow'] );
		$this->assertCount( 1, $abilities['datamachine/create-pipeline']->calls );
		$this->assertCount( 2, $abilities['datamachine/create-flow']->calls );
		$flow = $abilities['datamachine/create-flow']->calls[1];
		$this->assertSame( 230, $flow['pipeline_id'] );
		$this->assertSame( 'Regional Calendar — Listings', $flow['flow_name'] );
		$this->assertSame( array( 'interval' => 'weekly' ), $flow['scheduling_config'] );
		$this->assertSame( $other_url, $flow['step_configs']['event_import']['handler_config']['source_url'] );
		$this->assertCount( 1, array_filter( $abilities['datamachine/update-step-configuration']->calls, static fn( $u ) => 'pipeline' === $u['target'] ) );
		$this->assertSame( 1002, $this->wpdb->submissions[2]['flow_id'] );
	}

	public function test_listing_with_existing_flow_links_it_without_creating_another(): void {
		// Submission #21 against hand-built flow 983 on pipeline 230.
		$GLOBALS['artist_import_notification_calls'] = array();
		$this->wpdb->submissions[21] = $this->pending_listing_submission( 21 );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->listing_qualification(
			false,
			array( 'covered' => true, 'type' => 'universal_scraper_flow', 'flow_id' => 983, 'flow_name' => "Mo's Place Radio — Band Schedules" )
		);
		$state     = array(
			'pipeline_id' => 230,
			'flows'       => array( 983 => $this->existing_listing_flow( 983, 'https://mosplaceradio.com/bandschedules/' ) ),
		);
		$abilities = $this->listing_abilities( $state );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 21, 'source_kind' => 'listing' ) );

		$this->assertSame(
			array( 'success' => true, 'pipeline_id' => 230, 'flow_id' => 983, 'source_kind' => 'listing', 'linked_existing_flow' => true ),
			$result
		);
		$this->assertSame( array(), $abilities['datamachine/create-pipeline']->calls );
		$this->assertSame( array(), $abilities['datamachine/create-flow']->calls );
		$this->assertSame( array(), $abilities['datamachine/update-step-configuration']->calls, 'The hand-built flow keeps its own configuration.' );
		$this->assertSame( array(), $abilities['datamachine/run-flow']->calls, 'The linked flow is already scheduled.' );
		$row = $this->wpdb->submissions[21];
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_APPROVED, $row['status'] );
		$this->assertSame( 230, $row['pipeline_id'] );
		$this->assertSame( 983, $row['flow_id'] );
		$this->assertSame( 'listing', $row['source_kind'] );
		$this->assertCount( 1, $GLOBALS['artist_import_notification_calls'] );
	}

	public function test_listing_covered_by_a_flow_outside_the_listings_pipeline_is_rejected(): void {
		$this->wpdb->submissions[1] = $this->pending_listing_submission( 1 );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->listing_qualification( false, array( 'covered' => true, 'type' => 'universal_scraper_flow', 'flow_id' => 55 ) );
		$state     = array( 'pipeline_id' => 230, 'flows' => array( 984 => $this->existing_listing_flow( 984, 'https://elsewhere.test/gigs' ) ) );
		$abilities = $this->listing_abilities( $state );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'source_kind' => 'listing' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'source_already_covered', $result->get_error_code() );
		$this->assertSame( array(), $abilities['datamachine/create-flow']->calls );
		$this->assertSame( array(), $this->wpdb->updates );
	}

	public function test_coverage_never_relaxes_admission_for_artist_or_platform_coverage(): void {
		$this->wpdb->submissions[1] = $this->pending_listing_submission( 1 );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->listing_qualification( false, array( 'covered' => true, 'type' => 'platform_pipeline' ) );

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'source_kind' => 'listing' ) );

		$this->assertSame( 'source_no_longer_admissible', $result->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updates );
	}

	public function test_listing_approval_requires_fresh_listing_classification(): void {
		$this->wpdb->submissions[1] = $this->pending_submission( 'artist' );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'artist' );

		$result = $intake->executeApprove( array( 'submission_id' => 1, 'source_kind' => 'listing' ) );

		$this->assertSame( 'source_kind_changed', $result->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updates );
	}

	public function test_unknown_source_kind_still_requires_explicit_choice(): void {
		$this->wpdb->submissions[1] = $this->pending_listing_submission( 1 );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->listing_qualification();

		$result = $intake->executeApprove( array( 'submission_id' => 1 ) );

		$this->assertSame( 'explicit_source_kind_required', $result->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updates );
	}

	public function test_staff_listing_submission_is_approved_without_review(): void {
		$GLOBALS['ec_artist_test']['user_caps'][7]['access_events_admin'] = true;
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications = array( $this->listing_qualification(), $this->listing_qualification() );
		$state     = array();
		$abilities = $this->listing_abilities( $state );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeSubmit( array( 'url' => self::LISTING_URL ) );

		$this->assertTrue( $result['auto_approved'] );
		$this->assertSame( 'listing', $result['source_kind'] );
		$this->assertSame( 230, $result['pipeline_id'] );
		$this->assertSame( 'listing', $this->wpdb->submissions[1]['source_kind'] );
		$this->assertSame( "Mo's Place Radio — Listings", $abilities['datamachine/create-flow']->calls[0]['flow_name'] );
	}

	public function test_artist_web_flow_keeps_message_without_updating_shared_pipeline(): void {
		$this->wpdb->submissions[1] = $this->pending_submission( 'artist' );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'artist' );
		$abilities = $this->artist_flow_abilities( new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) ) );
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 1 ) );

		$this->assertSame( 'artist', $result['source_kind'] );
		$flow = $abilities['datamachine/create-flow']->calls[0];
		$message = $flow['step_configs']['ai']['user_message'];
		$this->assertStringContainsString( 'The artist is already known and pre-selected.', $message );
		$this->assertStringContainsString( 'tour page.', $message );
		$updates = $abilities['datamachine/update-step-configuration']->calls;
		$this->assertSame( array( 'flow', 'flow', 'flow' ), array_column( $updates, 'target' ) );
		$this->assertSame( $message, $updates[2]['configuration']['user_message'] );
	}

	public function test_artist_shared_pipeline_creation_keeps_artist_prompt(): void {
		$this->wpdb->submissions[1] = $this->pending_submission( 'artist' );
		$intake = ( new ReflectionClass( TestableEventSourceIntake::class ) )->newInstanceWithoutConstructor();
		$intake->qualifications[] = $this->qualification( 'artist' );
		$created   = false;
		$revision  = 'sha256:' . str_repeat( 'a', 64 );
		$selectors = array();
		$abilities = $this->artist_flow_abilities( new EventSourceAbilityDouble( array( 'success' => true, 'flow_id' => 77 ) ) );
		$abilities['datamachine/get-pipeline-configuration'] = new EventSourceAbilityDouble(
			static function ( array $input ) use ( &$created, &$selectors, $revision ) {
				$selectors[] = $input;
				if ( isset( $input['pipeline_name'] ) && ! $created ) {
					return array( 'success' => false, 'error_code' => 'pipeline_not_found', 'status' => 404 );
				}
				return array( 'success' => true, 'pipeline' => array( 'pipeline_id' => 42, 'revision' => $revision ), 'flows' => array( array( 'flow_id' => 77, 'revision' => $revision ) ) );
			}
		);
		$abilities['datamachine/create-pipeline'] = new EventSourceAbilityDouble(
			static function () use ( &$created ) {
				$created = true;
				return array( 'success' => true, 'pipeline_id' => 42 );
			}
		);
		$GLOBALS['ec_test_ability_resolver'] = static fn( $name ) => $abilities[ $name ] ?? null;

		$result = $intake->executeApprove( array( 'submission_id' => 1 ) );

		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 42, $result['pipeline_id'] );
		$this->assertSame( array( 'pipeline_name' => 'Artist Tour Import' ), $selectors[0] );
		$this->assertSame( 'Artist Tour Import', $abilities['datamachine/create-pipeline']->calls[0]['pipeline_name'] );
		$pipeline_update = array_values( array_filter( $abilities['datamachine/update-step-configuration']->calls, static fn( $u ) => 'pipeline' === $u['target'] ) );
		$this->assertStringContainsString( 'tour/events pages for the Events Test events feed', $pipeline_update[0]['configuration']['system_prompt'] );
		$this->assertSame( 'artist', $this->wpdb->submissions[1]['source_kind'] );
		$this->assertSame( 72, $this->wpdb->submissions[1]['artist_term_id'] );
	}
}
