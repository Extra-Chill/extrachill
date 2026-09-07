<?php
/**
 * Concrete Data Machine delegated-operation ability schema composition.
 *
 * @package ExtraChillEvents\Tests
 */

namespace DataMachine\Core\DelegatedOperations {
	// Guarded because Data Machine ships this class. Whenever it is a loaded
	// dependency the real one already exists and redeclaring it is fatal.
	if ( ! class_exists( 'DataMachine\\Core\\DelegatedOperations\\DelegatedOperationService' ) ) {
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
}

namespace DataMachine\Abilities {
	// Guarded because Data Machine ships these. Whenever it is a loaded
	// dependency the real classes already exist and redeclaring them is fatal.
	if ( ! class_exists( 'DataMachine\\Abilities\\AbilityRegistration' ) ) {
		class AbilityRegistration {
			public static function on_abilities_api_init( callable $callback ): void {
				unset( $callback );
			}
		}
	}

	if ( ! class_exists( 'DataMachine\\Abilities\\ExecutionScope' ) ) {
		class ExecutionScope {
		}
	}
}

namespace {
	use DataMachine\Abilities\DelegatedOperationAbilities;
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/Support/BookingTestHarness.php';
	require_once dirname( __DIR__, 2 ) . '/data-machine/inc/Abilities/DelegatedOperationAbilities.php';

	final class BookingMarketingDelegatedSchemaTest extends BookingTestCase {
		protected function setUp(): void {
			$GLOBALS['ec_artist_test'] = array( 'abilities' => array() );
		}

		/** Whether the local no-op stub, rather than Data Machine's real class, is loaded. */
		private static function usingLocalAbilityRegistrationStub(): bool {
			return ! property_exists( 'DataMachine\\Abilities\\AbilityRegistration', 'registration_owners' );
		}

		public function test_events_requests_match_concrete_data_machine_ability_schemas(): void {
			// This test captures registrations into a global, which only works
			// with the local AbilityRegistration stub. Data Machine's real
			// implementation defers to wp_abilities_api_init and dedupes by
			// owner, so once its own bootstrap has registered these abilities a
			// second call returns early and captures nothing. When the real
			// class is loaded there is nothing here left to assert.
			if ( ! self::usingLocalAbilityRegistrationStub() ) {
				$this->markTestSkipped( 'Delegated schema capture requires the local AbilityRegistration stub; Data Machine is providing the real one.' );
			}

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
