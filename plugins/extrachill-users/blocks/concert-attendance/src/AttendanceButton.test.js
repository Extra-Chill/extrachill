import { act } from 'react';
import { createRoot } from 'react-dom/client';

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
} );
