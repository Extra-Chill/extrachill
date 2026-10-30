/**
 * "I Was There" → rating form unlock bridge.
 */
/* global afterEach, beforeEach, describe, expect, it, jest */

describe( 'rating unlock bridge', () => {
	let received;
	const listener = ( event ) => received.push( event.detail );

	beforeEach( () => {
		received = [];
		document.addEventListener( 'dme:rating-eligibility', listener );
		jest.isolateModules( () => require( './rating-unlock' ) );
	} );

	afterEach( () => {
		document.removeEventListener( 'dme:rating-eligibility', listener );
	} );

	function fire( detail ) {
		document.dispatchEvent(
			new CustomEvent( 'ec:attendance-changed', { detail } )
		);
	}

	it( 'unlocks rating for the marked event', () => {
		fire( { eventId: 42, blogId: 7, marked: true } );
		expect( received ).toContainEqual( { eventId: 42, canRate: true } );
	} );

	it( 're-locks rating when attendance is removed', () => {
		fire( { eventId: 42, blogId: 7, marked: false } );
		expect( received ).toContainEqual( { eventId: 42, canRate: false } );
	} );

	it( 'ignores events without an event id', () => {
		fire( { marked: true } );
		expect( received ).toHaveLength( 0 );
	} );

	describe( 'rate this show link', () => {
		function page( { locked } ) {
			document.body.innerHTML =
				'<div id="ec-attendance-root"></div>' +
				'<div id="respond">' +
				( locked ? '<div hidden>' : '<div>' ) +
				'<fieldset class="dme-rating-field"><input type="radio"></fieldset></div></div>';
		}

		afterEach( () => {
			document.body.innerHTML = '';
		} );

		it( 'shows the link once the star picker opens', () => {
			page( { locked: true } );
			jest.isolateModules( () => require( './rating-unlock' ) );
			expect( document.querySelector( '.ec-rate-it-link' ) ).toBeNull();

			document.querySelector( '#respond [hidden]' ).hidden = false;
			fire( { eventId: 42, blogId: 7, marked: true } );
			const link = document.querySelector( '.ec-rate-it-link a' );
			expect( link ).not.toBeNull();
			expect( link.getAttribute( 'href' ) ).toBe( '#respond' );
			expect(
				document.getElementById( 'ec-attendance-root' )
					.nextElementSibling.className
			).toBe( 'ec-rate-it-link' );
		} );

		it( 'shows the link on load when the picker is already open', () => {
			page( { locked: false } );
			jest.isolateModules( () => require( './rating-unlock' ) );
			expect(
				document.querySelectorAll( '.ec-rate-it-link' )
			).toHaveLength( 1 );
		} );

		it( 'removes the link when attendance is removed', () => {
			page( { locked: false } );
			jest.isolateModules( () => require( './rating-unlock' ) );
			document.querySelector( '#respond > div' ).hidden = true;
			fire( { eventId: 42, blogId: 7, marked: false } );
			expect( document.querySelector( '.ec-rate-it-link' ) ).toBeNull();
		} );

		it( 'stays hidden when there is no rating form', () => {
			document.body.innerHTML = '<div id="ec-attendance-root"></div>';
			jest.isolateModules( () => require( './rating-unlock' ) );
			fire( { eventId: 42, blogId: 7, marked: true } );
			expect( document.querySelector( '.ec-rate-it-link' ) ).toBeNull();
		} );
	} );
} );
