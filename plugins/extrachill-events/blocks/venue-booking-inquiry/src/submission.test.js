/* global describe, expect, test */

/**
 * Internal dependencies
 */
import {
	availabilityErrorState,
	bookingDateInterval,
	buildAvailabilityPayload,
	buildPayload,
	clearDraft,
	DRAFT_TTL,
	draftStorageKey,
	errorState,
	loadDraft,
	saveDraft,
} from './submission';

const config = {
	venue: { id: 42 },
	revision: 7,
	consent: { id: 'booking-privacy', version: 3 },
};
const values = {
	artistName: 'Test Band',
	contactName: 'Alex',
	contactEmail: 'alex@example.com',
	contactPhone: '',
	spaceKey: 'main-room',
	requestedDate: '2026-08-12',
	message: 'Routing through Charleston.',
	fields: { draw: '150' },
	consent: true,
};

const storage = () => {
	const data = new Map();
	return {
		getItem: ( key ) => data.get( key ) || null,
		setItem: ( key, value ) => data.set( key, value ),
		removeItem: ( key ) => data.delete( key ),
	};
};

describe( 'booking inquiry transport helpers', () => {
	test( 'maps a requested date to its complete local day', () => {
		expect( bookingDateInterval( values.requestedDate ) ).toEqual( {
			start: '2026-08-12 00:00:00',
			end: '2026-08-13 00:00:00',
		} );
		expect( bookingDateInterval( '2026-12-31' ) ).toEqual( {
			start: '2026-12-31 00:00:00',
			end: '2027-01-01 00:00:00',
		} );
	} );

	test( 'builds only contracted transport and intake values', () => {
		const payload = buildPayload(
			config,
			values,
			'request-key',
			'turnstile-token'
		);
		expect( payload ).toEqual( {
			venue: 42,
			idempotency_key: 'request-key',
			artist_name: 'Test Band',
			contact_name: 'Alex',
			contact_email: 'alex@example.com',
			contact_phone: null,
			requested_space_key: 'main-room',
			requested_start_at: '2026-08-12 00:00:00',
			requested_end_at: '2026-08-13 00:00:00',
			intake: {
				config_revision: 7,
				message: 'Routing through Charleston.',
				fields: { draw: '150' },
				consent: { id: 'booking-privacy', version: 3, accepted: true },
			},
			turnstile_response: 'turnstile-token',
		} );
		expect( payload ).not.toHaveProperty( 'user_id' );
		expect( payload ).not.toHaveProperty( 'attachments' );
	} );

	test( 'builds a privacy-safe date availability request', () => {
		expect( buildAvailabilityPayload( config, values ) ).toEqual( {
			venue: 42,
			requested_space_key: 'main-room',
			requested_start_at: '2026-08-12 00:00:00',
			requested_end_at: '2026-08-13 00:00:00',
		} );
	} );

	test( 'scopes, versions, bounds, and expires local drafts without consent', () => {
		const local = storage();
		saveDraft( config, values, local );
		const restored = loadDraft( config, values, local, Date.now() + 1000 );
		expect( restored.artistName ).toBe( 'Test Band' );
		expect( restored.consent ).toBe( false );
		expect(
			local.getItem( draftStorageKey( { ...config, revision: 8 } ) )
		).toBeNull();
		const expired = loadDraft(
			config,
			values,
			local,
			Date.now() + DRAFT_TTL + 1
		);
		expect( expired ).toEqual( values );
		expect( local.getItem( draftStorageKey( config ) ) ).toBeNull();
	} );

	test( 'clears only the successful inquiry draft', () => {
		const local = storage();
		saveDraft( config, values, local );
		clearDraft( config, local );
		expect( local.getItem( draftStorageKey( config ) ) ).toBeNull();
	} );

	test( 'distinguishes safe retry, stale config, and reconciliation states', () => {
		const headers = new Headers( { 'Retry-After': '45' } );
		expect(
			errorState(
				{ status: 429, headers },
				{ code: 'public_write_rate_limited' }
			).message
		).toContain( '45 seconds' );
		expect(
			errorState(
				{ status: 409, headers },
				{ code: 'booking_inquiry_stale_config' }
			).retryable
		).toBe( false );
		expect(
			errorState(
				{ status: 503, headers },
				{ code: 'booking_inquiry_reconciliation_required' }
			).message
		).toContain( 'Do not resend' );
		expect(
			errorState(
				{ status: 409, headers },
				{ code: 'booking_inquiry_interval_unavailable' }
			).resetAvailability
		).toBe( true );
		expect(
			availabilityErrorState(
				{ status: 409 },
				{ message: 'That exact time is unavailable.' }
			).message
		).toBe( 'That exact time is unavailable.' );
	} );
} );
