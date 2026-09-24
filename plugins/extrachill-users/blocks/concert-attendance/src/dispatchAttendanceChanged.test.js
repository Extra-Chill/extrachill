/* global describe, test, expect, jest, afterEach */

/**
 * Internal dependencies
 */
import dispatchAttendanceChanged from './dispatchAttendanceChanged';

describe( 'dispatchAttendanceChanged', () => {
	afterEach( () => {
		jest.restoreAllMocks();
	} );

	test( 'dispatches ec:attendance-changed on document with the exact detail shape', () => {
		const listener = jest.fn();
		document.addEventListener( 'ec:attendance-changed', listener );

		dispatchAttendanceChanged( { eventId: 42, blogId: 7, marked: true } );

		expect( listener ).toHaveBeenCalledTimes( 1 );
		const event = listener.mock.calls[ 0 ][ 0 ];
		expect( event.type ).toBe( 'ec:attendance-changed' );
		expect( event.detail ).toEqual( {
			eventId: 42,
			blogId: 7,
			marked: true,
		} );

		document.removeEventListener( 'ec:attendance-changed', listener );
	} );

	test( 'carries marked: false for an unmark', () => {
		const listener = jest.fn();
		document.addEventListener( 'ec:attendance-changed', listener );

		dispatchAttendanceChanged( { eventId: 42, blogId: 7, marked: false } );

		expect( listener.mock.calls[ 0 ][ 0 ].detail.marked ).toBe( false );

		document.removeEventListener( 'ec:attendance-changed', listener );
	} );

	test( 'is a real DOM CustomEvent — generic, carries no perk/pass concept', () => {
		const listener = jest.fn();
		document.addEventListener( 'ec:attendance-changed', listener );

		dispatchAttendanceChanged( { eventId: 1, blogId: 1, marked: true } );

		const event = listener.mock.calls[ 0 ][ 0 ];
		expect( event instanceof CustomEvent ).toBe( true );
		expect( Object.keys( event.detail ).sort() ).toEqual( [
			'blogId',
			'eventId',
			'marked',
		] );

		document.removeEventListener( 'ec:attendance-changed', listener );
	} );
} );
