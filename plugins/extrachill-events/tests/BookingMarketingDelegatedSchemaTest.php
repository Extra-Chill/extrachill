<?php
/**
 * Concrete Data Machine delegated-operation ability schema composition.
 *
 * @package ExtraChillEvents\Tests
 */

namespace DataMachine\Core\DelegatedOperations {
	class DelegatedOperationService {
		public function submit( array $input ): array {
			return $input;
		}
		public function reconcile( array $input ): array {
			return $input;
		}
		public function retry( array $input ): array {
			return $input;
		}
		public function cancel( array $input ): array {
			return $input;
		}
	}
}

namespace DataMachine\Abilities {
	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void {
			unset( $callback );
		}
	}

	class ExecutionScope {
	}
}

namespace {
	use DataMachine\Abilities\DelegatedOperationAbilities;
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/Support/BookingTestHarness.php';
	require_once dirname( __DIR__, 2 ) . '/data-machine/inc/Abilities/DelegatedOperationAbilities.php';

	final class BookingMarketingDelegatedSchemaTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['ec_artist_test'] = array( 'abilities' => array() );
		}

		public function test_events_requests_match_concrete_data_machine_ability_schemas(): void {
			$abilities = new DelegatedOperationAbilities( new \DataMachine\Core\DelegatedOperations\DelegatedOperationService() );
			$abilities->register();
			$registered = $GLOBALS['ec_artist_test']['abilities'];
			$this->assertSame(
				array(
					'datamachine/submit-delegated-operation',
					'datamachine/get-delegated-operation',
					'datamachine/retry-delegated-operation',
					'datamachine/cancel-delegated-operation',
				),
				array_keys( $registered )
			);
			$submit = $registered['datamachine/submit-delegated-operation']['input_schema'];
			$this->assertSame( array( 'action', 'operation_id', 'input' ), $submit['required'] );
			$this->assertSame( array( 'action', 'operation_id', 'input', 'timestamp' ), array_keys( $submit['properties'] ) );
			$this->assertFalse( $submit['additionalProperties'] );
			foreach ( array( 'get', 'retry', 'cancel' ) as $verb ) {
				$schema = $registered[ 'datamachine/' . $verb . '-delegated-operation' ]['input_schema'];
				$this->assertSame( array( 'action', 'operation_ref' ), $schema['required'] );
				$this->assertSame( '^dop_[a-f0-9]{64}$', $schema['properties']['operation_ref']['pattern'] );
				$this->assertFalse( $schema['additionalProperties'] );
			}
		}
	}
}
