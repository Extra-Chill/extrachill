/* global describe, expect, it, jest */

jest.mock( '@extrachill/components', () => ( {} ) );

/**
 * External dependencies
 */
const { TextEncoder } = require( 'node:util' );
const { webcrypto } = require( 'node:crypto' );

/**
 * Internal dependencies
 */
import {
	calendarDays,
	calendarEntries,
	bookingSummary,
	bookingMessageIdentity,
	bookingMessageKey,
	filterBookings,
	monthKey,
	monthRange,
	moveMonth,
	sortBookingsChronologically,
	toVenueLocalInput,
	venueLocalToUtc,
} from './booking-console';
global.TextEncoder = TextEncoder;
Object.defineProperty( global, 'crypto', {
	configurable: true,
	value: webcrypto,
} );

describe( 'venue booking console state helpers', () => {
	it( 'keeps one key for canonical message content across teammates', async () => {
		const message = {
			bookingId: 42,
			recipient: ' Artist@Example.com ',
			subject: 'Offer details',
			message: 'First line\r\nSecond line',
			replyTo: ' Venue@Example.com ',
		};
		const canonical = {
			...message,
			recipient: 'artist@example.com',
			message: 'First line\nSecond line',
			replyTo: 'venue@example.com',
		};

		expect( bookingMessageIdentity( message ) ).toBe(
			bookingMessageIdentity( canonical )
		);
		expect( await bookingMessageKey( message ) ).toBe(
			await bookingMessageKey( canonical )
		);
	} );

	it( 'rotates the key for changed content or a conclusive rejection', async () => {
		const message = {
			bookingId: 42,
			recipient: 'artist@example.com',
			subject: 'Offer details',
			message: 'Original message',
			replyTo: 'venue@example.com',
		};

		expect( await bookingMessageKey( message ) ).not.toBe(
			await bookingMessageKey( {
				...message,
				message: 'Changed message',
			} )
		);
		expect( await bookingMessageKey( message ) ).not.toBe(
			await bookingMessageKey( {
				...message,
				subject: 'Changed subject',
			} )
		);
		expect( await bookingMessageKey( message ) ).not.toBe(
			await bookingMessageKey( message, 1 )
		);
	} );

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
		expect( monthRange( '2026-12' ) ).toEqual( {
			start: '2026-12-01 00:00:00',
			end: '2027-01-01 00:00:00',
		} );
	} );

	it( 'keeps standalone events and unconverted bookings', () => {
		const events = [
			{
				id: 900,
				title: 'Standalone Show',
				datetime: '2026-08-08T19:00:00',
			},
		];
		const bookings = [
			{
				id: 10,
				artist_name: 'Unconverted Artist',
				requested_start_at: '2026-08-09 20:00:00',
				event_id: null,
				status: 'held',
			},
		];

		expect( calendarEntries( bookings, events ) ).toEqual( [
			expect.objectContaining( {
				type: 'event',
				id: 900,
				date: '2026-08-08',
			} ),
			expect.objectContaining( {
				type: 'booking',
				id: 10,
				date: '2026-08-09',
			} ),
		] );
	} );

	it( 'groups canonical UTC bookings on the venue-local calendar date', () => {
		const entries = calendarEntries(
			[
				{
					id: 10,
					artist_name: 'Late Show',
					performance_start_at: '2026-08-02 02:00:00',
					venue_timezone: 'America/New_York',
					status: 'confirmed',
				},
			],
			[]
		);

		expect( entries[ 0 ].date ).toBe( '2026-08-01' );
	} );

	it( 'round trips one instant through venue-local controls', () => {
		expect(
			toVenueLocalInput( '2026-08-21 20:00:00', 'America/New_York' )
		).toBe( '2026-08-21T16:00:00' );
		expect(
			venueLocalToUtc( '2026-08-21T16:00:00', 'America/New_York' )
		).toBe( '2026-08-21 20:00:00' );
	} );

	it( 'rejects DST gaps and repeated venue wall times', () => {
		expect( () =>
			venueLocalToUtc( '2030-03-10T02:30:00', 'America/New_York' )
		).toThrow( 'does not exist' );
		expect( () =>
			venueLocalToUtc( '2030-11-03T01:30:00', 'America/New_York' )
		).toThrow( 'occurs twice' );
	} );

	it( 'deduplicates a converted booking against its canonical event', () => {
		const events = [
			{
				id: 900,
				title: 'Canonical Show',
				datetime: '2026-08-08T19:00:00',
			},
		];
		const bookings = [
			{
				id: 10,
				artist_name: 'Converted Artist',
				requested_start_at: '2026-08-08 19:00:00',
				event_id: 900,
				status: 'confirmed',
			},
		];

		expect( calendarEntries( bookings, events ) ).toHaveLength( 1 );
		expect( calendarEntries( bookings, events )[ 0 ] ).toEqual(
			expect.objectContaining( { type: 'event', id: 900 } )
		);
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

	it( 'sorts the inbox by booking date with a stable id fallback', () => {
		const bookings = [
			{ id: 3, requested_start_at: '2026-08-12 20:00:00' },
			{ id: 1, requested_start_at: '2026-08-04 20:00:00' },
			{ id: 2, created_at: '2026-08-01 12:00:00' },
		];
		expect(
			sortBookingsChronologically( bookings ).map( ( item ) => item.id )
		).toEqual( [ 2, 1, 3 ] );
	} );

	it( 'counts only canonical records and dated expiring holds', () => {
		expect(
			bookingSummary(
				[
					{ status: 'submitted' },
					{ status: 'needs_info' },
					{ status: 'confirmed' },
				],
				[
					{ status: 'active', expires_at: '2026-08-02 00:00:00' },
					{ status: 'active' },
					{ status: 'released', expires_at: '2026-08-01 13:00:00' },
				],
				new Date( '2026-08-01T12:00:00Z' )
			)
		).toEqual( {
			newSubmissions: 1,
			needsInfo: 1,
			activeHolds: 2,
			expiringHolds: 1,
		} );
	} );
} );
