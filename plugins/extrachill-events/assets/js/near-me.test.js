/**
 * Near Me location-state coverage.
 */
/* global beforeEach, describe, expect, it, jest */

const fallbackMessage =
	"We couldn't determine your location. Choose a city or search an area.";

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

function loadNearMe( geolocation ) {
	Object.defineProperty( navigator, 'geolocation', {
		configurable: true,
		value: geolocation,
	} );
	global.ecNearMe = {
		hasLocation: false,
		hasAccountMarket: false,
		pageUrl: 'http://localhost/near-me/',
	};
	jest.isolateModules( () => require( './near-me' ) );
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
} );
