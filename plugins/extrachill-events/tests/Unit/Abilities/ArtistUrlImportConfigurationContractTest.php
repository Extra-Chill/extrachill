<?php
/**
 * Tests for the Artist URL Import Data Machine configuration contract.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated unit fixtures intentionally use lightweight ability doubles and reflection.

require_once dirname( __DIR__, 3 ) . '/inc/Abilities/ArtistUrlImportAbilities.php';

use ExtraChillEvents\Abilities\ArtistUrlImportAbilities;

final class ArtistUrlImportConfigurationContractTest extends BookingTestCase {
	private ArtistUrlImportAbilities $instance;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_artist_test'] = array( 'ability_objects' => array() );

		$reflection     = new ReflectionClass( ArtistUrlImportAbilities::class );
		$this->instance = $reflection->newInstanceWithoutConstructor();
	}

	private function ability( callable $execute ): object {
		return new class( $execute ) {
			private $execute;

			public function __construct( callable $execute ) {
				$this->execute = $execute;
			}

			public function execute( array $input ) {
				return ( $this->execute )( $input );
			}
		};
	}

	private function invoke( string $method, ...$arguments ) {
		$reflection = new ReflectionMethod( ArtistUrlImportAbilities::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( $this->instance, ...$arguments );
	}

	public function test_shared_pipeline_lookup_uses_public_exact_name_contract(): void {
		$selectors = array();
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/get-pipeline-configuration'] = $this->ability(
			static function ( array $input ) use ( &$selectors ): array {
				$selectors[] = $input;
				return array(
					'success'  => true,
					'pipeline' => array(
						'pipeline_id' => 42,
						'revision'    => 'sha256:' . str_repeat( 'a', 64 ),
					),
					'flows'    => array(),
				);
			}
		);

		$this->assertSame( 42, $this->invoke( 'resolveSharedArtistImportPipeline' ) );
		$this->assertSame( array( array( 'pipeline_name' => 'Artist Tour Import' ) ), $selectors );
	}

	public function test_flow_configuration_updates_supported_steps_and_chains_revisions(): void {
		$updates = array();
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/get-pipeline-configuration'] = $this->ability(
			static fn(): array => array(
				'success'  => true,
				'pipeline' => array( 'pipeline_id' => 42 ),
				'flows'    => array(
					array(
						'flow_id'  => 77,
						'revision' => 'sha256:' . str_repeat( '1', 64 ),
					),
				),
			)
		);
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/update-step-configuration'] = $this->ability(
			static function ( array $input ) use ( &$updates ): array {
				$updates[] = $input;
				return array(
					'success'  => true,
					'revision' => 'sha256:' . str_repeat( (string) ( count( $updates ) + 1 ), 64 ),
				);
			}
		);

		$result = $this->invoke(
			'configureFlowSteps',
			42,
			77,
			array( 'source_url' => 'https://artist.example/tour' ),
			array( 'taxonomy_artist_selection' => '123' ),
			'Process Artist tour events.'
		);

		$this->assertTrue( $result );
		$this->assertSame( array( 'event_import', 'upsert', 'ai' ), array_column( $updates, 'step_type' ) );
		$this->assertSame( 'sha256:' . str_repeat( '1', 64 ), $updates[0]['expected_revision'] );
		$this->assertSame( 'sha256:' . str_repeat( '2', 64 ), $updates[1]['expected_revision'] );
		$this->assertSame( 'sha256:' . str_repeat( '3', 64 ), $updates[2]['expected_revision'] );
		$this->assertSame( 'universal_web_scraper', $updates[0]['configuration']['handler_slug'] );
		$this->assertSame( 'upsert_event', $updates[1]['configuration']['handler_slug'] );
		$this->assertSame( 'Process Artist tour events.', $updates[2]['configuration']['user_message'] );
	}

	public function test_missing_configuration_dependency_fails_explicitly(): void {
		$result = $this->invoke( 'getPipelineConfiguration', array( 'pipeline_id' => 42 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'datamachine_configuration_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_owner_not_found_error_is_preserved(): void {
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/get-pipeline-configuration'] = $this->ability(
			static fn(): array => array(
				'success'    => false,
				'error_code' => 'pipeline_not_found',
				'error'      => 'Pipeline not found',
				'status'     => 404,
			)
		);

		$result = $this->invoke( 'getPipelineConfiguration', array( 'pipeline_id' => 999 ) );

		$this->assertSame( 'pipeline_not_found', $result->get_error_code() );
		$this->assertSame( 'Pipeline not found', $result->get_error_message() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_stale_write_conflict_is_preserved(): void {
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/update-step-configuration'] = $this->ability(
			static fn(): array => array(
				'success'    => false,
				'error_code' => 'configuration_conflict',
				'error'      => 'Flow configuration revision is stale',
				'status'     => 409,
			)
		);

		$result = $this->invoke( 'updateStepConfiguration', array( 'target' => 'flow' ) );

		$this->assertSame( 'configuration_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_rejected_configuration_field_is_preserved(): void {
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/update-step-configuration'] = $this->ability(
			static fn(): array => array(
				'success'    => false,
				'error_code' => 'unknown_field',
				'error'      => 'Unknown flow step configuration fields: private_field',
				'status'     => 400,
			)
		);

		$result = $this->invoke( 'updateStepConfiguration', array( 'configuration' => array( 'private_field' => true ) ) );

		$this->assertSame( 'unknown_field', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_owner_wp_error_is_returned_unchanged(): void {
		$owner_error = new WP_Error( 'ability_invalid_permissions', 'Sorry, you are not allowed.', array( 'status' => 403 ) );
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/get-pipeline-configuration'] = $this->ability(
			static fn() => $owner_error
		);

		$this->assertSame( $owner_error, $this->invoke( 'getPipelineConfiguration', array( 'pipeline_id' => 42 ) ) );
	}

	public function test_artist_import_has_no_private_pipeline_or_flow_storage_access(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/ArtistUrlImportAbilities.php' );

		$this->assertIsString( $source );
		$this->assertStringNotContainsString( 'datamachine_pipelines', $source );
		$this->assertStringNotContainsString( 'datamachine_flows', $source );
		$this->assertStringNotContainsString( 'pipeline_config', $source );
		$this->assertStringNotContainsString( 'flow_config', $source );
	}
}
