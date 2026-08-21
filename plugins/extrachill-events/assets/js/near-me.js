/**
 * Near Me Page — Reactive Geolocation
 *
 * Detects user location via browser Geolocation API. On success, updates
 * the map center and lets the dynamic map + geo-sync handle the rest:
 * - Map fetches venues from REST API based on viewport
 * - Map fires data-machine-map-bounds-changed
 * - Calendar geo-sync catches it and re-fetches events
 * - URL updates via History API (shareable)
 *
 * No page reloads. The map viewport IS the radius.
 *
 * If geolocation is denied or unavailable, reveals the city grid fallback.
 *
 * The location search input is part of the EventsMap block (data-machine layer),
 * enabled on this page via the data_machine_events_map_show_location_search filter.
 *
 * @package
 * @since 0.8.0
 */
/* global ecNearMe */
( function () {
	'use strict';

	if ( typeof ecNearMe === 'undefined' ) {
		return;
	}

	const detect = document.querySelector( '.near-me-detect' );

	// Already have location in URL — map renders with server-side center,
	// dynamic mode fetches venues, geo-sync updates calendar.
	if ( ecNearMe.hasLocation ) {
		if ( detect ) {
			detect.style.display = 'none';
		}
		return;
	}

	const loading = document.querySelector( '.near-me-loading' );
	const spinner = document.querySelector( '.near-me-spinner' );
	const cities = document.querySelector( '.near-me-cities' );
	const status = document.querySelector( '.near-me-status' );
	const results = document.querySelector( '.near-me-results' );
	const calendar = results?.querySelector( '.data-machine-events-calendar' );
	let awaitingScopedResults = false;

	document.addEventListener( 'data-machine-map-bounds-changed', ( event ) => {
		if (
			! results?.classList.contains( 'is-location-pending' ) ||
			! [ 'manual-search', 'user-location' ].includes(
				event.detail?.authority
			)
		) {
			return;
		}

		awaitingScopedResults = true;
		showLoading( 'Loading events for that area...' );
	} );

	calendar?.addEventListener( 'data-machine-calendar-content-updated', () => {
		if ( awaitingScopedResults ) {
			revealScopedResults();
		}
	} );

	// No Geolocation API — show fallback immediately.
	if ( ! navigator.geolocation ) {
		showFallback();
		return;
	}

	// Show loading state.
	if ( loading ) {
		loading.style.display = 'flex';
	}
	// Request location.
	navigator.geolocation.getCurrentPosition( onSuccess, onError, {
		enableHighAccuracy: true,
		timeout: 10000,
		maximumAge: 300000,
	} );

	function onSuccess( position ) {
		const lat = position.coords.latitude.toFixed( 6 );
		const lng = position.coords.longitude.toFixed( 6 );

		// Update status text.
		if ( status ) {
			status.textContent = 'Found you! Loading nearby events...';
		}
		awaitingScopedResults = true;

		// Update URL via History API — no page reload.
		const url = new URL( ecNearMe.pageUrl );
		url.searchParams.set( 'lat', lat );
		url.searchParams.set( 'lng', lng );
		window.history.replaceState( {}, '', url.toString() );

		// Set the map center by updating data attributes on the map root.
		// The map React component reads these on init. If the map has already
		// initialized, we dispatch a custom event to recenter it.
		const mapRoot = document.querySelector(
			'.data-machine-events-map-root'
		);
		if ( mapRoot ) {
			mapRoot.dataset.centerLat = lat;
			mapRoot.dataset.centerLon = lng;
			mapRoot.dataset.userLat = lat;
			mapRoot.dataset.userLon = lng;

			// If map is already initialized, dispatch recenter + user location events.
			if ( mapRoot.dataset.initialized === '1' ) {
				document.dispatchEvent(
					new CustomEvent( 'data-machine-map-recenter', {
						detail: {
							lat: parseFloat( lat ),
							lng: parseFloat( lng ),
							zoom: 12,
							authority: 'user-location',
						},
					} )
				);

				// Add the blue dot marker for user location.
				document.dispatchEvent(
					new CustomEvent( 'data-machine-map-set-user-location', {
						detail: {
							lat: parseFloat( lat ),
							lng: parseFloat( lng ),
						},
					} )
				);
			}
		}

		showLoading( 'Found you! Loading nearby events...' );
	}

	function onError() {
		// The server-rendered calendar/map already use the account market.
		// Keep that result instead of dropping to anonymous city discovery.
		if ( ecNearMe.hasAccountMarket ) {
			hideDetectUI();
			return;
		}

		showFallback();
	}

	function hideDetectUI() {
		if ( detect ) {
			detect.style.display = 'none';
		}

		// The map container's viewport changes when the detect UI hides.
		// Leaflet needs to recalculate its size to enable proper interaction.
		window.dispatchEvent( new Event( 'resize' ) );
	}

	function showLoading( msg ) {
		if ( loading ) {
			loading.style.display = 'flex';
		}
		if ( spinner ) {
			spinner.style.display = 'block';
		}
		if ( status ) {
			status.textContent = msg;
		}
	}

	function showFallback() {
		if ( loading ) {
			loading.style.display = 'flex';
		}
		if ( spinner ) {
			spinner.style.display = 'none';
		}
		if ( status ) {
			status.textContent =
				"We couldn't determine your location. Choose a city or search an area.";
		}
		if ( cities ) {
			cities.style.display = 'block';
		}
	}

	function revealScopedResults() {
		results?.classList.remove( 'is-location-pending' );
		if ( cities ) {
			cities.style.display = 'none';
		}
		hideDetectUI();
	}
} )();
