<?php
/**
 * Behavioral tests for extrachill/add-venue: events-team gate and city resolution by location.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO,Generic.CodeAnalysis.UnusedFunctionParameter -- Isolated pure-unit fixtures declare WordPress doubles.

use ExtraChillEvents\Abilities\VenueAddAbilities;
use ExtraChillEvents\Core\TeamAuthorization;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Term' ) ) {
	/** Minimal term double. */
	class WP_Term {
		/** @var int */
		public $term_id;
		/** @var string */
		public $name;
		/** @var string */
		public $slug;
		/** @var string */
		public $taxonomy;

		public function __construct( int $term_id, string $name, string $slug, string $taxonomy ) {
			$this->term_id  = $term_id;
			$this->name     = $name;
			$this->slug     = $slug;
			$this->taxonomy = $taxonomy;
		}
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Resolve a capability against the current test role.
	 *
	 * @param string $capability Capability name.
	 */
	function current_user_can( $capability ) {
		$roles = array(
			'administrator'    => array( 'read', 'edit_posts', 'edit_others_posts', 'manage_options' ),
			'extra_chill_team' => array( 'read', 'edit_posts', TeamAuthorization::ACCESS_CAPABILITY ),
			'contributor'      => array( 'read', 'edit_posts' ),
			'subscriber'       => array( 'read' ),
		);
		$role  = $GLOBALS['venue_add_test']['role'] ?? '';

		return in_array( $capability, $roles[ $role ] ?? array(), true );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $value ) );
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $id, $key, $single = false ) {
		return $GLOBALS['venue_add_test']['meta'][ $id ][ $key ] ?? '';
	}
	function update_term_meta( $id, $key, $value ) {
		$GLOBALS['venue_add_test']['meta'][ $id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['venue_add_test_registered'][ $name ] = $args;
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		return $GLOBALS['venue_add_test']['terms'][ $taxonomy ][ (int) $term_id ] ?? null;
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		foreach ( $GLOBALS['venue_add_test']['terms'][ $taxonomy ] ?? array() as $term ) {
			if ( ( 'slug' === $field && $term->slug === $value ) || ( 'name' === $field && $term->name === $value ) ) {
				return $term;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args ) {
		$matches = array();
		foreach ( $GLOBALS['venue_add_test']['terms'][ $args['taxonomy'] ] ?? array() as $term ) {
			if ( ! isset( $args['name'] ) || 0 === strcasecmp( $term->name, (string) $args['name'] ) ) {
				$matches[] = $term;
			}
		}
		$number = (int) ( $args['number'] ?? 0 );
		return $number > 0 ? array_slice( $matches, 0, $number ) : $matches;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/Core/TeamAuthorization.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/FlowLocationGuard.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/VenueAddAbilities.php';
require_once __DIR__ . '/VenueAddVenueTaxonomyDouble.php';
require_once __DIR__ . '/VenueAddPermissionHelperDouble.php';

/** In-memory Data Machine pipelines/flows tables. */
final class VenueAddTestWpdb {
	/** @var string */
	public $prefix = 'wp_';
	/** @var array<int,array> */
	public $pipelines = array();
	/** @var array<int,array> flow_id => row */
	public $flows = array();
	/** @var array */
	public $updates = array();
	/** @var array */
	private $prepared = array();

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		$token                    = 'prepared:' . count( $this->prepared );
		$this->prepared[ $token ] = array( $query, $args );
		return $token;
	}

	public function get_row( $token, $output = null ) {
		list( $query, $args ) = $this->prepared[ $token ];
		if ( false !== strpos( $query, 'datamachine_pipelines' ) ) {
			return $this->pipelines[ (int) $args[0] ] ?? null;
		}
		if ( false !== strpos( $query, 'WHERE flow_id' ) ) {
			return $this->flows[ (int) $args[0] ] ?? null;
		}
		return null;
	}

	public function get_results( $token, $output = null ) {
		list( $query, $args ) = $this->prepared[ $token ];
		if ( false === strpos( $query, 'datamachine_flows' ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$this->flows,
				static function ( $row ) use ( $args ) {
					return (int) $row['pipeline_id'] === (int) $args[0];
				}
			)
		);
	}

	public function get_col( $token ) {
		list( $query, $args ) = $this->prepared[ $token ];
		if ( false === strpos( $query, 'datamachine_flows' ) || false === strpos( $query, 'LIKE' ) ) {
			return array();
		}
		$needles = array_map(
			static function ( $pattern ) {
				return str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), substr( (string) $pattern, 1, -1 ) );
			},
			$args
		);
		$ids     = array();
		foreach ( $this->flows as $row ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( (string) $row['flow_config'], $needle ) ) {
					$ids[ (int) $row['pipeline_id'] ] = (string) $row['pipeline_id'];
				}
			}
		}
		ksort( $ids );
		return array_values( $ids );
	}

	public function get_var( $token ) {
		return null;
	}

	public function update( $table, $data, $where ) {
		$this->updates[] = array( $table, $data, $where );
		if ( isset( $where['flow_id'], $this->flows[ $where['flow_id'] ] ) ) {
			$this->flows[ $where['flow_id'] ] = array_merge( $this->flows[ $where['flow_id'] ], $data );
		}
		return 1;
	}
}

/**
 * In-memory Data Machine flow abilities (create-flow, get-flows,
 * update-flow-step) backed by the wpdb double's flow rows.
 *
 * Like production, each ability denies a caller without Data Machine
 * manage rights unless the call runs inside PermissionHelper::run_as_system(),
 * and update-flow-step rejects handler_config keys the handler does not
 * declare.
 */
final class VenueAddTestDataMachine {
	/** Fields upsert_event declares (venue/category/post_tag are excluded upstream). */
	private const UPSERT_EVENT_FIELDS = array( 'post_status', 'include_images', 'post_author', 'taxonomy_location_selection', 'taxonomy_festival_selection', 'taxonomy_artist_selection', 'taxonomy_promoter_selection', 'taxonomy_genre_selection', 'taxonomy_venue_selection' );

	/** @var array<string,array> */
	public $calls = array();

	public function resolver(): callable {
		return function ( $name ) {
			if ( 'datamachine/resolve-source-url' === $name && ! empty( $GLOBALS['venue_add_test']['instagram_resolver'] ) ) {
				return new class {
					public function execute( $input ) {
						return array( 'handler_slug' => 'instagram_account', 'handler_config' => array( 'username' => 'hedhi' ) );
					}
				};
			}
			if ( ! in_array( $name, array( 'datamachine/create-flow', 'datamachine/get-flows', 'datamachine/update-flow-step' ), true ) ) {
				return null;
			}
			$fake = $this;
			return new class( $fake, $name ) {
				private $fake;
				private $name;

				public function __construct( $fake, $name ) {
					$this->fake = $fake;
					$this->name = $name;
				}

				public function execute( $input ) {
					return $this->fake->execute( $this->name, $input );
				}
			};
		};
	}

	public function execute( string $name, array $input ) {
		$this->calls[ $name ][] = $input;

		$is_admin = 'administrator' === ( $GLOBALS['venue_add_test']['role'] ?? '' );
		if ( ! $is_admin && empty( $GLOBALS['venue_add_test']['as_system'] ) ) {
			return new WP_Error( 'ability_invalid_permissions', sprintf( 'Ability "%s" does not have necessary permission.', $name ) );
		}

		$wpdb = $GLOBALS['wpdb'];
		switch ( $name ) {
			case 'datamachine/create-flow':
				$flow_id                 = 900 + count( $this->calls[ $name ] );
				$wpdb->flows[ $flow_id ] = array(
					'flow_id'     => $flow_id,
					'pipeline_id' => (int) $input['pipeline_id'],
					'flow_config' => wp_json_encode(
						array(
							'import_' . $flow_id => array( 'step_type' => 'event_import' ),
							'ai_' . $flow_id     => array( 'step_type' => 'ai' ),
							'upsert_' . $flow_id => array( 'step_type' => 'upsert' ),
						)
					),
				);
				return array(
					'success' => true,
					'flow_id' => $flow_id,
				);

			case 'datamachine/get-flows':
				$row = $wpdb->flows[ (int) $input['flow_id'] ] ?? null;
				if ( ! $row ) {
					return array(
						'success' => true,
						'flows'   => array(),
					);
				}
				return array(
					'success' => true,
					'flows'   => array(
						array(
							'flow_id'     => $row['flow_id'],
							'flow_name'   => '',
							'flow_config' => json_decode( $row['flow_config'], true ),
						),
					),
				);

			case 'datamachine/update-flow-step':
				$flow_id = (int) substr( (string) $input['flow_step_id'], strrpos( (string) $input['flow_step_id'], '_' ) + 1 );
				$config  = json_decode( $wpdb->flows[ $flow_id ]['flow_config'] ?? 'null', true );
				if ( ! is_array( $config ) || ! isset( $config[ $input['flow_step_id'] ] ) ) {
					return new WP_Error( 'flow_step_not_found', 'Flow step not found.' );
				}
				$step = &$config[ $input['flow_step_id'] ];
				if ( isset( $input['handler_slug'] ) ) {
					$slug           = $input['handler_slug'];
					$handler_config = $input['handler_config'] ?? array();
					if ( 'upsert_event' === $slug && array_diff( array_keys( $handler_config ), self::UPSERT_EVENT_FIELDS ) ) {
						return new WP_Error( 'invalid_handler_config', 'Unknown handler_config fields for upsert_event.' );
					}
					$step['handler_slugs']            = array( $slug );
					$step['handler_configs'][ $slug ] = array_merge( $step['handler_configs'][ $slug ] ?? array(), $handler_config );
					$step['enabled']                  = true;
				}
				if ( array_key_exists( 'user_message', $input ) ) {
					$step['user_message'] = $input['user_message'];
				}
				unset( $step );
				$wpdb->flows[ $flow_id ]['flow_config'] = wp_json_encode( $config );
				return array(
					'success'      => true,
					'flow_step_id' => $input['flow_step_id'],
				);
		}

		return null;
	}

	/**
	 * Stored flow config keyed by step type.
	 *
	 * @param int $flow_id Flow ID.
	 */
	public static function steps( int $flow_id ): array {
		$steps = array();
		foreach ( (array) json_decode( $GLOBALS['wpdb']->flows[ $flow_id ]['flow_config'], true ) as $step ) {
			$steps[ $step['step_type'] ] = $step;
		}
		return $steps;
	}
}

final class VenueAddAbilitiesTest extends TestCase {
	/** @var mixed */
	private $previous_wpdb;
	/** @var VenueAddTestWpdb */
	private $wpdb;
	/** @var VenueAddAbilities */
	private $abilities;

	protected function setUp(): void {
		$this->previous_wpdb       = $GLOBALS['wpdb'] ?? null;
		$this->wpdb                = new VenueAddTestWpdb();
		$GLOBALS['wpdb']           = $this->wpdb;
		$GLOBALS['venue_add_test'] = array(
			'role'  => '',
			'terms' => array(
				'location' => array(
					10 => new WP_Term( 10, 'Charleston', 'charleston', 'location' ),
					20 => new WP_Term( 20, 'Portland', 'portland-or', 'location' ),
					21 => new WP_Term( 21, 'Portland', 'portland-me', 'location' ),
					30 => new WP_Term( 30, 'Austin', 'austin', 'location' ),
					40 => new WP_Term( 40, 'Boise', 'boise', 'location' ),
				),
				'venue'    => array(
					77 => new WP_Term( 77, 'Hed Hi Studio', 'hed-hi-studio', 'venue' ),
				),
			),
		);

		// Charleston: one city pipeline (5) storing the binding as an integer and a string.
		$this->city_pipeline( 5, 'Charleston Events', array( 10, '10' ) );
		// Austin: two pipelines bound to the same location.
		$this->city_pipeline( 6, 'Austin Events', array( 30 ) );
		$this->city_pipeline( 7, 'Austin TX Events', array( 'Austin' ) );
		// A non-event pipeline whose config mentions the Charleston ID must not match.
		$this->wpdb->pipelines[8] = array(
			'pipeline_id'   => 8,
			'pipeline_name' => 'Newsletter',
		);
		$this->wpdb->flows[]      = array(
			'pipeline_id' => 8,
			'flow_config' => wp_json_encode( array( 'publish' => array( 'taxonomy_location_selection' => 10 ) ) ),
		);

		$this->abilities = ( new ReflectionClass( VenueAddAbilities::class ) )->newInstanceWithoutConstructor();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		unset( $GLOBALS['venue_add_test'], $GLOBALS['ec_test_ability_resolver'] );
	}

	private function city_pipeline( int $pipeline_id, string $name, array $locations ): void {
		$this->wpdb->pipelines[ $pipeline_id ] = array(
			'pipeline_id'   => $pipeline_id,
			'pipeline_name' => $name,
		);
		foreach ( $locations as $location ) {
			$this->wpdb->flows[] = array(
				'pipeline_id' => $pipeline_id,
				'flow_config' => wp_json_encode(
					array(
						'import' => array( 'step_type' => 'event_import' ),
						'upsert' => array(
							'step_type'       => 'upsert',
							'handler_configs' => array( 'upsert_event' => array( 'taxonomy_location_selection' => $location ) ),
						),
					)
				),
			);
		}
	}

	private function add_venue_permission(): callable {
		if ( empty( $GLOBALS['venue_add_test_registered']['extrachill/add-venue'] ) ) {
			new VenueAddAbilities();
			do_action( 'wp_abilities_api_init' );
		}
		return $GLOBALS['venue_add_test_registered']['extrachill/add-venue']['permission_callback'];
	}

	public function test_events_team_member_passes_add_venue_gate(): void {
		$GLOBALS['venue_add_test']['role'] = 'extra_chill_team';

		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertTrue( TeamAuthorization::current_user_is_events_staff() );
		$this->assertTrue( call_user_func( $this->add_venue_permission() ) );
	}

	public function test_administrator_passes_add_venue_gate(): void {
		$GLOBALS['venue_add_test']['role'] = 'administrator';

		$this->assertTrue( call_user_func( $this->add_venue_permission() ) );
	}

	public function test_users_without_events_capability_are_denied(): void {
		$permission = $this->add_venue_permission();
		foreach ( array( 'contributor', 'subscriber', '' ) as $role ) {
			$GLOBALS['venue_add_test']['role'] = $role;
			$this->assertFalse( call_user_func( $permission ), $role );
		}
	}

	public function test_schema_requires_name_and_url_and_accepts_location(): void {
		$this->add_venue_permission();
		$schema = $GLOBALS['venue_add_test_registered']['extrachill/add-venue']['input_schema'];

		$this->assertSame( array( 'name', 'url' ), $schema['required'] );
		$this->assertArrayHasKey( 'location', $schema['properties'] );
		$this->assertArrayHasKey( 'pipeline_id', $schema['properties'] );
	}

	public function test_missing_pipeline_and_location_is_rejected(): void {
		$result = $this->abilities->executeAddVenue(
			array(
				'name' => 'Hed Hi Studio',
				'url'  => 'https://hedhi.test/events',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_pipeline', $result->get_error_code() );
	}

	/**
	 * @dataProvider charleston_location_inputs
	 *
	 * @param int|string $location Location input.
	 */
	public function test_location_resolves_city_pipeline( $location ): void {
		$result = $this->abilities->executeAddVenue(
			array(
				'location' => $location,
				'name'     => 'Hed Hi Studio',
				'url'      => 'https://hedhi.test/events',
				'dry_run'  => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['pipeline_id'] );
		$this->assertSame( '10', $result['location_term'] );
	}

	public function charleston_location_inputs(): array {
		return array(
			'term id'        => array( 10 ),
			'term id string' => array( '10' ),
			'slug'           => array( 'charleston' ),
			'name'           => array( 'Charleston' ),
		);
	}

	public function test_location_add_creates_flow_in_resolved_pipeline(): void {
		$data_machine                        = new VenueAddTestDataMachine();
		$GLOBALS['ec_test_ability_resolver'] = $data_machine->resolver();

		$result = $this->abilities->executeAddVenue(
			array(
				'location'      => 'Charleston',
				'name'          => 'Hed Hi Studio',
				'venue_term_id' => 77,
				'url'           => 'https://hedhi.test/events',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['pipeline_id'] );
		$this->assertSame( 5, $data_machine->calls['datamachine/create-flow'][0]['pipeline_id'] );
		$config = VenueAddTestDataMachine::steps( $result['flow_id'] );
		$this->assertSame( '10', $config['upsert']['handler_configs']['upsert_event']['taxonomy_location_selection'] );
		$this->assertSame( 'https://hedhi.test/events', $config['event_import']['handler_configs']['universal_web_scraper']['source_url'] );
		$this->assertSame( 'Charleston', $config['event_import']['handler_configs']['universal_web_scraper']['venue_city'] );
		$this->assertSame( array(), $this->wpdb->updates, 'Flow config is written through Data Machine abilities, not $wpdb.' );
	}

	public function test_add_without_term_reuses_same_name_same_city_venue_via_find_or_create(): void {
		// A same-name venue in another city must not be picked up by name alone.
		$GLOBALS['venue_add_test']['terms']['venue']      = array(
			76 => new WP_Term( 76, 'Hed Hi Studio', 'hed-hi-studio-portland', 'venue' ),
			77 => new WP_Term( 77, 'Hed Hi Studio', 'hed-hi-studio', 'venue' ),
		);
		$GLOBALS['venue_add_test']['venue_cities']        = array(
			76 => 'Portland',
			77 => 'Charleston',
		);
		$data_machine                        = new VenueAddTestDataMachine();
		$GLOBALS['ec_test_ability_resolver'] = $data_machine->resolver();

		$result = $this->abilities->executeAddVenue(
			array(
				'location' => 'Charleston',
				'name'     => 'Hed Hi Studio',
				'address'  => '1 Hed Hi Way',
				'zip'      => '29401',
				'url'      => 'https://hedhi.test/events',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				array(
					'Hed Hi Studio',
					array(
						'address' => '1 Hed Hi Way',
						'city'    => 'Charleston',
						'zip'     => '29401',
					),
				),
			),
			$GLOBALS['venue_add_test']['find_or_create_calls']
		);
		$this->assertCount( 2, $GLOBALS['venue_add_test']['terms']['venue'], 'No near-duplicate venue term is minted.' );
		$config = VenueAddTestDataMachine::steps( $result['flow_id'] );
		$this->assertSame( 77, $config['event_import']['handler_configs']['universal_web_scraper']['venue'] );
	}

	public function test_add_with_canonical_term_skips_find_or_create(): void {
		$data_machine                        = new VenueAddTestDataMachine();
		$GLOBALS['ec_test_ability_resolver'] = $data_machine->resolver();

		$result = $this->abilities->executeAddVenue(
			array(
				'location'      => 'Charleston',
				'name'          => 'Hed Hi Studio',
				'venue_term_id' => 77,
				'url'           => 'https://hedhi.test/events',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'find_or_create_calls', $GLOBALS['venue_add_test'] );
	}

	public function test_events_staff_without_data_machine_caps_completes_add_venue_end_to_end(): void {
		$GLOBALS['venue_add_test']['role']   = 'extra_chill_team';
		$data_machine                        = new VenueAddTestDataMachine();
		$GLOBALS['ec_test_ability_resolver'] = $data_machine->resolver();

		// Data Machine denies this user when called directly.
		$this->assertInstanceOf( WP_Error::class, $data_machine->execute( 'datamachine/create-flow', array( 'pipeline_id' => 5 ) ) );

		$this->assertTrue( call_user_func( $this->add_venue_permission() ) );
		$result = $this->abilities->executeAddVenue(
			array(
				'location'      => 'Charleston',
				'name'          => 'Hed Hi Studio',
				'venue_term_id' => 77,
				'url'           => 'https://hedhi.test/events',
			)
		);

		$this->assertIsArray( $result );
		$config = VenueAddTestDataMachine::steps( $result['flow_id'] );
		$this->assertSame( array( 'universal_web_scraper' ), $config['event_import']['handler_slugs'] );
		$this->assertSame( 77, $config['event_import']['handler_configs']['universal_web_scraper']['venue'] );
		$this->assertTrue( $config['event_import']['enabled'] );
		$this->assertSame( array( 'upsert_event' ), $config['upsert']['handler_slugs'] );
		$this->assertSame( '10', $config['upsert']['handler_configs']['upsert_event']['taxonomy_location_selection'] );
		$this->assertSame( 'publish', $config['upsert']['handler_configs']['upsert_event']['post_status'] );
		$this->assertTrue( $config['upsert']['enabled'] );
		$this->assertSame( '', $config['ai']['user_message'] );
		$this->assertCount( 3, $data_machine->calls['datamachine/update-flow-step'] );
		$this->assertSame( array(), $this->wpdb->updates );
		$this->assertFalse( $GLOBALS['venue_add_test']['as_system'] ?? false, 'Elevation is scoped to each Data Machine call.' );
	}

	public function test_events_staff_can_create_daily_instagram_draft_flow(): void {
		$GLOBALS['venue_add_test']['instagram_resolver'] = true;
		$GLOBALS['venue_add_test']['role'] = 'extra_chill_team';
		$data_machine = new VenueAddTestDataMachine();
		$GLOBALS['ec_test_ability_resolver'] = $data_machine->resolver();
		$this->assertTrue( call_user_func( $this->add_venue_permission() ) );
		$result = $this->abilities->executeAddVenue( array(
			'location' => 'Charleston',
			'name' => 'Hed Hi Studio',
			'venue_term_id' => 77,
			'url' => 'https://www.instagram.com/hedhi/',
		) );
		$this->assertIsArray( $result );
		$this->assertSame( 'daily', $result['interval'] );
		$config = VenueAddTestDataMachine::steps( $result['flow_id'] );
		$this->assertSame( array( 'username' => 'hedhi' ), $config['event_import']['handler_configs']['instagram_account'] );
		$this->assertSame( 'draft', $config['upsert']['handler_configs']['upsert_event']['post_status'] );
		$this->assertSame( '77', $config['upsert']['handler_configs']['upsert_event']['taxonomy_venue_selection'] );
		$this->assertSame( '', $config['ai']['user_message'] );
	}

	public function test_unreadable_instagram_has_clear_verdict(): void {
		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) {
			return new class {
				public function execute( $input ) {
					TestCase::assertSame( 1, $input['max_items'] );
					return new WP_Error( 'instagram_account_unavailable', 'Personal account' );
				}
			};
		};
		$result = \ExtraChillEvents\Core\EventSourceHandler::preflight( array( 'handler_slug' => 'instagram_account', 'handler_config' => array( 'username' => 'private' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Only public Business/Creator accounts can be read.', $result->get_error_message() );
	}

	public function test_non_events_staff_is_still_denied_at_add_venue(): void {
		$permission = $this->add_venue_permission();
		foreach ( array( 'contributor', 'subscriber', '' ) as $role ) {
			$GLOBALS['venue_add_test']['role'] = $role;
			$this->assertFalse( call_user_func( $permission ), $role );
		}
	}

	public function test_flow_step_write_failure_is_reported(): void {
		$data_machine                        = new VenueAddTestDataMachine();
		$resolver                            = $data_machine->resolver();
		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) use ( $resolver ) {
			if ( 'datamachine/update-flow-step' === $name ) {
				return new class() {
					public function execute( $input ) {
						return new WP_Error( 'invalid_handler_config', 'Unknown handler_config fields.' );
					}
				};
			}
			return $resolver( $name );
		};

		$result = $this->abilities->executeAddVenue(
			array(
				'location'      => 'Charleston',
				'name'          => 'Hed Hi Studio',
				'venue_term_id' => 77,
				'url'           => 'https://hedhi.test/events',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'flow_step_update_failed', $result->get_error_code() );
		$this->assertSame( 901, $result->get_error_data()['flow_id'] );
	}

	public function test_pipeline_id_still_works_without_location(): void {
		$result = $this->abilities->executeAddVenue(
			array(
				'pipeline_id' => 6,
				'name'        => 'Mohawk',
				'url'         => 'https://mohawk.test/events',
				'dry_run'     => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 6, $result['pipeline_id'] );
		$this->assertSame( '30', $result['location_term'] );
	}

	public function test_unknown_location_is_rejected(): void {
		foreach ( array( 'Atlantis', '9999' ) as $location ) {
			$result = $this->abilities->executeAddVenue(
				array(
					'location' => $location,
					'name'     => 'Nowhere Hall',
					'url'      => 'https://nowhere.test/events',
					'dry_run'  => true,
				)
			);

			$this->assertInstanceOf( WP_Error::class, $result, $location );
			$this->assertSame( 'location_not_found', $result->get_error_code(), $location );
		}
	}

	public function test_location_without_city_pipeline_is_rejected(): void {
		$result = $this->abilities->executeAddVenue(
			array(
				'location' => 'boise',
				'name'     => 'Neurolux',
				'url'      => 'https://neurolux.test/events',
				'dry_run'  => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_city_pipeline', $result->get_error_code() );
	}

	public function test_ambiguous_location_name_is_rejected_but_slug_resolves_term(): void {
		$result = $this->abilities->executeAddVenue(
			array(
				'location' => 'Portland',
				'name'     => 'Doug Fir',
				'url'      => 'https://dougfir.test/events',
				'dry_run'  => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ambiguous_location', $result->get_error_code() );

		$result = $this->abilities->executeAddVenue(
			array(
				'location' => 'portland-me',
				'name'     => 'Port City Music Hall',
				'url'      => 'https://pcmh.test/events',
				'dry_run'  => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_city_pipeline', $result->get_error_code() );
		$this->assertStringContainsString( 'location 21', $result->get_error_message() );
	}

	public function test_location_with_multiple_city_pipelines_requires_pipeline_id(): void {
		$input  = array(
			'location' => 'Austin',
			'name'     => 'Mohawk',
			'url'      => 'https://mohawk.test/events',
			'dry_run'  => true,
		);
		$result = $this->abilities->executeAddVenue( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ambiguous_city_pipeline', $result->get_error_code() );
		$this->assertSame( array( 6, 7 ), $result->get_error_data()['pipeline_ids'] );

		$result = $this->abilities->executeAddVenue( $input + array( 'pipeline_id' => 7 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['pipeline_id'] );
	}

	public function test_pipeline_id_must_match_location(): void {
		$result = $this->abilities->executeAddVenue(
			array(
				'pipeline_id' => 6,
				'location'    => 'Charleston',
				'name'        => 'Mohawk',
				'url'         => 'https://mohawk.test/events',
				'dry_run'     => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pipeline_location_mismatch', $result->get_error_code() );
	}
}
