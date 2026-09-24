/* global jest, describe, beforeEach, afterEach, test, expect */

/**
 * External dependencies
 */
import { act } from 'react';
import { createRoot } from 'react-dom/client';

/**
 * Internal dependencies
 */
import AttendanceButton from './AttendanceButton';
import useMarkAttendance from './useMarkAttendance';

jest.mock( './useMarkAttendance', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@wordpress/element', () => require( 'react' ) );

const props = {
	eventId: 123,
	blogId: 7,
	isLoggedIn: true,
	initialMarked: false,
	initialCount: { label: '' },
	labelDefault: 'Going',
	labelActive: 'Going',
	loginUrl: '/login/',
	redirectTo: '',
	pendingIntent: true,
	intentToken: '',
};

/** Deferred promise helper for controlling exactly when mark() resolves. */
function deferred() {
	let resolve;
	const promise = new Promise( ( res ) => {
		resolve = res;
	} );
	return { promise, resolve };
}

describe( 'AttendanceButton continuation', () => {
	let container;
	let root;

	beforeEach( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
		window.history.replaceState(
			{},
			'',
			'/events/test/?ec_attendance_intent=signed'
		);
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
		jest.resetAllMocks();
	} );

	test( 'resumes with the idempotent marked state and reconciles server truth', async () => {
		const mark = jest.fn().mockResolvedValue( {
			marked: true,
			count: 2,
			count_label: '2 going',
		} );
		useMarkAttendance.mockReturnValue( {
			mark,
			isMarking: false,
			error: null,
		} );

		await act( async () => {
			root.render( <AttendanceButton { ...props } /> );
			await Promise.resolve();
		} );

		expect( mark ).toHaveBeenCalledTimes( 1 );
		expect( mark ).toHaveBeenCalledWith( {
			eventId: 123,
			blogId: 7,
			marked: true,
		} );
		expect(
			container.querySelector( 'button' ).getAttribute( 'aria-pressed' )
		).toBe( 'true' );
		expect(
			container.querySelector( '.ec-attendance__count' ).textContent
		).toBe( '2 going' );
		expect( container.querySelector( '[role="status"]' ).textContent ).toBe(
			'Attendance saved.'
		);
		expect( window.location.search ).toBe( '' );
		expect( document.activeElement ).toBe(
			container.querySelector( 'button' )
		);
	} );

	test( 'dispatches ec:attendance-changed once the resumed intent resolves', async () => {
		const mark = jest.fn().mockResolvedValue( {
			marked: true,
			count: 2,
			count_label: '2 going',
		} );
		useMarkAttendance.mockReturnValue( {
			mark,
			isMarking: false,
			error: null,
		} );

		const listener = jest.fn();
		document.addEventListener( 'ec:attendance-changed', listener );

		await act( async () => {
			root.render( <AttendanceButton { ...props } /> );
			await Promise.resolve();
		} );

		document.removeEventListener( 'ec:attendance-changed', listener );

		expect( listener ).toHaveBeenCalledTimes( 1 );
		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
			eventId: 123,
			blogId: 7,
			marked: true,
		} );
	} );
} );

describe( 'AttendanceButton click', () => {
	let container;
	let root;

	beforeEach( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
		window.history.replaceState( {}, '', '/events/test/' );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
		jest.resetAllMocks();
	} );

	const clickProps = { ...props, pendingIntent: false };

	test( 'dispatches ec:attendance-changed with the server-reconciled marked state after a click', async () => {
		const mark = jest.fn().mockResolvedValue( {
			marked: true,
			count: 1,
			count_label: '1 going',
		} );
		useMarkAttendance.mockReturnValue( {
			mark,
			isMarking: false,
			error: null,
		} );

		const listener = jest.fn();
		document.addEventListener( 'ec:attendance-changed', listener );

		await act( async () => {
			root.render( <AttendanceButton { ...clickProps } /> );
		} );

		await act( async () => {
			container.querySelector( 'button' ).click();
			await Promise.resolve();
		} );

		document.removeEventListener( 'ec:attendance-changed', listener );

		expect( listener ).toHaveBeenCalledTimes( 1 );
		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
			eventId: 123,
			blogId: 7,
			marked: true,
		} );
	} );

	test( 'does not dispatch until a slow mark request actually resolves — the exact bar-wifi scenario', async () => {
		const { promise, resolve } = deferred();
		const mark = jest.fn().mockReturnValue( promise );
		useMarkAttendance.mockReturnValue( {
			mark,
			isMarking: false,
			error: null,
		} );

		const listener = jest.fn();
		document.addEventListener( 'ec:attendance-changed', listener );

		await act( async () => {
			root.render( <AttendanceButton { ...clickProps } /> );
		} );

		act( () => {
			container.querySelector( 'button' ).click();
		} );

		// The click fired the request but it has not resolved yet — no
		// event should have been dispatched. This is the guarantee that
		// replaces the old fixed 900ms/1500ms timing guess: correctness
		// does not depend on how long the request takes.
		expect( listener ).not.toHaveBeenCalled();

		await act( async () => {
			resolve( { marked: true, count: 1, count_label: '1 going' } );
			await promise;
			await Promise.resolve();
		} );

		document.removeEventListener( 'ec:attendance-changed', listener );

		expect( listener ).toHaveBeenCalledTimes( 1 );
		expect( listener.mock.calls[ 0 ][ 0 ].detail.marked ).toBe( true );
	} );

	test( 'carries marked: false on an unmark', async () => {
		const mark = jest.fn().mockResolvedValue( {
			marked: false,
			count: 0,
			count_label: '',
		} );
		useMarkAttendance.mockReturnValue( {
			mark,
			isMarking: false,
			error: null,
		} );

		const listener = jest.fn();
		document.addEventListener( 'ec:attendance-changed', listener );

		await act( async () => {
			root.render( <AttendanceButton { ...clickProps } initialMarked /> );
		} );

		await act( async () => {
			container.querySelector( 'button' ).click();
			await Promise.resolve();
		} );

		document.removeEventListener( 'ec:attendance-changed', listener );

		expect( listener.mock.calls[ 0 ][ 0 ].detail.marked ).toBe( false );
	} );

	test( 'does not dispatch when the request fails', async () => {
		const mark = jest.fn().mockRejectedValue( new Error( 'network' ) );
		useMarkAttendance.mockReturnValue( {
			mark,
			isMarking: false,
			error: null,
		} );

		const listener = jest.fn();
		document.addEventListener( 'ec:attendance-changed', listener );

		await act( async () => {
			root.render( <AttendanceButton { ...clickProps } /> );
		} );

		await act( async () => {
			container.querySelector( 'button' ).click();
			await Promise.resolve();
		} );

		document.removeEventListener( 'ec:attendance-changed', listener );

		expect( listener ).not.toHaveBeenCalled();
	} );
} );
