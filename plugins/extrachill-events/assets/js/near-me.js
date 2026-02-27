/**
 * Near Me Page Geolocation
 *
 * Requests browser geolocation on the /near-me/ page and reloads with
 * lat/lng params so server-side filters can scope the map and calendar
 * blocks to nearby venues.
 *
 * @package ExtraChillEvents
 * @since 0.7.0
 */
(function () {
	'use strict';

	if ( typeof ecNearMe === 'undefined' ) {
		return;
	}

	// Already have location in URL — nothing to do.
	if ( ecNearMe.hasLocation ) {
		return;
	}

	// No Geolocation API — bail silently, fallback content is shown.
	if ( ! navigator.geolocation ) {
		return;
	}

	// Request location.
	navigator.geolocation.getCurrentPosition(
		function ( position ) {
			var lat = position.coords.latitude.toFixed( 6 );
			var lng = position.coords.longitude.toFixed( 6 );

			// Build URL with geo params.
			var url = new URL( ecNearMe.pageUrl );
			url.searchParams.set( 'lat', lat );
			url.searchParams.set( 'lng', lng );
			url.searchParams.set( 'radius', ecNearMe.radius );

			// Reload with location.
			window.location.href = url.toString();
		},
		function ( error ) {
			// Geolocation denied or failed — fallback content already visible.
			// Optionally show a message.
			var cta = document.querySelector( '.near-me-cta' );
			if ( cta && error.code === error.PERMISSION_DENIED ) {
				cta.textContent = 'Location access denied. Browse by city below, or check your browser settings.';
			}
		},
		{
			enableHighAccuracy: false,
			timeout: 10000,
			maximumAge: 300000, // Cache for 5 minutes.
		}
	);
})();
