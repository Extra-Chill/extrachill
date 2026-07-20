/**
 * useStats request isolation and authorization behavior.
 */
/* eslint-env jest */

import { createRoot } from '@wordpress/element';
import { act } from 'react';
import apiFetch from '@wordpress/api-fetch';
import useStats from './useStats';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const privateError = {
	code: 'concert_history_private',
	message: 'Concert history is private.',
	data: { status: 403 },
};

const deferred = () => {
	let resolve;
	let reject;
	const promise = new Promise( ( done, fail ) => {
		resolve = done;
		reject = fail;
	} );
	return { promise, resolve, reject };
};

const StatsHarness = ( { userId, filters } ) => {
	const result = useStats( userId, filters );
	return (
		<div>
			<span data-testid="total">{ result.stats?.total_shows ?? '' }</span>
			<span data-testid="loading">
				{ result.loading ? 'loading' : 'ready' }
			</span>
			<span data-testid="error-code">{ result.error?.code || '' }</span>
			<span data-testid="error-status">
				{ result.error?.data?.status || '' }
			</span>
		</div>
	);
};

const value = ( container, testId ) =>
	container.querySelector( `[data-testid="${ testId }"]` ).textContent;

async function renderHarness( props ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	await act( async () => {
		root.render( <StatsHarness { ...props } /> );
		await Promise.resolve();
	} );

	return {
		container,
		root,
		rerender: async ( nextProps ) => {
			await act( async () => {
				root.render( <StatsHarness { ...nextProps } /> );
				await Promise.resolve();
			} );
		},
	};
}

describe( 'useStats', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );

	beforeEach( () => {
		apiFetch.mockReset();
		document.body.innerHTML = '';
	} );

	it( 'clears public stats when the next profile returns private', async () => {
		const privateRequest = deferred();
		apiFetch
			.mockResolvedValueOnce( { total_shows: 12 } )
			.mockImplementationOnce( () => privateRequest.promise );
		const harness = await renderHarness( {
			userId: 7,
			filters: { dateTo: '2026-07-18' },
		} );

		expect( value( harness.container, 'total' ) ).toBe( '12' );
		await harness.rerender( {
			userId: 8,
			filters: { dateTo: '2026-07-18' },
		} );

		expect( value( harness.container, 'total' ) ).toBe( '' );
		expect( value( harness.container, 'error-code' ) ).toBe( '' );
		expect( value( harness.container, 'loading' ) ).toBe( 'loading' );
		await act( async () => privateRequest.reject( privateError ) );
		expect( value( harness.container, 'error-code' ) ).toBe(
			'concert_history_private'
		);
		expect( value( harness.container, 'error-status' ) ).toBe( '403' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'ignores out-of-order success from an aborted profile request', async () => {
		const oldRequest = deferred();
		const newRequest = deferred();
		apiFetch
			.mockImplementationOnce( () => oldRequest.promise )
			.mockImplementationOnce( () => newRequest.promise );
		const harness = await renderHarness( { userId: 7, filters: {} } );
		const oldSignal = apiFetch.mock.calls[ 0 ][ 0 ].signal;

		await harness.rerender( { userId: 8, filters: {} } );
		expect( oldSignal.aborted ).toBe( true );
		expect( value( harness.container, 'total' ) ).toBe( '' );

		await act( async () => newRequest.reject( privateError ) );
		await act( async () => oldRequest.resolve( { total_shows: 99 } ) );

		expect( value( harness.container, 'total' ) ).toBe( '' );
		expect( value( harness.container, 'error-code' ) ).toBe(
			'concert_history_private'
		);
		await act( async () => harness.root.unmount() );
	} );

	it( 'aborts on query changes and exposes a clean disabled state', async () => {
		const request = deferred();
		apiFetch.mockImplementationOnce( () => request.promise );
		const harness = await renderHarness( { userId: 7, filters: {} } );
		const signal = apiFetch.mock.calls[ 0 ][ 0 ].signal;

		await harness.rerender( {
			userId: 7,
			filters: { enabled: false },
		} );

		expect( signal.aborted ).toBe( true );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( value( harness.container, 'loading' ) ).toBe( 'ready' );
		expect( value( harness.container, 'total' ) ).toBe( '' );
		expect( value( harness.container, 'error-code' ) ).toBe( '' );
		await act( async () => request.resolve( { total_shows: 99 } ) );
		expect( value( harness.container, 'total' ) ).toBe( '' );
		await act( async () => harness.root.unmount() );
	} );
} );
