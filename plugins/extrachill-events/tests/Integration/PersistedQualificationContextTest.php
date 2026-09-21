<?php
/**
 * Persisted-flow qualification integration coverage.
 *
 * This suite runs under a sandbox runtime where core's real Abilities API
 * (wp-includes/abilities-api.php) is already loaded. Test doubles are
 * registered as genuine abilities via wp_register_ability() rather than
 * through a hand-rolled global wp_get_ability() override — that override
 * duplicated a capability the managed runtime already provides and fataled
 * with "Cannot redeclare function wp_get_ability()" the moment this file
 * was reachable from a sandbox suite. See
 * https://github.com/Extra-Chill/extrachill-events/issues/846.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

	require_once __DIR__ . '/Stubs/persisted-qualification-stubs.php';
	require_once __DIR__ . '/Stubs/wp-cli-stubs.php';

	use ExtraChillEvents\Abilities\VenueQualificationAbilities;
	use ExtraChillEvents\Cli\UnqualifiableFlowsCommand;
	use ExtraChillEvents\Core\QualifyVerdict;
	use PHPUnit\Framework\TestCase;

class PersistedQualificationContextTest extends TestCase {
	/**
	 * Ability names registered by the current test, for teardown cleanup.
	 *
	 * @var array<int,string>
	 */
	private array $registered_ability_names = array();

	/**
	 * [flow_step_id, source_type, item_identifier] rows seeded into the real
	 * datamachine_processed_items table by the current test, for teardown cleanup.
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	private array $seeded_processed_items = array();

	/**
	 * flow_id values seeded into the real datamachine_flows table by the
	 * current test, for teardown cleanup.
	 *
	 * @var array<int,int>
	 */
	private array $seeded_flow_ids = array();

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::$logs      = array();
		\WP_CLI::$formatted = array();
	}

	protected function tearDown(): void {
		foreach ( $this->registered_ability_names as $name ) {
			if ( wp_get_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$this->registered_ability_names = array();

		global $wpdb;
		if ( ! empty( $this->seeded_processed_items ) ) {
			$table = $wpdb->prefix . 'datamachine_processed_items';
			foreach ( $this->seeded_processed_items as list( $flow_step_id, $source_type, $item_identifier ) ) {
				$wpdb->delete(
					$table,
					array(
						'flow_step_id'    => $flow_step_id,
						'source_type'     => $source_type,
						'item_identifier' => $item_identifier,
					)
				);
			}
			$this->seeded_processed_items = array();
		}
		if ( ! empty( $this->seeded_flow_ids ) ) {
			$table = $wpdb->prefix . 'datamachine_flows';
			foreach ( $this->seeded_flow_ids as $flow_id ) {
				$wpdb->delete( $table, array( 'flow_id' => $flow_id ) );
			}
			$this->seeded_flow_ids = array();
		}

		parent::tearDown();
	}

	/**
	 * Seeds real datamachine_processed_items rows so the real
	 * DataMachine\Core\ExecutionContext::classifySourceItems() reports every
	 * given identifier as already processed — the same outcome the deleted
	 * fake ExecutionContext unconditionally returned.
	 *
	 * @param string[] $identifiers Item identifiers to mark processed.
	 */
	private function seed_processed_items( array $identifiers, string $flow_step_id = 'step-42', int $job_id = 9001 ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_processed_items';
		foreach ( $identifiers as $identifier ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding a real fixture row for this integration test against the real ProcessedItems table.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
					"INSERT IGNORE INTO {$table} (flow_step_id, source_type, item_identifier, job_id, status) VALUES (%s, %s, %s, %d, %s)",
					$flow_step_id,
					'universal_web_scraper',
					$identifier,
					$job_id,
					'processed'
				)
			);
			$this->seeded_processed_items[] = array( $flow_step_id, 'universal_web_scraper', $identifier );
		}
	}

	/**
	 * Seeds a real datamachine_flows row so
	 * ExtraChillEvents\Cli\FlowOps::repair_flow_source_url() — called by the
	 * real UnqualifiableFlowsCommand apply-repair path — finds a matching
	 * flow_config and genuinely updates it, the same outcome the deleted
	 * fake FlowOps unconditionally recorded.
	 */
	private function seed_flow_row( int $flow_id, string $source_url ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'datamachine_flows',
			array(
				'flow_id'           => $flow_id,
				'pipeline_id'       => 3,
				'user_id'           => 0,
				'flow_name'         => 'Test Venue',
				'flow_config'       => wp_json_encode(
					array(
						array(
							'step_type'      => 'event_import',
							'handler_slug'   => 'universal_web_scraper',
							'handler_config' => array( 'source_url' => $source_url ),
						),
					)
				),
				'scheduling_config' => wp_json_encode( array( 'interval' => 'daily' ) ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		$this->seeded_flow_ids[] = $flow_id;
	}

	/**
	 * Registers a real ability for the duration of the current test only.
	 *
	 * `wp_register_ability()` requires `doing_action( 'wp_abilities_api_init' )`
	 * to be true, and that hook has already fired once for every real
	 * plugin ability by the time a test method runs. Re-firing it naively
	 * would re-invoke every other subscriber too — each hitting an
	 * "already registered" `_doing_it_wrong()` notice, which would trip
	 * this suite's `beStrictAboutOutputDuringTests`/`failOnRisky` gates.
	 *
	 * Instead, every other subscriber on `wp_abilities_api_init` is
	 * temporarily detached, the hook is fired with only this test's
	 * registrar attached, and the original subscriber list is restored
	 * immediately after — satisfying `doing_action()` for our own
	 * registration without touching any real ability's registration
	 * state.
	 *
	 * @param string   $name             Ability name, e.g. 'data-machine-events/test-event-scraper'.
	 * @param callable $execute_callback Callback invoked with the ability's input.
	 * @param array    $input_schema     JSON-Schema-shaped input schema. Defaults to a
	 *                                   permissive object schema so callers that pass an
	 *                                   array (every production call site here does) are
	 *                                   forwarded to $execute_callback rather than rejected
	 *                                   by validate_input().
	 */
	private function register_test_ability( string $name, callable $execute_callback, array $input_schema = array( 'type' => 'object' ) ): void {
		if ( wp_get_ability( $name ) ) {
			wp_unregister_ability( $name );
		}

		global $wp_filter;
		$existing = $wp_filter['wp_abilities_api_init'] ?? null;
		unset( $wp_filter['wp_abilities_api_init'] );

		$registrar = static function () use ( $name, $execute_callback, $input_schema ): void {
			wp_register_ability(
				$name,
				array(
					'label'               => $name,
					'description'         => 'Test double registered by PersistedQualificationContextTest.',
					'category'            => 'extrachill-events',
					'input_schema'        => $input_schema,
					'execute_callback'    => $execute_callback,
					'permission_callback' => '__return_true',
				)
			);
		};

			add_action( 'wp_abilities_api_init', $registrar );
			do_action( 'wp_abilities_api_init' );

		if ( null !== $existing ) {
			$wp_filter['wp_abilities_api_init'] = $existing; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the exact hook registry entries present before this isolated firing, same convention as ConcertStatsPublicProfileRenderTest::restore_url_policy_filters().
		} else {
			unset( $wp_filter['wp_abilities_api_init'] );
		}

			$this->registered_ability_names[] = $name;
	}

	public function test_real_ability_caller_passes_persisted_config_and_classifies_all_processed(): void {
		$scraper_input = array();

		$this->register_test_ability(
			'data-machine-events/test-event-scraper',
			function ( $input ) use ( &$scraper_input ) {
				$scraper_input = $input;
				return array(
					'success'         => true,
					'extraction_info' => array(
						'extraction_method'         => 'squarespace',
						'source_type'               => 'universal_web_scraper',
						'payload_type'              => 'event',
						'extracted_packet_count'    => 2,
						'unique_source_event_count' => 2,
						'production_max_items'      => 1,
					),
					'event_data'      => array(
						'items' => array(
							array( 'title' => 'One' ),
							array( 'title' => 'Two' ),
						),
					),
				);
			},
			array(
				'type'       => 'object',
				'properties' => array(
					'target_url'     => array( 'type' => 'string' ),
					'handler_config' => array(
						'type'       => 'object',
						'properties' => array(
							'source_url'       => array( 'type' => 'string' ),
							'exclude_keywords' => array( 'type' => 'string' ),
							'venue'            => array( 'type' => 'integer' ),
							'max_items'        => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);

		$this->register_test_ability(
			'datamachine/test-handler',
			function () {
				return array(
					'success'    => true,
					'packets'    => array(
						array(
							'data'     => array( 'body' => '{"event":{}}' ),
							'metadata' => array( 'item_identifier' => 'event-one' ),
						),
						array(
							'data'     => array( 'body' => '{"event":{}}' ),
							'metadata' => array( 'item_identifier' => 'event-two' ),
						),
					),
					'truncation' => array( 'truncated' => false ),
				);
			}
		);

		// The venue is a numeric term ID that doesn't resolve in this test's
		// fresh term table, and no venue_name fallback is supplied, so
		// QualifyFingerprinter::diagnostic_identifiers() can't derive
		// canonical identifiers here — it falls back to the scraper's raw
		// packet identifiers ('event-one', 'event-two'). Seed those as
		// already processed so the real ExecutionContext reports the same
		// "nothing new to do" outcome the deleted fake always returned.
		$this->seed_processed_items( array( 'event-one', 'event-two' ) );

		$ability = new class() extends VenueQualificationAbilities {
			protected function loadPersistedFlowContext( int $flow_id ): array {
				return array(
					'flow_id'        => $flow_id,
					'pipeline_id'    => 3,
					'flow_step_id'   => 'step-42',
					'job_id'         => '9001',
					'handler_config' => array(
						'source_url'        => 'https://venue.example/events',
						'exclude_keywords'  => 'comedy',
						'venue'             => 1491,
						'max_items'         => 1,
						'venue_coordinates' => '32,-79',
					),
				);
			}
		};

			$result = $ability->executeQualifyVenue(
			array(
				'flow_id'         => 42,
				'persist_verdict' => false,
			)
		);

		$this->assertSame( QualifyVerdict::QUALIFIED_STRUCTURED, $result['verdict'] );
		$this->assertSame( 2, $result['production_context']['raw_extracted'] );
		$this->assertSame( 2, $result['production_context']['processed'] );
		$this->assertSame( 0, $result['production_context']['production_eligible'] );
		$this->assertTrue( $result['production_context']['complete'] );
		$this->assertSame( 'comedy', $scraper_input['handler_config']['exclude_keywords'] );
		$this->assertSame( 1491, $scraper_input['handler_config']['venue'] );
		$this->assertArrayNotHasKey( 'venue_coordinates', $scraper_input['handler_config'] );
	}

	public function test_ad_hoc_mode_reports_missing_production_context(): void {
		$this->register_test_ability(
			'data-machine-events/test-event-scraper',
			function () {
				return array(
					'success'         => true,
					'extraction_info' => array(
						'extraction_method'         => 'jsonld',
						'source_type'               => 'universal_web_scraper',
						'payload_type'              => 'event',
						'extracted_packet_count'    => 2,
						'unique_source_event_count' => 2,
					),
					'event_data'      => array( 'items' => array( array(), array() ) ),
				);
			}
		);

		$result = ( new VenueQualificationAbilities() )->executeQualifyVenue(
			array(
				'url'             => 'https://venue.example/events',
				'persist_verdict' => false,
			)
		);

		$this->assertFalse( $result['production_context']['context_supplied'] );
		$this->assertStringContainsString( 'no persisted production flow context', $result['production_context']['reason'] );
	}

	public function test_all_processed_stable_zero_is_expected_zero(): void {
		require_once dirname( __DIR__, 2 ) . '/inc/Cli/FlowHelpers.php';
		require_once dirname( __DIR__, 2 ) . '/inc/Cli/UnqualifiableFlowsCommand.php';

		$command = new class() extends UnqualifiableFlowsCommand {
			public function classify( array $production ): string {
				return $this->classify_qualified_action( $production, null );
			}
		};

			$this->assertSame(
			'expected_zero',
			$command->classify(
				array(
					'complete'            => true,
					'production_eligible' => 0,
				)
			)
		);
	}

	/**
	 * @dataProvider large_inventory_provider
	 */
	public function test_large_all_processed_inventory_is_complete_with_one_bulk_classification( int $count ): void {
		$this->install_inventory_abilities( $count );
		$result = $this->new_qualification_ability()->executeQualifyVenue(
			array(
				'flow_id'         => 42,
				'persist_verdict' => false,
			)
		);

		$this->assertTrue( $result['production_context']['complete'] );
		$this->assertSame( $count, $result['production_context']['processed'] );
		$this->assertSame( 0, $result['production_context']['production_eligible'] );
		$this->assertSame( 'verified_event_inventory', $result['production_context']['identifier_source'] );
	}

	public function large_inventory_provider(): array {
		return array(
			'dinghy-sized'       => array( 115 ),
			'knuckleheads-sized' => array( 206 ),
		);
	}

	public function test_inventory_above_safety_ceiling_is_explicitly_incomplete(): void {
		$this->install_inventory_abilities( 501 );
		$result = $this->new_qualification_ability()->executeQualifyVenue(
			array(
				'flow_id'         => 42,
				'persist_verdict' => false,
			)
		);

		$this->assertFalse( $result['production_context']['complete'] );
		$this->assertNull( $result['production_context']['processed'] );
		$this->assertNull( $result['production_context']['production_eligible'] );
		$this->assertStringContainsString( 'observed 100 of 501', $result['production_context']['error'] );
	}

	public function test_command_invocation_forwards_persisted_context_and_renders_truthful_counts(): void {
		$this->install_inventory_abilities( 115 );
		$qualification = $this->new_qualification_ability();
		$calls         = array();
		$this->register_test_ability(
			'extrachill/qualify-venue',
			function ( $input ) use ( $qualification, &$calls ) {
				$calls[] = $input;
				return $qualification->executeQualifyVenue( $input );
			}
		);

		$this->new_command()->__invoke( array(), array(
			'dry-run'  => true,
			'min-runs' => 1,
		) );
		$row = \WP_CLI::$formatted[0]['items'][0];

		$this->assertSame( 42, $calls[0]['flow_id'] );
		$this->assertFalse( $calls[0]['persist_verdict'] );
		$this->assertSame( 115, $row['raw_extracted'] );
		$this->assertSame( 115, $row['unique_source'] );
		$this->assertSame( 115, $row['processed'] );
		$this->assertSame( 0, $row['production_eligible'] );
		$this->assertTrue( $row['complete'] );
		$this->assertSame( 'verified_event_inventory', $row['identifier_source'] );
		$this->assertSame( 'expected_zero', $row['action'] );
	}

	public function test_command_renders_incomplete_lifecycle_counts_as_non_authoritative(): void {
		$this->install_inventory_abilities( 501 );
		$qualification = $this->new_qualification_ability();
		$this->register_test_ability(
			'extrachill/qualify-venue',
			function ( $input ) use ( $qualification ) {
				return $qualification->executeQualifyVenue( $input );
			}
		);

		$this->new_command()->__invoke( array(), array(
			'dry-run'  => true,
			'min-runs' => 1,
		) );
		$row = \WP_CLI::$formatted[0]['items'][0];

		$this->assertFalse( $row['complete'] );
		$this->assertNull( $row['processed'] );
		$this->assertNull( $row['production_eligible'] );
		$this->assertStringContainsString( 'observed 100 of 501', $row['diagnostic_error'] );
		$this->assertSame( 'unexpected_pass', $row['action'] );
	}

	public function test_stale_zero_command_proposes_in_dry_run_then_applies_only_when_confirmed(): void {
		$this->register_test_ability(
			'data-machine-events/test-event-scraper',
			function ( $input ) {
				$qualified = 'https://venue.example/events' === $input['target_url'];
				$items     = $qualified
					? array(
						array(
							'title'     => 'One',
							'startDate' => '2026-08-01',
						),
						array(
							'title'     => 'Two',
							'startDate' => '2026-08-02',
						),
					)
					: array();
				return array(
					'success'         => true,
					'extraction_info' => array(
						'extraction_method'         => 'jsonld',
						'source_type'               => 'universal_web_scraper',
						'payload_type'              => 'event',
						'extracted_packet_count'    => count( $items ),
						'unique_source_event_count' => count( $items ),
					),
					'event_data'      => array( 'items' => $items ),
				);
			}
		);
		$this->register_test_ability(
			'datamachine/test-handler',
			function () {
				return array(
					'success'    => true,
					'packets'    => array(),
					'truncation' => array( 'truncated' => false ),
				);
			}
		);
		// UnqualifiableFlowsCommand's apply-repair path calls the real
		// ExtraChillEvents\Cli\FlowOps::repair_flow_source_url(), which reads
		// and writes a real datamachine_flows row — seed one whose
		// flow_config carries the stale URL being repaired.
		$this->seed_flow_row( 42, 'https://venue.example/stale' );

		$qualification = $this->new_qualification_ability( 'https://venue.example/stale' );
		$result        = $qualification->executeQualifyVenue( array(
			'flow_id'         => 42,
			'persist_verdict' => false,
		) );
		$this->assertSame( 0, $result['production_context']['production_eligible'] );
		$this->assertSame( 'https://venue.example/events', $result['repair_proposal']['proposed'] );

		$this->register_test_ability(
			'extrachill/qualify-venue',
			function () use ( $result ) {
				return $result;
			}
		);

		$this->new_command()->__invoke( array(), array(
			'dry-run'  => true,
			'min-runs' => 1,
		) );
		$this->assertSame( 'repair_proposed', \WP_CLI::$formatted[0]['items'][0]['action'] );
		$this->assertSame( 'https://venue.example/stale', $this->stored_flow_source_url( 42 ) );

		\WP_CLI::$formatted = array();
		$this->new_command()->__invoke(
			array(),
			array(
				'apply-repair' => true,
				'yes'          => true,
				'min-runs'     => 1,
			)
		);
		$this->assertSame( 'repaired', \WP_CLI::$formatted[0]['items'][0]['action'] );
		$this->assertSame( 'https://venue.example/events', $this->stored_flow_source_url( 42 ) );
	}

	/**
	 * Reads back the source_url FlowOps::repair_flow_source_url() would
	 * find/patch for a flow's universal_web_scraper step, straight from the
	 * real datamachine_flows row seeded by seed_flow_row().
	 */
	private function stored_flow_source_url( int $flow_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading back this test's own seeded fixture row.
		$encoded = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT flow_config FROM {$wpdb->prefix}datamachine_flows WHERE flow_id = %d",
				$flow_id
			)
		);
		$flow_config = json_decode( (string) $encoded, true );
		return $flow_config[0]['handler_config']['source_url'] ?? null;
	}

	public function test_apply_repair_requires_explicit_confirmation(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '--apply-repair requires --yes' );
		$this->new_command()->__invoke( array(), array(
			'apply-repair' => true,
			'min-runs'     => 1,
		) );
	}

	private function install_inventory_abilities( int $count ): void {
		$items = array();
		foreach ( range( 1, $count ) as $index ) {
			$items[] = array(
				'title'     => 'Event ' . $index,
				'startDate' => sprintf( '2026-08-%02d', ( ( $index - 1 ) % 28 ) + 1 ),
			);
		}

		// new_qualification_ability()'s handler_config carries venue_name
		// (not a numeric venue term), so QualifyFingerprinter derives
		// canonical identifiers for the *whole* reported inventory via
		// EventIdentifierGenerator — not just the raw ability's 100-packet
		// ceiling. Seed the same identifiers as already processed so the
		// real ExecutionContext reports the "everything's already been
		// seen" outcome these tests assert on.
		if ( $count <= 500 ) {
			$identifiers = array();
			foreach ( $items as $item ) {
				$identifiers[] = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
					$item['title'],
					$item['startDate'],
					'Test Venue'
				);
			}
			$this->seed_processed_items( $identifiers );
		}

		$this->register_test_ability(
			'data-machine-events/test-event-scraper',
			function () use ( $items ) {
				$count = count( $items );
				return array(
					'success'         => true,
					'extraction_info' => array(
						'extraction_method'         => 'jsonld',
						'source_type'               => 'universal_web_scraper',
						'payload_type'              => 'event',
						'extracted_packet_count'    => $count,
						'unique_source_event_count' => $count,
					),
					'event_data'      => array( 'items' => $items ),
				);
			}
		);

		$this->register_test_ability(
			'datamachine/test-handler',
			function () use ( $items ) {
				$packets = array();
				foreach ( array_slice( $items, 0, 100 ) as $item ) {
					$packets[] = array(
						'data'     => array( 'body' => '{"event":{}}' ),
						'metadata' => array(
							'item_identifier' => \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
								$item['title'],
								$item['startDate'],
								'Test Venue'
							),
						),
					);
				}
				return array(
					'success'    => true,
					'packets'    => $packets,
					'truncation' => array( 'truncated' => count( $items ) > 100 ),
				);
			}
		);
	}

	private function new_qualification_ability( string $source_url = 'https://venue.example/events' ): VenueQualificationAbilities {
		return new class( $source_url ) extends VenueQualificationAbilities {
			private string $source_url;
			public function __construct( string $source_url ) {
				$this->source_url = $source_url;
				parent::__construct();
			}
			protected function loadPersistedFlowContext( int $flow_id ): array {
				return array(
					'flow_id'        => $flow_id,
					'pipeline_id'    => 3,
					'flow_step_id'   => 'step-42',
					'job_id'         => '9001',
					'handler_config' => array(
						'source_url'        => $this->source_url,
						'venue_name'        => 'Test Venue',
						'venue_coordinates' => '32,-79',
					),
				);
			}
		};
	}

	private function new_command(): UnqualifiableFlowsCommand {
		require_once dirname( __DIR__, 2 ) . '/inc/Cli/FlowHelpers.php';
		require_once dirname( __DIR__, 2 ) . '/inc/Cli/UnqualifiableFlowsCommand.php';

		return new class() extends UnqualifiableFlowsCommand {
			protected function load_all_web_scraper_flows(): array {
				return array(
					array(
						'flow_id'           => 42,
						'flow_name'         => 'Test Venue',
						'source_url'        => 'https://venue.example/events',
						'handler_slug'      => 'universal_web_scraper',
						'scheduling_config' => array( 'interval' => 'daily' ),
					),
				);
			}
			protected function count_recent_runs( int $flow_id, int $lookback = 25 ): array {
				return array(
					'recent'        => 25,
					'zero_yield'    => 25,
					'any_completed' => 25,
				);
			}
		};
	}
}
