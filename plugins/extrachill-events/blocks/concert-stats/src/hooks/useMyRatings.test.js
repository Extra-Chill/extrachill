/**
 * useMyRatings — batched read + optimistic write of the viewer's ratings.
 */
/* global afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, jest */

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
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp-abilities/v1/abilities/extrachill/get-my-show-ratings/run?input[event_ids][]=11&input[event_ids][]=12',
			method: 'GET',
		} );
		expect( hook.ratings ).toEqual( { 11: 4 } );
		await act( async () => root.unmount() );
	} );

	it( 'reports a failed read instead of silently treating it as unrated', async () => {
		const error = new Error( 'Invalid nonce' );
		const report = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		apiFetch.mockRejectedValueOnce( error );
		const root = await mount( [ 11 ] );
		expect( report ).toHaveBeenCalledWith(
			'Could not load your show ratings.',
			error
		);
		expect( hook.ratings ).toEqual( {} );
		await act( async () => root.unmount() );
		report.mockRestore();
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
			path: '/wp-abilities/v1/abilities/extrachill/rate-show/run',
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

// Exercise the actual apiFetch serialization and WordPress authentication
// middleware, not just the hook's mocked apiFetch options.
describe( 'useMyRatings authenticated requests', () => {
	const realApiFetch = jest.requireActual( '@wordpress/api-fetch' ).default;
	const nonceMiddleware =
		realApiFetch.createNonceMiddleware( 'test-rest-nonce' );
	const rootMiddleware = realApiFetch.createRootURLMiddleware(
		'https://events.example/wp-json/'
	);
	let originalFetch;

	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
		realApiFetch.use( nonceMiddleware );
		realApiFetch.use( rootMiddleware );
	} );

	afterAll( () => {
		realApiFetch.unregister( nonceMiddleware );
		realApiFetch.unregister( rootMiddleware );
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );

	beforeEach( () => {
		originalFetch = global.fetch;
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			status: 200,
			json: () => Promise.resolve( { ratings: { 11: 4 } } ),
		} );
		apiFetch.mockReset();
		apiFetch.mockImplementation( realApiFetch );
	} );

	afterEach( () => {
		global.fetch = originalFetch;
	} );

	it( 'sends a GET with array input, the REST root and nonce middleware', async () => {
		const root = await mount( [ 11, 12 ] );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = global.fetch.mock.calls[ 0 ];
		expect( url ).toBe(
			'https://events.example/wp-json/wp-abilities/v1/abilities/extrachill/get-my-show-ratings/run?input%5Bevent_ids%5D%5B0%5D=11&input%5Bevent_ids%5D%5B1%5D=12&_locale=user'
		);
		expect( options.method ).toBe( 'GET' );
		expect( options.credentials ).toBe( 'include' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'test-rest-nonce' );
		expect( options.body ).toBeUndefined();
		expect( hook.ratings ).toEqual( { 11: 4 } );
		await act( async () => root.unmount() );
	} );

	it.each( [ 5, 0 ] )(
		'sends rating %i as a JSON POST with the nonce middleware',
		async ( rating ) => {
			const root = await mount( [ 11 ] );
			await act( async () => {
				await hook.rate( 11, rating );
			} );
			expect( global.fetch ).toHaveBeenCalledTimes( 2 );
			const [ url, options ] = global.fetch.mock.calls[ 1 ];
			expect( url ).toBe(
				'https://events.example/wp-json/wp-abilities/v1/abilities/extrachill/rate-show/run?_locale=user'
			);
			expect( options.method ).toBe( 'POST' );
			expect( options.credentials ).toBe( 'include' );
			expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'test-rest-nonce' );
			expect( options.headers[ 'Content-Type' ] ).toBe(
				'application/json'
			);
			expect( options.body ).toBe(
				JSON.stringify( { input: { event_id: 11, rating } } )
			);
			expect( hook.ratings[ '11' ] ).toBe( rating );
			await act( async () => root.unmount() );
		}
	);
} );
