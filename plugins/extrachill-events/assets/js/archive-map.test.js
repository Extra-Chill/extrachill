/**
 * Archive map persistence coverage (#847).
 */
/* global afterEach, beforeEach, describe, expect, it, jest */

const STORAGE_KEY = 'ecEventsArchiveMapOpen';

function renderMap( { disabled = true, expanded = false } = {} ) {
	document.body.innerHTML = `
		<div class="data-machine-events-map-collapsible">
			<button type="button" class="data-machine-events-map-toggle"
				aria-expanded="${ expanded ? 'true' : 'false' }"
				${ disabled ? 'disabled' : '' }>Show map</button>
			<div class="data-machine-events-map-region" hidden></div>
		</div>`;
}

function blockToggleHandler( toggle ) {
	/* Stands in for the block's own target-phase handler, which flips
	 * aria-expanded before the persistence script's document listener runs. */
	return () => {
		if ( toggle.disabled ) {
			return;
		}
		const expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
		toggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
	};
}

function loadArchiveMap() {
	jest.isolateModules( () => require( './archive-map' ) );
}

describe( 'Archive map persistence', () => {
	beforeEach( () => {
		window.localStorage.clear();
	} );

	afterEach( () => {
		jest.useRealTimers();
		jest.clearAllMocks();
	} );

	it( 'stores the reader choice when the map toggle is used', () => {
		renderMap( { disabled: false } );
		const toggle = document.querySelector(
			'.data-machine-events-map-toggle'
		);
		toggle.addEventListener( 'click', blockToggleHandler( toggle ) );

		loadArchiveMap();

		toggle.click();
		expect( window.localStorage.getItem( STORAGE_KEY ) ).toBe( '1' );

		toggle.click();
		expect( window.localStorage.getItem( STORAGE_KEY ) ).toBe( '0' );
	} );

	it( 're-opens a persisted-open map once the block enables its toggle', () => {
		jest.useFakeTimers();
		renderMap( { disabled: false } );
		const toggle = document.querySelector(
			'.data-machine-events-map-toggle'
		);
		toggle.addEventListener( 'click', blockToggleHandler( toggle ) );
		window.localStorage.setItem( STORAGE_KEY, '1' );

		// First poll tick is synchronous: the toggle is already enabled.
		loadArchiveMap();
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
	} );

	it( 'waits for the block to enable the toggle before expanding', () => {
		jest.useFakeTimers();
		renderMap();
		const toggle = document.querySelector(
			'.data-machine-events-map-toggle'
		);
		toggle.addEventListener( 'click', blockToggleHandler( toggle ) );
		window.localStorage.setItem( STORAGE_KEY, '1' );

		loadArchiveMap();
		jest.advanceTimersByTime( 2000 );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );

		toggle.disabled = false;
		jest.advanceTimersByTime( 100 );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
	} );

	it( 'gives up without error when the map never renders', () => {
		jest.useFakeTimers();
		document.body.innerHTML = '';
		window.localStorage.setItem( STORAGE_KEY, '1' );

		loadArchiveMap();

		expect( () => jest.advanceTimersByTime( 6000 ) ).not.toThrow();
	} );

	it( 'leaves collapsed maps alone when the persisted choice is closed', () => {
		jest.useFakeTimers();
		renderMap();
		const toggle = document.querySelector(
			'.data-machine-events-map-toggle'
		);

		loadArchiveMap();
		jest.advanceTimersByTime( 6000 );

		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( window.localStorage.getItem( STORAGE_KEY ) ).toBeNull();
	} );
} );
