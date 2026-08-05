// React's test-only act helper is supplied transitively by @wordpress/element.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';
import { createRoot } from '@wordpress/element';
import useAnalytics from '../../src/blocks/artist-analytics/hooks/useAnalytics';
import { getAnalytics } from '../../src/blocks/shared/api/client';

jest.mock( '../../src/blocks/shared/api/client', () => ( {
	getAnalytics: jest.fn(),
} ) );

const deferred = () => {
	let resolve;
	let reject;
	const promise = new Promise( ( promiseResolve, promiseReject ) => {
		resolve = promiseResolve;
		reject = promiseReject;
	} );
	return { promise, resolve, reject };
};

describe( 'useAnalytics', () => {
	let container;
	let root;
	let current;

	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );

	function Harness( { artistId = 42 } ) {
		current = useAnalytics( artistId );
		return null;
	}

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		getAnalytics.mockReset();
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
	} );

	test( 'preserves numeric presets and sends exact object requests', async () => {
		getAnalytics.mockResolvedValue( { summary: {} } );
		await act( async () => root.render( <Harness /> ) );

		expect( getAnalytics ).toHaveBeenLastCalledWith( 42, 30 );

		await act( async () => current.selectPreset( 7 ) );
		expect( getAnalytics ).toHaveBeenLastCalledWith( 42, 7 );

		await act( async () =>
			current.selectExact( {
				startDate: '2026-06-01',
				endDate: '2026-06-30',
			} )
		);
		expect( getAnalytics ).toHaveBeenLastCalledWith( 42, {
			start_date: '2026-06-01',
			end_date: '2026-06-30',
		} );
	} );

	test( 'does not request an incomplete custom range', async () => {
		getAnalytics.mockResolvedValue( { summary: {} } );
		await act( async () => root.render( <Harness /> ) );
		getAnalytics.mockClear();

		await act( async () => current.selectCustom() );

		expect( getAnalytics ).not.toHaveBeenCalled();
		expect( current.analytics ).toBeNull();
		expect( current.isLoading ).toBe( false );

		await act( async () => root.render( <Harness artistId={ 43 } /> ) );
		expect( getAnalytics ).not.toHaveBeenCalled();
		expect( current.analytics ).toBeNull();
	} );

	test( 'prevents stale requests from overwriting the current range', async () => {
		const presetRequest = deferred();
		const exactRequest = deferred();
		getAnalytics
			.mockReturnValueOnce( presetRequest.promise )
			.mockReturnValueOnce( exactRequest.promise );
		act( () => root.render( <Harness /> ) );

		act( () =>
			current.selectExact( {
				startDate: '2026-06-01',
				endDate: '2026-06-30',
			} )
		);
		await act( async () => exactRequest.resolve( { range: 'exact' } ) );
		expect( current.analytics ).toEqual( { range: 'exact' } );

		await act( async () => presetRequest.resolve( { range: 'stale' } ) );
		expect( current.analytics ).toEqual( { range: 'exact' } );
	} );
} );
