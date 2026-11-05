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
	DRAFT_SCOPE_DEVICE,
	DRAFT_SCOPE_SESSION,
	DRAFT_TTL,
	DRAFT_VERSION,
	draftStorageKey,
	errorState,
	loadDraft,
	saveDraft,
} from './submission';

const config = {
	venue: { id: 42 },
	revision: 7,
	consent: { id: 'booking-privacy', version: 3 },
	fields: [ { key: 'draw', type: 'text' } ],
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
	turnstileResponse: 'must-not-persist',
	idempotencyKey: 'must-not-persist',
	accessToken: 'must-not-persist',
	files: [ 'must-not-persist' ],
	receipt: { public_id: 'must-not-persist' },
};

const storage = ( failures = {} ) => {
	const data = new Map();
	return {
		get length() {
			return data.size;
		},
		key: ( index ) => Array.from( data.keys() )[ index ] || null,
		getItem: ( key ) => {
			if ( failures.read ) {
				throw new Error( 'read denied' );
			}
			return data.get( key ) || null;
		},
		setItem: ( key, value ) => {
			if ( failures.write ) {
				throw new Error( 'write denied' );
			}
			data.set( key, value );
		},
		removeItem: ( key ) => {
			if ( failures.remove ) {
				throw new Error( 'remove denied' );
			}
			data.delete( key );
		},
	};
};

const stores = ( session = storage(), device = storage() ) => ( {
	session,
	device,
} );

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

	test( 'defaults to session-only recovery with a strict persisted allowlist', () => {
		const browserStores = stores();
		const now = Date.now();
		expect(
			saveDraft( config, values, DRAFT_SCOPE_SESSION, browserStores, now )
		).toBe( true );
		const raw = JSON.parse(
			browserStores.session.getItem( draftStorageKey( config ) )
		);
		expect( raw.scope ).toBe( DRAFT_SCOPE_SESSION );
		expect( raw.values ).toEqual( {
			artistName: 'Test Band',
			contactName: 'Alex',
			contactEmail: 'alex@example.com',
			contactPhone: '',
			spaceKey: 'main-room',
			requestedDate: '2026-08-12',
			message: 'Routing through Charleston.',
			fields: { draw: '150' },
		} );
		expect( JSON.stringify( raw ) ).not.toMatch(
			/consent|turnstile|idempotency|accessToken|files|receipt/
		);
		expect(
			browserStores.device.getItem( draftStorageKey( config ) )
		).toBeNull();

		const restored = loadDraft(
			config,
			{ ...values, consent: false },
			browserStores,
			now + 1000
		);
		expect( restored.values.artistName ).toBe( 'Test Band' );
		expect( restored.values.consent ).toBe( false );
		expect( restored.scope ).toBe( DRAFT_SCOPE_SESSION );
		expect( restored.outcome ).toBe( 'restored' );
	} );

	test( 'moves an opted-in draft to device storage and expires it', () => {
		const browserStores = stores();
		const now = Date.now();
		saveDraft( config, values, DRAFT_SCOPE_SESSION, browserStores, now );
		saveDraft( config, values, DRAFT_SCOPE_DEVICE, browserStores, now );
		expect(
			browserStores.session.getItem( draftStorageKey( config ) )
		).toBeNull();
		expect(
			browserStores.device.getItem( draftStorageKey( config ) )
		).not.toBeNull();

		const expired = loadDraft(
			config,
			values,
			browserStores,
			now + DRAFT_TTL + 1
		);
		expect( expired.values ).toEqual( values );
		expect( expired.outcome ).toBe( 'expired' );
		expect(
			browserStores.device.getItem( draftStorageKey( config ) )
		).toBeNull();
	} );

	test( 'clears incompatible schemas and stale configuration revisions', () => {
		const browserStores = stores();
		const oldConfig = { ...config, revision: 6 };
		saveDraft( oldConfig, values, DRAFT_SCOPE_SESSION, browserStores );
		browserStores.session.setItem(
			draftStorageKey( config ),
			JSON.stringify( { version: 1, values: {} } )
		);
		const restored = loadDraft( config, values, browserStores );
		expect( restored.outcome ).toBe( 'incompatible' );
		expect(
			browserStores.session.getItem( draftStorageKey( oldConfig ) )
		).toBeNull();
		expect(
			browserStores.session.getItem( draftStorageKey( config ) )
		).toBeNull();

		browserStores.session.setItem(
			draftStorageKey( config ),
			JSON.stringify( {
				version: DRAFT_VERSION,
				venueId: config.venue.id,
				revision: config.revision,
				scope: DRAFT_SCOPE_SESSION,
				savedAt: 'invalid',
				values: { artistName: 'Must not restore' },
			} )
		);
		expect( loadDraft( config, values, browserStores ).outcome ).toBe(
			'incompatible'
		);
		expect(
			browserStores.session.getItem( draftStorageKey( config ) )
		).toBeNull();
	} );

	test( 'uses available session storage when device storage is blocked', () => {
		const browserStores = stores( storage(), null );
		expect( loadDraft( config, values, browserStores ).outcome ).toBe(
			'none'
		);
		expect(
			saveDraft( config, values, DRAFT_SCOPE_SESSION, browserStores )
		).toBe( true );
	} );

	test( 'clears both storage scopes after success or an explicit clear', () => {
		const browserStores = stores();
		browserStores.session.setItem( draftStorageKey( config ), 'session' );
		browserStores.device.setItem( draftStorageKey( config ), 'device' );
		expect( clearDraft( config, browserStores ) ).toBe( true );
		expect(
			browserStores.session.getItem( draftStorageKey( config ) )
		).toBeNull();
		expect(
			browserStores.device.getItem( draftStorageKey( config ) )
		).toBeNull();
	} );

	test( 'reports denied storage without interrupting form behavior', () => {
		const deniedRead = stores( storage( { read: true } ), null );
		expect( loadDraft( config, values, deniedRead ).outcome ).toBe(
			'read-failed'
		);
		expect(
			saveDraft(
				config,
				values,
				DRAFT_SCOPE_SESSION,
				stores( storage( { write: true } ) )
			)
		).toBe( false );
		expect(
			clearDraft(
				config,
				stores( storage( { remove: true } ), storage() )
			)
		).toBe( false );
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
