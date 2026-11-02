/* global describe, expect, test */

/**
 * Internal dependencies
 */
import {
	accessPayload,
	boundedReceipt,
	clearReceipt,
	followThroughError,
	loadReceipt,
	mutationPayload,
	receiptStorageKey,
	recoveryPayload,
	recoveryError,
	saveReceipt,
} from './follow-through';

const publicId = '123e4567-e89b-42d3-a456-426614174000';
const capability = 'a'.repeat( 64 );
const config = { venue: { id: 42 } };
const storage = () => {
	const values = new Map();
	return {
		getItem: ( key ) => values.get( key ) || null,
		setItem: ( key, value ) => values.set( key, value ),
		removeItem: ( key ) => values.delete( key ),
	};
};

describe( 'booking inquiry follow-through contracts', () => {
	test( 'persists and restores only the bounded anonymous receipt', () => {
		const session = storage();
		expect(
			saveReceipt(
				config,
				{
					public_id: publicId,
					venue_term_id: 42,
					capability,
					contact_email: 'never@example.com',
					intake: { private: true },
					attachments: [ 1 ],
				},
				session
			)
		).toBe( true );
		const raw = session.getItem( receiptStorageKey( config ) );
		expect( JSON.parse( raw ) ).toEqual( {
			public_id: publicId,
			venue_term_id: 42,
			capability,
		} );
		expect( raw ).not.toMatch( /contact|intake|attachment/ );
		expect( loadReceipt( config, session ) ).toEqual( {
			public_id: publicId,
			venue_term_id: 42,
			capability,
		} );
	} );

	test( 'supports authenticated receipts without inventing a capability', () => {
		const session = storage();
		saveReceipt(
			config,
			{ public_id: publicId, venue_term_id: 42 },
			session
		);
		expect( loadReceipt( config, session ) ).toEqual( {
			public_id: publicId,
			venue_term_id: 42,
		} );
		expect( accessPayload( loadReceipt( config, session ) ) ).toEqual( {
			public_id: publicId,
		} );
		expect(
			mutationPayload( loadReceipt( config, session ), 4, 'auth-key' )
		).toEqual( {
			public_id: publicId,
			expected_version: 4,
			idempotency_key: 'auth-key',
		} );
	} );

	test( 'rejects wrong venue, malformed authority, and malformed references', () => {
		expect(
			boundedReceipt( config, {
				public_id: publicId,
				venue_term_id: 41,
				capability,
			} )
		).toBeNull();
		expect(
			boundedReceipt( config, {
				public_id: publicId,
				venue_term_id: 42,
				capability: 'wrong',
			} )
		).toBeNull();
		expect(
			boundedReceipt( config, { public_id: 'guess', venue_term_id: 42 } )
		).toBeNull();
	} );

	test( 'keeps anonymous authority in request bodies and never URLs', () => {
		const receipt = {
			public_id: publicId,
			venue_term_id: 42,
			capability,
		};
		expect( accessPayload( receipt ) ).toEqual( {
			public_id: publicId,
			capability,
		} );
		expect( mutationPayload( receipt, 3, 'fresh-key' ) ).toEqual( {
			public_id: publicId,
			capability,
			expected_version: 3,
			idempotency_key: 'fresh-key',
		} );
		const endpoints = Object.values( {
			status: '/follow-through/status',
			correction: '/follow-through/correction',
			withdrawal: '/follow-through/withdrawal',
		} );
		expect( endpoints.join( '' ) ).not.toContain( capability );
	} );

	test( 'builds neutral recovery without retaining contact data', () => {
		expect(
			recoveryPayload(
				` ${ publicId } `,
				' artist@example.com ',
				'recovery-key',
				'turnstile'
			)
		).toEqual( {
			public_id: publicId,
			contact_email: 'artist@example.com',
			idempotency_key: 'recovery-key',
			turnstile_response: 'turnstile',
		} );
	} );

	test( 'classifies stale versions and wrong authority without server detail', () => {
		expect(
			followThroughError(
				{ status: 409 },
				{ code: 'booking_version_conflict', current_version: 9 }
			).stale
		).toBe( true );
		const forbidden = followThroughError(
			{ status: 403 },
			{ code: 'booking_inquiry_forbidden', message: 'Secret mismatch' }
		);
		expect( forbidden.authorityLost ).toBe( true );
		expect( forbidden.message ).not.toContain( 'Secret mismatch' );
		expect(
			followThroughError(
				{ status: 404 },
				{ code: 'booking_inquiry_unavailable' }
			).authorityLost
		).toBe( true );
		expect(
			followThroughError( { status: 400 }, { code: 'turnstile_failed' } )
				.challenge
		).toBe( true );
		expect(
			recoveryError(
				{ status: 429 },
				{ code: 'public_write_rate_limited' }
			).message
		).toContain( 'Wait a moment' );
		expect(
			recoveryError( { status: 503 }, { message: 'Private mismatch' } )
				.message
		).not.toContain( 'Private mismatch' );
	} );

	test( 'clears terminal and explicitly removed receipts', () => {
		const session = storage();
		saveReceipt(
			config,
			{ public_id: publicId, venue_term_id: 42 },
			session
		);
		expect( clearReceipt( config, session ) ).toBe( true );
		expect( loadReceipt( config, session ) ).toBeNull();
	} );
} );
