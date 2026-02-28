/**
 * Near Me Page — Reactive Geolocation
 *
 * Detects user location via browser Geolocation API. On success, updates
 * the map center and lets the dynamic map + geo-sync handle the rest:
 * - Map fetches venues from REST API based on viewport
 * - Map fires datamachine-map-bounds-changed
 * - Calendar geo-sync catches it and re-fetches events
 * - URL updates via History API (shareable)
 *
 * No page reloads. The map viewport IS the radius.
 *
 * If geolocation is denied or unavailable, reveals the city grid fallback.
 *
 * @package ExtraChillEvents
 * @since 0.8.0
 */
( function () {
	'use strict';

	if ( typeof ecNearMe === 'undefined' ) {
		return;
	}

	var detect  = document.querySelector( '.near-me-detect' );
	var loading = document.querySelector( '.near-me-loading' );
	var cities  = document.querySelector( '.near-me-cities' );
	var status  = document.querySelector( '.near-me-status' );

	// Already have location in URL — map renders with server-side center,
	// dynamic mode fetches venues, geo-sync updates calendar.
	if ( ecNearMe.hasLocation ) {
		if ( detect ) {
			detect.style.display = 'none';
		}
		return;
	}

	// No Geolocation API — show fallback immediately.
	if ( ! navigator.geolocation ) {
		showFallback( 'Your browser does not support location detection. Browse by city below.' );
		return;
	}

	// Show loading state.
	if ( loading ) {
		loading.style.display = 'flex';
	}
	if ( cities ) {
		cities.style.display = 'none';
	}

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

		// Update URL via History API — no page reload.
		var url = new URL( ecNearMe.pageUrl );
		url.searchParams.set( 'lat', lat );
		url.searchParams.set( 'lng', lng );
		window.history.replaceState( {}, '', url.toString() );

		// Set the map center by updating data attributes on the map root.
		// The map React component reads these on init. If the map has already
		// initialized, we dispatch a custom event to recenter it.
		var mapRoot = document.querySelector( '.datamachine-events-map-root' );
		if ( mapRoot ) {
			mapRoot.dataset.centerLat = lat;
			mapRoot.dataset.centerLon = lng;
			mapRoot.dataset.userLat   = lat;
			mapRoot.dataset.userLon   = lng;

			// If map is already initialized, dispatch recenter event.
			if ( mapRoot.dataset.initialized === '1' ) {
				document.dispatchEvent( new CustomEvent( 'datamachine-map-recenter', {
					detail: {
						lat: parseFloat( lat ),
						lng: parseFloat( lng ),
						zoom: 12,
					},
				} ) );
			}
		}

		// Hide the detection UI — map and calendar are now loading.
		hideDetectUI();
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

	function hideDetectUI() {
		if ( detect ) {
			detect.style.display = 'none';
		}
	}

	function showFallback( msg ) {
		if ( loading ) {
			loading.style.display = 'none';
		}
		if ( status ) {
			status.textContent = msg;
			status.style.display = 'block';
		}
		if ( cities ) {
			cities.style.display = 'block';
		}
	}
} )();
