<?php
/**
 * Priority boost operation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Abilities/PriorityEventAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/priority-boost-service-authority.php';
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

	/** Exact target-runtime service authority can execute as user zero. */
	public function test_verified_target_service_allows_user_zero(): void {
		$this->ability->actor_id                   = 0;
		$this->ability->can_manage                 = false;
		$this->ability->verified_service_authority = true;

		$this->assertTrue( $this->ability->can_grant_priority_boost( $this->input() ) );
		$result = $this->ability->grant_priority_boost( $this->input() );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['receipt']['actor_id'] );
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

	/** Fresh transport assertions retain business duplicate semantics. */
	public function test_fresh_assertions_replay_duplicate_business_callback(): void {
		$this->ability->actor_id                   = 0;
		$this->ability->can_manage                 = false;
		$this->ability->verified_service_authority = true;

		$this->assertTrue( $this->ability->can_grant_priority_boost( $this->input() ) );
		$first = $this->ability->grant_priority_boost( $this->input() );

		// A retry arrives with separately verified transport claims.
		$this->ability->verified_service_authority = true;
		$this->assertTrue( $this->ability->can_grant_priority_boost( $this->input() ) );
		$second = $this->ability->grant_priority_boost( $this->input() );

		$this->assertFalse( $first['replayed'] );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( $first['receipt'], $second['receipt'] );
		$this->assertSame( 1, $this->ability->priority_writes );
	}

	/** Events registers one exact target operation with no embedded secret. */
	public function test_builds_exact_target_grant_from_product_configuration(): void {
		$grant = extrachill_events_priority_boost_build_target_grant(
			array(
				'source_site_id' => 3,
				'target_site_id' => 7,
				'target_host'    => 'EVENTS.EXTRACHILL.COM',
				'keys'           => array( 'current' => str_repeat( 's', 32 ) ),
			)
		);

		$this->assertSame( EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ID, $grant['service_id'] );
		$this->assertSame( EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_SCOPE, $grant['scope'] );
		$this->assertSame( 'POST', $grant['method'] );
		$this->assertSame( EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ROUTE, $grant['route'] );
		$this->assertSame( 'events.extrachill.com', $grant['target_host'] );
		$this->assertArrayNotHasKey( 'active_key_id', $grant );
	}

	/** Missing key configuration keeps target service authority disabled. */
	public function test_incomplete_target_grant_fails_closed(): void {
		$this->assertNull(
			extrachill_events_priority_boost_build_target_grant(
				array(
					'source_site_id' => 3,
					'target_site_id' => 7,
					'target_host'    => 'events.extrachill.com',
					'keys'           => array(),
				)
			)
		);
	}

	/** Only exact Network-verified product claims match the target grant. */
	public function test_rejects_mismatched_or_missing_verified_claims(): void {
		$grant  = $this->target_grant();
		$claims = array_intersect_key( $grant, array_flip( array( 'service_id', 'scope', 'source_site_id', 'target_site_id', 'target_host' ) ) );

		$this->assertTrue( extrachill_events_priority_boost_service_claims_match( $claims, $grant ) );

		foreach ( array( 'service_id', 'scope', 'source_site_id', 'target_site_id', 'target_host' ) as $field ) {
			$mismatched           = $claims;
			$mismatched[ $field ] = 'wrong';
			$this->assertFalse( extrachill_events_priority_boost_service_claims_match( $mismatched, $grant ), $field );
		}

		$this->assertFalse( extrachill_events_priority_boost_service_claims_match( array(), $grant ) );
	}

	/** Target policy binds verified claims to the exact ability request. */
	public function test_target_policy_requires_exact_route_method_and_verified_context(): void {
		$grant  = $this->target_grant();
		$claims = array_intersect_key( $grant, array_flip( array( 'service_id', 'scope', 'source_site_id', 'target_site_id', 'target_host' ) ) );

		$this->assertTrue( extrachill_events_priority_boost_service_request_is_authorized( 'POST', EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ROUTE, $claims, $grant ) );
		$this->assertFalse( extrachill_events_priority_boost_service_request_is_authorized( 'GET', EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ROUTE, $claims, $grant ) );
		$this->assertFalse( extrachill_events_priority_boost_service_request_is_authorized( 'POST', '/wp-abilities/v1/abilities/extrachill/other/run', $claims, $grant ) );
		$this->assertFalse( extrachill_events_priority_boost_service_request_is_authorized( 'POST', EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ROUTE, array(), $grant ) );
	}

	/** Invalid transport assertions never become Events service authority. */
	public function test_transport_rejections_remain_untrusted_at_integration_boundary(): void {
		$this->ability->actor_id   = 0;
		$this->ability->can_manage = false;

		foreach ( array( 'forged', 'expired', 'replayed', 'wrong-route', 'wrong-method', 'wrong-body' ) as $transport_failure ) {
			$this->ability->verified_service_authority = false;
			$result                                    = $this->ability->can_grant_priority_boost( $this->input() );
			$this->assertInstanceOf( WP_Error::class, $result, $transport_failure );
			$this->assertSame( 'priority_boost_authentication_required', $result->get_error_code(), $transport_failure );
		}
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

	/** Return one complete target grant fixture. */
	private function target_grant(): array {
		return extrachill_events_priority_boost_build_target_grant(
			array(
				'source_site_id' => 3,
				'target_site_id' => 7,
				'target_host'    => 'events.extrachill.com',
				'keys'           => array( 'current' => str_repeat( 's', 32 ) ),
			)
		);
	}
}
