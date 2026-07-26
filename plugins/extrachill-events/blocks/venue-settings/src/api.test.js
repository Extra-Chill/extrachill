/* global beforeEach, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { runAbility } from './api';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

describe( 'venue settings ability transport', () => {
	beforeEach( () => apiFetch.mockResolvedValue( {} ) );

	it( 'uses GET with JSON input for canonical reads', async () => {
		await runAbility( 'extrachill/get-venue-profile', {
			venue_term_id: 44,
		} );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'GET',
				path: expect.stringContaining(
					'input=%7B%22venue_term_id%22%3A44%7D'
				),
			} )
		);
	} );

	it( 'uses DELETE for idempotent destructive cancellation', async () => {
		await runAbility( 'extrachill/cancel-venue-invitation', {
			invitation_id: 9,
		} );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( { method: 'DELETE' } )
		);
	} );

	it.each( [ 'approved', 'rejected' ] )(
		'uses DELETE when a claim is %s',
		async ( decision ) => {
			const input = {
				claim_id: 12,
				decision,
				expected_version: 3,
			};
			await runAbility( 'extrachill/review-venue-claim', input );
			expect( apiFetch ).toHaveBeenCalledWith( {
				method: 'DELETE',
				path: expect.stringContaining(
					encodeURIComponent( JSON.stringify( input ) )
				),
			} );
		}
	);

	it( 'wraps mutation input in the WordPress ability request envelope', async () => {
		const input = { venue_term_id: 44, expected_revision: 2, config: {} };
		await runAbility( 'extrachill/update-venue-booking-config', input );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( { method: 'POST', data: { input } } )
		);
	} );
} );
