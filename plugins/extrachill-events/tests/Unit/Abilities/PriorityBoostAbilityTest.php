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
		$this->ability                  = new PriorityBoostAbilityDouble();
		$this->ability->events['42']    = (object) array(
			'ID'          => 42,
			'post_type'   => 'data_machine_events',
			'post_status' => 'publish',
			'post_title'  => 'Test Event',
			'post_name'   => 'test-event',
		);
		$this->ability->event_dates[42] = '2026-08-07';
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
		$this->assertArrayNotHasKey( 'external_reference', $result );
		$this->assertArrayNotHasKey( 'idempotency_key', $result );
		$this->assertArrayNotHasKey( 'external_reference', $result['receipt'] );
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

	/** Trusted commerce fulfillment can execute without a WordPress actor. */
	public function test_trusted_commerce_allows_user_zero(): void {
		$this->ability->actor_id   = 0;
		$this->ability->can_manage = false;
		$input                     = $this->input();
		$authorize                 = static function ( bool $trusted, array $received ) use ( $input ): bool {
			return false === $trusted && $input === $received;
		};
		add_filter( 'extrachill_events_priority_boost_trusted_commerce', $authorize, 10, 2 );

		try {
			$this->assertTrue( $this->ability->can_grant_priority_boost( $input ) );
			$result = $this->ability->grant_priority_boost( $input );
			$this->assertTrue( $result['success'] );
			$this->assertSame( 0, $result['receipt']['actor_id'] );
		} finally {
			remove_filter( 'extrachill_events_priority_boost_trusted_commerce', $authorize, 10 );
		}
	}

	/** Untrusted user zero remains unauthenticated. */
	public function test_untrusted_user_zero_requires_authentication(): void {
		$this->ability->actor_id   = 0;
		$this->ability->can_manage = false;

		$result = $this->ability->can_grant_priority_boost( $this->input() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'priority_boost_authentication_required', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/** Authenticated customers remain forbidden without trusted fulfillment. */
	public function test_customer_returns_explicit_capability_error(): void {
		$this->ability->can_manage = false;

		$result = $this->ability->can_grant_priority_boost( $this->input() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'priority_boost_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/** Administrators retain direct execution authority. */
	public function test_administrator_remains_authorized(): void {
		$this->assertTrue( $this->ability->can_grant_priority_boost( $this->input() ) );
		$result = $this->ability->grant_priority_boost( $this->input() );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 7, $result['receipt']['actor_id'] );
	}

	/** New grants reject past events before writing state. */
	public function test_past_event_is_ineligible(): void {
		$this->ability->event_dates[42] = '2026-08-05';

		$result = $this->ability->grant_priority_boost( $this->input() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'priority_boost_event_ineligible', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->ability->priority_writes );
	}

	/** Exact retries retain their receipt after the event date passes. */
	public function test_completed_grant_replays_after_event_passes(): void {
		$first                          = $this->ability->grant_priority_boost( $this->input() );
		$this->ability->event_dates[42] = '2026-08-05';

		$replay = $this->ability->grant_priority_boost( $this->input() );

		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $first['receipt'], $replay['receipt'] );
		$this->assertSame( 1, $this->ability->priority_writes );
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
