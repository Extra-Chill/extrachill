<?php
/**
 * Persisted-flow qualification integration coverage.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

	require_once __DIR__ . '/Stubs/persisted-qualification-global-stubs.php';
	require_once __DIR__ . '/Stubs/persisted-qualification-stubs.php';
	require_once __DIR__ . '/Stubs/wp-cli-stubs.php';

	use DataMachine\Core\ExecutionContext;
	use ExtraChillEvents\Abilities\VenueQualificationAbilities;
	use ExtraChillEvents\Cli\UnqualifiableFlowsCommand;
	use ExtraChillEvents\Cli\FlowOps;
	use ExtraChillEvents\Core\QualifyVerdict;
	use PHPUnit\Framework\TestCase;

	class PersistedQualificationContextTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['ec_persisted_qualification_abilities'] = array();
			ExecutionContext::$scope                           = array();
			ExecutionContext::$classify_calls                  = 0;
			ExecutionContext::$lifecycle_writes                = 0;
			ExecutionContext::$classified_identifiers         = array();
			FlowOps::$repairs                                  = array();
			\WP_CLI::$logs                                    = array();
			\WP_CLI::$formatted                               = array();
		}

		public function test_real_ability_caller_passes_persisted_config_and_classifies_all_processed(): void {
			$scraper = new class() {
				public array $input = array();

				public function get_input_schema(): array {
					return array(
						'properties' => array(
							'handler_config' => array(
								'properties' => array(
									'source_url'       => array(),
									'exclude_keywords' => array(),
									'venue'            => array(),
									'max_items'        => array(),
								),
							),
						),
					);
				}

				public function execute( array $input ): array {
					$this->input = $input;
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
				}
			};

			$GLOBALS['ec_persisted_qualification_abilities']['data-machine-events/test-event-scraper'] = $scraper;
			$GLOBALS['ec_persisted_qualification_abilities']['datamachine/test-handler']                = new class() {
				public function execute(): array {
					return array(
						'success'    => true,
						'packets'    => array(
							array( 'data' => array( 'body' => '{"event":{}}' ), 'metadata' => array( 'item_identifier' => 'event-one' ) ),
							array( 'data' => array( 'body' => '{"event":{}}' ), 'metadata' => array( 'item_identifier' => 'event-two' ) ),
						),
						'truncation' => array( 'truncated' => false ),
					);
				}
			};

			$ability = new class() extends VenueQualificationAbilities {
				protected function loadPersistedFlowContext( int $flow_id ): array {
					return array(
						'flow_id'        => $flow_id,
						'pipeline_id'    => 3,
						'flow_step_id'   => 'step-42',
						'job_id'         => '9001',
						'handler_config' => array(
							'source_url'       => 'https://venue.example/events',
							'exclude_keywords' => 'comedy',
							'venue'            => 1491,
							'max_items'        => 1,
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
			$this->assertSame( 'comedy', $scraper->input['handler_config']['exclude_keywords'] );
			$this->assertSame( 1491, $scraper->input['handler_config']['venue'] );
			$this->assertArrayNotHasKey( 'venue_coordinates', $scraper->input['handler_config'] );
			$this->assertSame( 'step-42', ExecutionContext::$scope['flow_step_id'] );
			$this->assertSame( '9001', ExecutionContext::$scope['job_id'] );
		}

		public function test_ad_hoc_mode_reports_missing_production_context(): void {
			$GLOBALS['ec_persisted_qualification_abilities']['data-machine-events/test-event-scraper'] = new class() {
				public function execute(): array {
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
			};

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
						'complete'             => true,
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
			$this->assertSame( 1, ExecutionContext::$classify_calls );
			$this->assertCount( $count, ExecutionContext::$classified_identifiers );
			$this->assertSame( 0, ExecutionContext::$lifecycle_writes );
		}

		public function large_inventory_provider(): array {
			return array(
				'dinghy-sized'     => array( 115 ),
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
			$this->assertSame( 0, ExecutionContext::$classify_calls );
		}

		public function test_command_invocation_forwards_persisted_context_and_renders_truthful_counts(): void {
			$this->install_inventory_abilities( 115 );
			$qualification = $this->new_qualification_ability();
			$calls         = array();
			$GLOBALS['ec_persisted_qualification_abilities']['extrachill/qualify-venue'] = new class( $qualification, $calls ) {
				private VenueQualificationAbilities $qualification;
				public array $calls;

				public function __construct( VenueQualificationAbilities $qualification, array &$calls ) {
					$this->qualification = $qualification;
					$this->calls         = &$calls;
				}

				public function execute( array $input ): array {
					$this->calls[] = $input;
					return $this->qualification->executeQualifyVenue( $input );
				}
			};

			$this->new_command()->__invoke( array(), array( 'dry-run' => true, 'min-runs' => 1 ) );
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
			$GLOBALS['ec_persisted_qualification_abilities']['extrachill/qualify-venue'] = new class( $qualification ) {
				private VenueQualificationAbilities $qualification;
				public function __construct( VenueQualificationAbilities $qualification ) {
					$this->qualification = $qualification;
				}
				public function execute( array $input ): array {
					return $this->qualification->executeQualifyVenue( $input );
				}
			};

			$this->new_command()->__invoke( array(), array( 'dry-run' => true, 'min-runs' => 1 ) );
			$row = \WP_CLI::$formatted[0]['items'][0];

			$this->assertFalse( $row['complete'] );
			$this->assertNull( $row['processed'] );
			$this->assertNull( $row['production_eligible'] );
			$this->assertStringContainsString( 'observed 100 of 501', $row['diagnostic_error'] );
			$this->assertSame( 'unexpected_pass', $row['action'] );
		}

		public function test_stale_zero_command_proposes_in_dry_run_then_applies_only_when_confirmed(): void {
			$GLOBALS['ec_persisted_qualification_abilities']['data-machine-events/test-event-scraper'] = new class() {
				public function execute( array $input ): array {
					$qualified = 'https://venue.example/events' === $input['target_url'];
					$items     = $qualified
						? array(
							array( 'title' => 'One', 'startDate' => '2026-08-01' ),
							array( 'title' => 'Two', 'startDate' => '2026-08-02' ),
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
			};
			$GLOBALS['ec_persisted_qualification_abilities']['datamachine/test-handler'] = new class() {
				public function execute(): array {
					return array(
						'success'    => true,
						'packets'    => array(),
						'truncation' => array( 'truncated' => false ),
					);
				}
			};
			$qualification = $this->new_qualification_ability( 'https://venue.example/stale' );
			$result        = $qualification->executeQualifyVenue( array( 'flow_id' => 42, 'persist_verdict' => false ) );
			$this->assertSame( 0, $result['production_context']['production_eligible'] );
			$this->assertSame( 'https://venue.example/events', $result['repair_proposal']['proposed'] );

			$GLOBALS['ec_persisted_qualification_abilities']['extrachill/qualify-venue'] = new class( $result ) {
				private array $result;
				public function __construct( array $result ) {
					$this->result = $result;
				}
				public function execute(): array {
					return $this->result;
				}
			};

			$this->new_command()->__invoke( array(), array( 'dry-run' => true, 'min-runs' => 1 ) );
			$this->assertSame( 'repair_proposed', \WP_CLI::$formatted[0]['items'][0]['action'] );
			$this->assertSame( array(), FlowOps::$repairs );

			\WP_CLI::$formatted = array();
			$this->new_command()->__invoke(
				array(),
				array( 'apply-repair' => true, 'yes' => true, 'min-runs' => 1 )
			);
			$this->assertSame( 'repaired', \WP_CLI::$formatted[0]['items'][0]['action'] );
			$this->assertCount( 1, FlowOps::$repairs );
			$this->assertSame( 'https://venue.example/events', FlowOps::$repairs[0]['proposed_url'] );
		}

		public function test_apply_repair_requires_explicit_confirmation(): void {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( '--apply-repair requires --yes' );
			$this->new_command()->__invoke( array(), array( 'apply-repair' => true, 'min-runs' => 1 ) );
		}

		private function install_inventory_abilities( int $count ): void {
			$items = array();
			foreach ( range( 1, $count ) as $index ) {
				$items[] = array(
					'title'     => 'Event ' . $index,
					'startDate' => sprintf( '2026-08-%02d', ( ( $index - 1 ) % 28 ) + 1 ),
				);
			}
			$GLOBALS['ec_persisted_qualification_abilities']['data-machine-events/test-event-scraper'] = new class( $items ) {
				private array $items;
				public function __construct( array $items ) {
					$this->items = $items;
				}
				public function execute(): array {
					$count = count( $this->items );
					return array(
						'success'         => true,
						'extraction_info' => array(
							'extraction_method'         => 'jsonld',
							'source_type'               => 'universal_web_scraper',
							'payload_type'              => 'event',
							'extracted_packet_count'    => $count,
							'unique_source_event_count' => $count,
						),
						'event_data'      => array( 'items' => $this->items ),
					);
				}
			};
			$GLOBALS['ec_persisted_qualification_abilities']['datamachine/test-handler'] = new class( $items ) {
				private array $items;
				public int $calls = 0;
				public function __construct( array $items ) {
					$this->items = $items;
				}
				public function execute(): array {
					++$this->calls;
					$packets = array();
					foreach ( array_slice( $this->items, 0, 100 ) as $item ) {
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
						'truncation' => array( 'truncated' => count( $this->items ) > 100 ),
					);
				}
			};
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
							'source_url' => $this->source_url,
							'venue_name' => 'Test Venue',
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
					return array( 'recent' => 25, 'zero_yield' => 25, 'any_completed' => 25 );
				}
			};
		}
	}
