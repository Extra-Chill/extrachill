<?php
/**
 * Test dbDelta simulation backed by BookingWpdb schema metadata.
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Simulate WordPress core's schema reconciler.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return array Empty change list.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Matches the WordPress core API under test.
	function dbDelta( $sql ) {
		$GLOBALS['ec_artist_test']['dbdelta'][] = $sql;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'apply_schema' ) ) {
			$GLOBALS['wpdb']->apply_schema( $sql );
		}
		return array();
	}
}
