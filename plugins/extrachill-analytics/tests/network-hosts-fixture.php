<?php
/**
 * extrachill-network redirect-host helper fixture for the isolated analytics harness.
 *
 * @package ExtraChill\Analytics
 */

if ( ! function_exists( 'ec_get_allowed_redirect_hosts' ) ) {
	/**
	 * Mirror extrachill-network's helper, which is not loaded in this suite.
	 *
	 * @return string[] Network hosts.
	 */
	function ec_get_allowed_redirect_hosts() {
		return apply_filters( 'ec_allowed_redirect_hosts', array() );
	}
}
