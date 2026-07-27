/* global describe, expect, test */

/**
 * Internal dependencies
 */
import { apiDate, buildPayload, errorState } from './submission';

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
	startAt: '2026-08-12T20:00',
	endAt: '',
	message: 'Routing through Charleston.',
	fields: { draw: '150' },
	consent: true,
};

describe( 'booking inquiry transport helpers', () => {
	test( 'formats local date controls for the protected route', () => {
		expect( apiDate( values.startAt ) ).toBe( '2026-08-12 20:00:00' );
		expect( apiDate( '' ) ).toBeNull();
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
			requested_start_at: '2026-08-12 20:00:00',
			requested_end_at: null,
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
	} );
} );
