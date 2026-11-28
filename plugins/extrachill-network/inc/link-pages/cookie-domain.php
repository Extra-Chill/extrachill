<?php
/** Early cookie-domain selection for the mapped Link Pages host. */

defined( 'ABSPATH' ) || exit;

/** Match only the mapped host, ignoring a request port. */
function ec_network_link_page_cookie_host( string $host ): bool {
	return ec_network_link_page_public_host() === preg_replace( '/:\d+$/D', '', strtolower( $host ) );
}
