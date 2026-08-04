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
		'extrachill/list-venue-bookings',
		'extrachill/get-venue-booking',
		'extrachill/update-venue-booking-config',
		'extrachill/review-venue-claim',
		'extrachill/cancel-venue-invitation',
	] )( 'sends object input through POST for %s', async ( ability ) => {
		const input = { venue_term_id: 44 };
		await runAbility( ability, input );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: `/wp-abilities/v1/abilities/${ ability }/run`,
			method: 'POST',
			data: { input },
		} );
	} );
} );
