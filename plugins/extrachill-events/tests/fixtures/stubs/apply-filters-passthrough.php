<?php
/**
 * Pass-through apply_filters() for standalone render fixtures that load no hooks.
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Return the filtered value unchanged.
	 *
	 * @param string $hook  Filter name.
	 * @param mixed  $value Value to filter.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		unset( $hook );
		return $value;
	}
}
