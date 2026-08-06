<?php
/**
 * Priority boost ability test double.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\PriorityEventAbilities;

/** In-memory persistence and event adapter for operation tests. */
final class PriorityBoostAbilityDouble extends PriorityEventAbilities {

	/**
	 * Canonical event fixtures.
	 *
	 * @var array<string,object>
	 */
	public $events = array();

	/**
	 * Priority state by event ID.
	 *
	 * @var array<int,bool>
	 */
	public $priority = array();

	/**
	 * Operation receipts by option name.
	 *
	 * @var array<string,array>
	 */
	public $receipts = array();

	/**
	 * Recorded cache deletions.
	 *
	 * @var array<int,array>
	 */
	public $cache_deletes = array();

	/**
	 * Number of priority writes.
	 *
	 * @var int
	 */
	public $priority_writes = 0;

	/**
	 * Authenticated actor fixture.
	 *
	 * @var int
	 */
	public $actor_id = 7;

	/**
	 * Whether the actor may grant boosts.
	 *
	 * @var bool
	 */
	public $can_manage = true;

	/**
	 * Event dates by event ID.
	 *
	 * @var array<int,string>
	 */
	public $event_dates = array();

	/**
	 * Current site date fixture.
	 *
	 * @var string
	 */
	public $today = '2026-08-06';

	/**
	 * Resolve an event fixture.
	 *
	 * @param string $event_reference Event ID or slug.
	 * @return object|false
	 */
	protected function resolve_priority_boost_event( string $event_reference ) {
		return $this->events[ $event_reference ] ?? false;
	}

	/** Return the fixture actor. */
	protected function get_priority_boost_actor_id(): int {
		return $this->actor_id;
	}

	/**
	 * Return fixture authorization.
	 *
	 * @param int $actor_id Actor user ID.
	 * @return bool
	 */
	protected function priority_boost_actor_can_manage( int $actor_id ): bool {
		unset( $actor_id );
		return $this->can_manage;
	}

	/**
	 * Return fixture event eligibility.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool
	 */
	protected function priority_boost_event_is_eligible( int $post_id ): bool {
		return isset( $this->event_dates[ $post_id ] ) && $this->event_dates[ $post_id ] >= $this->today;
	}

	/**
	 * Read an in-memory receipt.
	 *
	 * @param string $option_name Receipt option name.
	 * @return array|false
	 */
	protected function get_priority_boost_receipt( string $option_name ) {
		return $this->receipts[ $option_name ] ?? false;
	}

	/**
	 * Reserve an in-memory receipt.
	 *
	 * @param string $option_name Receipt option name.
	 * @param array  $receipt Receipt payload.
	 * @return bool
	 */
	protected function add_priority_boost_receipt( string $option_name, array $receipt ): bool {
		if ( isset( $this->receipts[ $option_name ] ) ) {
			return false;
		}

		$this->receipts[ $option_name ] = $receipt;
		return true;
	}

	/**
	 * Complete an in-memory receipt.
	 *
	 * @param string $option_name Receipt option name.
	 * @param array  $receipt Receipt payload.
	 * @return bool
	 */
	protected function update_priority_boost_receipt( string $option_name, array $receipt ): bool {
		$this->receipts[ $option_name ] = $receipt;
		return true;
	}

	/**
	 * Read fixture priority.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool
	 */
	protected function is_priority_boosted_event( int $post_id ): bool {
		return ! empty( $this->priority[ $post_id ] );
	}

	/**
	 * Write fixture priority.
	 *
	 * @param int $post_id Event post ID.
	 */
	protected function set_priority_boosted_event( int $post_id ): void {
		$this->priority[ $post_id ] = true;
		++$this->priority_writes;
	}

	/** Record cache invalidation. */
	protected function delete_priority_boost_cache(): void {
		$this->cache_deletes[] = array( 'extrachill_priority_event_ids', 'extrachill-events' );
	}

	/** Return a deterministic timestamp. */
	protected function priority_boost_timestamp(): string {
		return '2026-08-06T12:00:00+00:00';
	}
}
