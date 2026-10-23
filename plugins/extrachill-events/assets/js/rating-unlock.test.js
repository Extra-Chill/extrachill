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
} );
