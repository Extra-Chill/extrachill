/**
 * Near Me Page Geolocation
 *
 * Detects user location via browser Geolocation API. Shows a loading
 * state while detecting, then redirects with lat/lng params so the
 * server renders the map and calendar with geo-filtered results.
 *
 * If geolocation is denied or unavailable, reveals the city grid fallback.
 *
 * @package ExtraChillEvents
 * @since 0.7.0
 */
(function () {
	'use strict';

	if ( typeof ecNearMe === 'undefined' ) {
		return;
	}

	var detect  = document.querySelector( '.near-me-detect' );
	var loading = document.querySelector( '.near-me-loading' );
	var cities  = document.querySelector( '.near-me-cities' );
	var status  = document.querySelector( '.near-me-status' );

	// Already have location in URL — page is server-rendered with results.
	if ( ecNearMe.hasLocation ) {
		if ( detect ) { detect.style.display = 'none'; }
		initRadiusSelect();
		return;
	}

	// No Geolocation API — show fallback immediately.
	if ( ! navigator.geolocation ) {
		showFallback( 'Your browser does not support location detection. Browse by city below.' );
		return;
	}

	// Show loading state.
	if ( loading ) { loading.style.display = 'flex'; }
	if ( cities )  { cities.style.display = 'none'; }

	// Request location.
	navigator.geolocation.getCurrentPosition( onSuccess, onError, {
		enableHighAccuracy: true,
		timeout: 10000,
		maximumAge: 300000,
	} );

	function onSuccess( position ) {
		var lat = position.coords.latitude.toFixed( 6 );
		var lng = position.coords.longitude.toFixed( 6 );

		// Update status text.
		if ( status ) {
			status.textContent = 'Found you! Loading nearby events...';
		}

		// Navigate with geo params — server renders map + calendar.
		var url = new URL( ecNearMe.pageUrl );
		url.searchParams.set( 'lat', lat );
		url.searchParams.set( 'lng', lng );
		url.searchParams.set( 'radius', ecNearMe.radius );
		window.location.href = url.toString();
	}

	function onError( error ) {
		var msg;
		switch ( error.code ) {
			case error.PERMISSION_DENIED:
				msg = 'Location access denied. Browse by city below, or check your browser settings.';
				break;
			case error.POSITION_UNAVAILABLE:
				msg = 'Could not determine your location. Browse by city below.';
				break;
			case error.TIMEOUT:
				msg = 'Location request timed out. Browse by city below.';
				break;
			default:
				msg = 'Could not detect your location. Browse by city below.';
		}
		showFallback( msg );
	}

	function showFallback( msg ) {
		if ( loading ) { loading.style.display = 'none'; }
		if ( status )  { status.textContent = msg; status.style.display = 'block'; }
		if ( cities )  { cities.style.display = 'block'; }
	}

	function initRadiusSelect() {
		var select = document.querySelector( '.near-me-radius-select' );
		if ( ! select ) { return; }

		select.addEventListener( 'change', function () {
			var url = new URL( select.getAttribute( 'data-url' ) );
			url.searchParams.set( 'lat', select.getAttribute( 'data-lat' ) );
			url.searchParams.set( 'lng', select.getAttribute( 'data-lng' ) );
			url.searchParams.set( 'radius', select.value );
			window.location.href = url.toString();
		} );
	}

})();
