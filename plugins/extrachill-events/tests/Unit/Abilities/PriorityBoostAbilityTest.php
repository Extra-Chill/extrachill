<?php
/**
 * Priority boost operation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Abilities/PriorityEventAbilities.php';
require_once dirname( __DIR__, 2 ) . '/Support/PriorityBoostAbilityDouble.php';

/** Covers the paid event priority operation contract. */
final class PriorityBoostAbilityTest extends TestCase {

	/**
	 * Ability test double.
	 *
	 * @var PriorityBoostAbilityDouble
	 */
	private $ability;

	/** Prepare one canonical event. */
	protected function setUp(): void {
		$this->ability               = new PriorityBoostAbilityDouble();
		$this->ability->events['42'] = (object) array(
			'ID'          => 42,
			'post_type'   => 'data_machine_events',
			'post_status' => 'publish',
			'post_title'  => 'Test Event',
			'post_name'   => 'test-event',
		);
	}

	/** A valid request grants priority and returns an opaque receipt. */
	public function test_grants_priority_and_returns_receipt(): void {
		$result = $this->ability->grant_priority_boost( $this->input() );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['replayed'] );
		$this->assertFalse( $result['existing_priority_preserved'] );
		$this->assertTrue( $this->ability->priority[42] );
		$this->assertSame( 42, $result['event']['post_id'] );
		$this->assertSame( 7, $result['receipt']['actor_id'] );
	}

	/** Exact retries return the original projection without another write. */
	public function test_exact_duplicate_replays_without_another_write(): void {
		$first  = $this->ability->grant_priority_boost( $this->input() );
		$second = $this->ability->grant_priority_boost( $this->input() );

		$this->assertFalse( $first['replayed'] );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( $first['receipt'], $second['receipt'] );
		$this->assertSame( 1, $this->ability->priority_writes );
	}

	/** Reusing a key for another external reference fails explicitly. */
	public function test_conflicting_duplicate_returns_explicit_error(): void {
		$this->ability->grant_priority_boost( $this->input() );
		$conflict                       = $this->input();
		$conflict['external_reference'] = 'order:other';

		$result = $this->ability->grant_priority_boost( $conflict );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'priority_boost_idempotency_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/** Missing canonical events return a stable not-found error. */
	public function test_missing_event_returns_stable_error(): void {
		$input          = $this->input();
		$input['event'] = 'missing';

		$result = $this->ability->grant_priority_boost( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'priority_boost_event_not_found', $result->get_error_code() );
	}

	/** Non-administrator actors receive an explicit capability error. */
	public function test_unauthorized_actor_returns_explicit_error(): void {
		$this->ability->can_manage = false;

		$result = $this->ability->can_grant_priority_boost( $this->input() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'priority_boost_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/** Successful grants invalidate the established Events cache only. */
	public function test_success_invalidates_only_the_priority_cache(): void {
		$this->ability->grant_priority_boost( $this->input() );

		$this->assertSame( array( array( 'extrachill_priority_event_ids', 'extrachill-events' ) ), $this->ability->cache_deletes );
	}

	/** Legacy priority state remains active while receiving a new receipt. */
	public function test_existing_priority_state_is_preserved_for_migration(): void {
		$this->ability->priority[42] = true;

		$result = $this->ability->grant_priority_boost( $this->input() );

		$this->assertTrue( $result['existing_priority_preserved'] );
		$this->assertTrue( $result['event']['priority'] );
		$this->assertTrue( $this->ability->priority[42] );
	}

	/** Return canonical operation input. */
	private function input(): array {
		return array(
			'event'              => '42',
			'external_reference' => 'order:100:item:5',
			'idempotency_key'    => 'priority-boost:100:5',
		);
	}
}
