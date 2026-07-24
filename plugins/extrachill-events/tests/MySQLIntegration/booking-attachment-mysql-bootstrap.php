<?php
/**
 * External feature-flag dependency for the disposable WordPress integration test.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

if ( ! function_exists( 'ec_feature_available' ) ) {
	/**
	 * Enable only the venue-booking feature exercised by this isolated test.
	 *
	 * @param string $feature Feature identifier.
	 * @param int    $user_id User identifier.
	 */
	function ec_feature_available( string $feature, int $user_id ): bool {
		return 'venue_booking' === $feature && $user_id > 0;
	}
}
