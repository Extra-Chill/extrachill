/**
 * Join flow: land new musicians on the Register tab.
 *
 * Visitors from extrachill.link/join arrive on /login/?from_join=true. They
 * came to create an account, so open the Register tab directly through the
 * URL hash the login/register block already listens for. Returning users
 * still have the Login tab one click away, and a join-flow registration with
 * an existing email is nudged to log in server-side
 * (ec_join_flow_existing_account_nudge).
 *
 * Replaces a "Do you already have an Extra Chill Community account?" modal
 * whose buttons dispatched an event nothing listened for (#244).
 *
 * @package ExtraChillArtistPlatform
 */
( function () {
	'use strict';

	var params = new URLSearchParams( window.location.search );
	if ( 'true' !== params.get( 'from_join' ) ) {
		return;
	}
	if ( '' === window.location.hash || '#' === window.location.hash ) {
		// replace() fires hashchange without adding a history entry.
		window.location.replace( '#tab-register' );
	}
} )();
