<?php
/**
 * Root location repair boundary tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\RootLocationRepair;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/RootLocationRepair.php';

/** Verifies mutation ordering and compensating rollback. */
final class RootLocationRepairTest extends TestCase {

	/** Relationships and redirects are verified before deletion. */
	public function test_verifies_relationships_and_redirect_before_deletion(): void {
		$fixture = $this->fixture();
		$result  = $fixture['repair']->repair( $fixture['duplicate'], $fixture['canonical'] );

		$this->assertSame(
			array(
				'status' => 'reconciled',
				'reason' => 'relationships_redirect_and_deletion_verified',
			),
			$result
		);
		$this->assertSame( array( 200 ), $fixture['state']->relationships[101] );
		$this->assertFalse( $fixture['state']->term_exists );
		$this->assertSame( array( 'prepare_redirect', 'add:101:200', 'remove:101:100', 'create_redirect', 'verify_redirect', 'delete_term' ), $fixture['state']->events );
	}

	/** Redirect failure restores original relationships and blocks deletion. */
	public function test_redirect_failure_rolls_relationships_back_before_deletion(): void {
		$fixture                        = $this->fixture();
		$fixture['state']->create_fails = true;
		$result                         = $fixture['repair']->repair( $fixture['duplicate'], $fixture['canonical'] );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'redirect_creation_failed_rolled_back', $result['reason'] );
		$this->assertSame( array( 100 ), $fixture['state']->relationships[101] );
		$this->assertTrue( $fixture['state']->term_exists );
		$this->assertNotContains( 'delete_term', $fixture['state']->events );
	}

	/** Partial relationship failure is compensated before returning. */
	public function test_relationship_failure_compensates_partial_mutation(): void {
		$fixture                        = $this->fixture();
		$fixture['state']->remove_fails = true;
		$result                         = $fixture['repair']->repair( $fixture['duplicate'], $fixture['canonical'] );

		$this->assertSame( 'relationship_remove_failed_rolled_back', $result['reason'] );
		$this->assertSame( array( 100 ), $fixture['state']->relationships[101] );
		$this->assertTrue( $fixture['state']->term_exists );
	}

	/** Build an in-memory operation adapter fixture. */
	private function fixture(): array {
		$state      = (object) array(
			'relationships' => array( 101 => array( 100 ) ),
			'events'        => array(),
			'redirect'      => false,
			'term_exists'   => true,
			'create_fails'  => false,
			'remove_fails'  => false,
		);
		$operations = array(
			'prepare_redirect'    => static function () use ( $state ) {
				$state->events[] = 'prepare_redirect';
				return array( 'existing' => false );
			},
			'get_objects'         => static function ( int $term_id ) use ( $state ): array {
				return array_keys( array_filter( $state->relationships, static fn( array $terms ): bool => in_array( $term_id, $terms, true ) ) );
			},
			'get_object_terms'    => static fn( int $object_id ): array => $state->relationships[ $object_id ],
			'add_relationship'    => static function ( int $object_id, int $term_id ) use ( $state ): bool {
				$state->events[] = "add:{$object_id}:{$term_id}";
				if ( ! in_array( $term_id, $state->relationships[ $object_id ], true ) ) {
					$state->relationships[ $object_id ][] = $term_id;
					sort( $state->relationships[ $object_id ] );
				}
				return true;
			},
			'remove_relationship' => static function ( int $object_id, int $term_id ) use ( $state ): bool {
				$state->events[] = "remove:{$object_id}:{$term_id}";
				if ( $state->remove_fails && 100 === $term_id ) {
					$state->remove_fails = false;
					return false;
				}
				$state->relationships[ $object_id ] = array_values( array_diff( $state->relationships[ $object_id ], array( $term_id ) ) );
				return true;
			},
			'create_redirect'     => static function () use ( $state ) {
				$state->events[] = 'create_redirect';
				if ( $state->create_fails ) {
					return new WP_Error( 'redirect_creation_failed' );
				}
				$state->redirect = true;
				return 55;
			},
			'verify_redirect'     => static function () use ( $state ): bool {
				$state->events[] = 'verify_redirect';
				return $state->redirect;
			},
			'delete_redirect'     => static function () use ( $state ): bool {
				$state->redirect = false;
				return true;
			},
			'delete_term'         => static function () use ( $state ): bool {
				$state->events[]     = 'delete_term';
				$state->term_exists = false;
				return true;
			},
			'term_exists'         => static fn(): bool => $state->term_exists,
		);

		return array(
			'repair'    => new RootLocationRepair( $operations ),
			'state'     => $state,
			'duplicate' => (object) array(
				'term_id' => 100,
				'name'    => 'Charleston, SC',
			),
			'canonical' => (object) array(
				'term_id' => 200,
				'name'    => 'Charleston',
			),
		);
	}
}
