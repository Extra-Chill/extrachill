/**
 * Near Me location-state coverage.
 */
/* global afterEach, beforeEach, describe, expect, it, jest */

const fallbackMessage =
	"We couldn't determine your location. Choose a city or search an area.";
const timeoutMessage =
	"We found you, but couldn't load nearby shows. Pick a city below or try again.";

function renderNearMe() {
	document.body.innerHTML = `
		<div class="near-me-detect">
			<div class="near-me-loading" role="status" aria-live="polite">
				<div class="near-me-spinner"></div>
				<p class="near-me-status">Detecting your location...</p>
			</div>
		</div>
		<div class="near-me-cities"><h2>Browse by City</h2></div>
		<div class="near-me-results is-location-pending">
			<div class="data-machine-events-map-root" data-initialized="1"></div>
			<div class="data-machine-events-calendar">Worldwide events</div>
		</div>`;
}

function loadNearMe( geolocation, extraConfig = {} ) {
	Object.defineProperty( navigator, 'geolocation', {
		configurable: true,
		value: geolocation,
	} );
	global.ecNearMe = {
		hasLocation: false,
		hasAccountMarket: false,
		pageUrl: 'http://localhost/near-me/',
		...extraConfig,
	};
	jest.isolateModules( () => require( './near-me' ) );
}

function geolocate( latitude = 32.7765, longitude = -79.9311 ) {
	return {
		getCurrentPosition: ( success ) =>
			success( { coords: { latitude, longitude } } ),
	};
}

describe( 'Near Me location states', () => {
	beforeEach( () => {
		renderNearMe();
		window.history.replaceState( {}, '', '/near-me/' );
	} );

	it.each( [ 1, 2, 3 ] )(
		'shows the accessible fallback for geolocation error code %s',
		( code ) => {
			loadNearMe( {
				getCurrentPosition: ( success, error ) =>
					error( {
						code,
						PERMISSION_DENIED: 1,
						POSITION_UNAVAILABLE: 2,
						TIMEOUT: 3,
					} ),
			} );

			expect(
				document.querySelector( '.near-me-status' ).textContent
			).toBe( fallbackMessage );
			expect(
				document
					.querySelector( '.near-me-loading' )
					.getAttribute( 'role' )
			).toBe( 'status' );
			expect(
				document
					.querySelector( '.near-me-results' )
					.classList.contains( 'is-location-pending' )
			).toBe( true );
		}
	);

	it( 'handles browsers without geolocation', () => {
		loadNearMe( undefined );

		expect( document.querySelector( '.near-me-status' ).textContent ).toBe(
			fallbackMessage
		);
		expect(
			document.querySelector( '.near-me-cities' ).style.display
		).toBe( 'block' );
	} );

	it( 'reveals results only after a successful scoped calendar update', () => {
		document.addEventListener(
			'data-machine-map-recenter',
			( event ) => {
				expect( event.detail.authority ).toBe( 'user-location' );
				document.dispatchEvent(
					new CustomEvent( 'data-machine-map-bounds-changed', {
						detail: { authority: 'user-location' },
					} )
				);
			},
			{ once: true }
		);

		loadNearMe( {
			getCurrentPosition: ( success ) =>
				success( {
					coords: { latitude: 32.7765, longitude: -79.9311 },
				} ),
		} );

		const results = document.querySelector( '.near-me-results' );
		expect( results.classList.contains( 'is-location-pending' ) ).toBe(
			true
		);
		document
			.querySelector( '.data-machine-events-calendar' )
			.dispatchEvent(
				new CustomEvent( 'data-machine-calendar-content-updated' )
			);

		expect( results.classList.contains( 'is-location-pending' ) ).toBe(
			false
		);
		expect(
			document.querySelector( '.near-me-cities' ).style.display
		).toBe( 'none' );
		expect(
			document.querySelector( '.near-me-detect' ).style.display
		).toBe( 'none' );
	} );

	it( 'accepts an explicit area search after geolocation fails', () => {
		loadNearMe( {
			getCurrentPosition: ( success, error ) =>
				error( { code: 1, PERMISSION_DENIED: 1 } ),
		} );

		document.dispatchEvent(
			new CustomEvent( 'data-machine-map-bounds-changed', {
				detail: { authority: 'manual-search' },
			} )
		);
		expect( document.querySelector( '.near-me-status' ).textContent ).toBe(
			'Loading events for that area...'
		);

		document
			.querySelector( '.data-machine-events-calendar' )
			.dispatchEvent(
				new CustomEvent( 'data-machine-calendar-content-updated' )
			);
		expect(
			document
				.querySelector( '.near-me-results' )
				.classList.contains( 'is-location-pending' )
		).toBe( false );
	} );

	describe( 'scoped results timeout', () => {
		let recenter;
		let setUserLocation;

		beforeEach( () => {
			recenter = jest.fn();
			setUserLocation = jest.fn();
			document.addEventListener( 'data-machine-map-recenter', recenter );
			document.addEventListener(
				'data-machine-map-set-user-location',
				setUserLocation
			);
		} );

		afterEach( () => {
			jest.useRealTimers();
			document.removeEventListener(
				'data-machine-map-recenter',
				recenter
			);
			document.removeEventListener(
				'data-machine-map-set-user-location',
				setUserLocation
			);
		} );

		it( 'falls back to the city grid with a retry link when scoped results never arrive', () => {
			jest.useFakeTimers();
			loadNearMe( geolocate() );

			jest.advanceTimersByTime( 9999 );
			expect(
				document.querySelector( '.near-me-status' ).textContent
			).toBe( 'Found you! Loading nearby events...' );

			jest.advanceTimersByTime( 1 );
			expect(
				document.querySelector( '.near-me-status' ).textContent
			).toBe( timeoutMessage );
			expect(
				document.querySelector( '.near-me-spinner' ).style.display
			).toBe( 'none' );
			expect(
				document.querySelector( '.near-me-cities' ).style.display
			).toBe( 'block' );
			expect(
				document
					.querySelector( '.near-me-results' )
					.classList.contains( 'is-location-pending' )
			).toBe( true );
			expect(
				document
					.querySelector( '.near-me-retry' )
					?.getAttribute( 'href' )
			).toBe( 'http://localhost/near-me/?lat=32.776500&lng=-79.931100' );
		} );

		it( 'honors ecNearMe.scopedResultsTimeoutMs when provided', () => {
			jest.useFakeTimers();
			loadNearMe( geolocate(), { scopedResultsTimeoutMs: 5000 } );

			jest.advanceTimersByTime( 4999 );
			expect(
				document.querySelector( '.near-me-status' ).textContent
			).not.toBe( timeoutMessage );

			jest.advanceTimersByTime( 1 );
			expect(
				document.querySelector( '.near-me-status' ).textContent
			).toBe( timeoutMessage );
		} );

		it( 'clears the timer and reveals results when scoped content updates before the timeout', () => {
			jest.useFakeTimers();
			loadNearMe( geolocate() );

			document
				.querySelector( '.data-machine-events-calendar' )
				.dispatchEvent(
					new CustomEvent( 'data-machine-calendar-content-updated' )
				);

			jest.advanceTimersByTime( 60000 );
			expect(
				document.querySelector( '.near-me-status' ).textContent
			).not.toBe( timeoutMessage );
			expect(
				document
					.querySelector( '.near-me-results' )
					.classList.contains( 'is-location-pending' )
			).toBe( false );
			expect(
				document.querySelector( '.near-me-cities' ).style.display
			).toBe( 'none' );
		} );

		it( 'waits for data-machine-map-ready before recentering an uninitialized map', () => {
			document
				.querySelector( '.data-machine-events-map-root' )
				.setAttribute( 'data-initialized', '0' );
			loadNearMe( geolocate() );

			expect( recenter ).not.toHaveBeenCalled();
			expect( setUserLocation ).not.toHaveBeenCalled();

			document
				.querySelector( '.data-machine-events-map-root' )
				.dispatchEvent(
					new CustomEvent( 'data-machine-map-ready', {
						bubbles: true,
					} )
				);

			expect( recenter ).toHaveBeenCalledTimes( 1 );
			expect( recenter.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
				lat: 32.7765,
				lng: -79.9311,
				zoom: 12,
				authority: 'user-location',
			} );
			expect( setUserLocation ).toHaveBeenCalledWith(
				expect.objectContaining( {
					detail: { lat: 32.7765, lng: -79.9311 },
				} )
			);
		} );

		it( 'still falls back when an uninitialized map never becomes ready', () => {
			jest.useFakeTimers();
			document
				.querySelector( '.data-machine-events-map-root' )
				.setAttribute( 'data-initialized', '0' );
			loadNearMe( geolocate() );

			expect( recenter ).not.toHaveBeenCalled();

			jest.advanceTimersByTime( 10000 );
			expect(
				document.querySelector( '.near-me-status' ).textContent
			).toBe( timeoutMessage );
			expect(
				document.querySelector( '.near-me-cities' ).style.display
			).toBe( 'block' );
			// Data attributes are still set so an initialized map picks up
			// the center on init.
			expect(
				document.querySelector( '.data-machine-events-map-root' )
					.dataset.centerLat
			).toBe( '32.776500' );
		} );
	} );
} );
