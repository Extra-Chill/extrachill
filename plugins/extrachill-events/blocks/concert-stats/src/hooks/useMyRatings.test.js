/**
 * useMyRatings — batched read + optimistic write of the viewer's ratings.
 */
/* global afterAll, beforeAll, beforeEach, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import useMyRatings from './useMyRatings';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

let hook;
const Probe = ( { ids, enabled } ) => {
	hook = useMyRatings( ids, enabled );
	return null;
};

async function mount( ids, enabled = true ) {
	const root = createRoot( document.createElement( 'div' ) );
	await act( async () => {
		root.render( <Probe ids={ ids } enabled={ enabled } /> );
		await Promise.resolve();
	} );
	return root;
}

describe( 'useMyRatings', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		apiFetch.mockReset();
		hook = null;
	} );

	it( 'reads ratings for the given events in one request', async () => {
		apiFetch.mockResolvedValue( { ratings: { 11: 4 } } );
		const root = await mount( [ 11, 12 ] );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
			'/wp-abilities/v1/abilities/data-machine-events/get-my-event-ratings/run?input[event_ids][]=11&input[event_ids][]=12'
		);
		expect( hook.ratings ).toEqual( { 11: 4 } );
		await act( async () => root.unmount() );
	} );

	it( 'does nothing when disabled', async () => {
		const root = await mount( [ 11 ], false );
		expect( apiFetch ).not.toHaveBeenCalled();
		await act( async () => root.unmount() );
	} );

	it( 'saves a rating optimistically and rolls back on failure', async () => {
		apiFetch.mockResolvedValueOnce( { ratings: {} } );
		const root = await mount( [ 11 ] );

		apiFetch.mockResolvedValueOnce( { created: true } );
		await act( async () => {
			await hook.rate( 11, 5 );
		} );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/wp-abilities/v1/abilities/data-machine-events/rate-event/run',
			method: 'POST',
			data: { input: { event_id: 11, rating: 5 } },
		} );
		expect( hook.ratings[ '11' ] ).toBe( 5 );

		apiFetch.mockRejectedValueOnce( new Error( 'Nope' ) );
		await act( async () => {
			await hook.rate( 11, 2 );
		} );
		expect( hook.ratings[ '11' ] ).toBe( 5 );
		expect( hook.errors[ '11' ] ).toBe( 'Nope' );
		await act( async () => root.unmount() );
	} );
} );
