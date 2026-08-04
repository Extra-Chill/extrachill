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

	it.each( [
		'extrachill/get-venue-profile',
		'extrachill/get-venue-booking-config',
		'extrachill/list-venue-memberships',
		'extrachill/list-booking-holds',
	] )( 'sends object input through GET for %s', async ( ability ) => {
		const input = { venue_term_id: 44 };
		await runAbility( ability, input );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: `/wp-abilities/v1/abilities/${ ability }/run?input%5Bvenue_term_id%5D=44`,
			method: 'GET',
		} );
	} );

	it( 'uses DELETE with nested object input for destructive actions', async () => {
		await runAbility( 'extrachill/cancel-venue-invitation', {
			invitation_id: 9,
		} );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp-abilities/v1/abilities/extrachill/cancel-venue-invitation/run?input%5Binvitation_id%5D=9',
			method: 'DELETE',
		} );
	} );

	it( 'uses POST with JSON object input for updates', async () => {
		const input = { venue_term_id: 44, config: {} };
		await runAbility( 'extrachill/update-venue-booking-config', input );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp-abilities/v1/abilities/extrachill/update-venue-booking-config/run',
			method: 'POST',
			data: { input },
		} );
	} );
} );
