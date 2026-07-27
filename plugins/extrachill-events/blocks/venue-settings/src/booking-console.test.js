/* global describe, expect, it, jest */

jest.mock( '@extrachill/components', () => ( {} ) );

/**
 * Internal dependencies
 */
import {
	calendarDays,
	filterBookings,
	monthKey,
	moveMonth,
} from './booking-console';

describe( 'venue booking console state helpers', () => {
	it( 'builds a stable six-week month grid', () => {
		const days = calendarDays( '2026-07' );
		expect( days ).toHaveLength( 42 );
		expect( days[ 0 ] ).toEqual( {
			key: '2026-06-28',
			day: 28,
			inMonth: false,
		} );
		expect( days.filter( ( day ) => day.inMonth ) ).toHaveLength( 31 );
	} );

	it( 'navigates across year boundaries deterministically', () => {
		expect( moveMonth( '2026-01', -1 ) ).toBe( '2025-12' );
		expect( moveMonth( '2026-12', 1 ) ).toBe( '2027-01' );
		expect( monthKey( new Date( 2026, 6, 1 ) ) ).toBe( '2026-07' );
	} );

	it( 'searches only the bounded venue response', () => {
		const bookings = [
			{
				id: 1,
				artist_name: 'Kid Lake',
				contact_email: 'agent@example.com',
				status: 'held',
			},
			{
				id: 2,
				artist_name: 'The Sand Dollars',
				contact_name: 'Chris',
				status: 'submitted',
			},
		];
		expect( filterBookings( bookings, 'agent' ) ).toEqual( [
			bookings[ 0 ],
		] );
		expect( filterBookings( bookings, 'submitted' ) ).toEqual( [
			bookings[ 1 ],
		] );
		expect( filterBookings( bookings, '' ) ).toBe( bookings );
	} );
} );
