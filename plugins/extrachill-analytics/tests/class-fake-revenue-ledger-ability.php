<?php
/**
 * Ability double for the in-memory revenue ledger used in analytics tests.
 *
 * @package ExtraChill\Analytics
 */

/**
 * Minimal ability double wrapping a callable.
 */
final class Fake_Revenue_Ledger_Ability {

	/**
	 * Callable executed for execute().
	 *
	 * @var callable
	 */
	private $callback;

	/**
	 * Constructor.
	 *
	 * @param callable $callback Execute callback.
	 */
	public function __construct( callable $callback ) {
		$this->callback = $callback;
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function execute( $input = array() ) {
		return call_user_func( $this->callback, is_array( $input ) ? $input : array() );
	}
}
