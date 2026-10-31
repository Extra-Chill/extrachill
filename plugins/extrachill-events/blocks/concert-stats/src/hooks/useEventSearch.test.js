/**
 * useEventSearch period scoping and reset behavior (#837).
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
import useEventSearch from './useEventSearch';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const response = ( postIds ) => ( {
	events: postIds.map( ( postId ) => ( {
		post_id: postId,
		title: `Event ${ postId }`,
		timing: 'past',
		is_marked: false,
	} ) ),
	total: postIds.length,
	pages: 1,
	page: 1,
} );

const SearchHarness = ( { query, options } ) => {
	const result = useEventSearch( query, options );
	return (
		<div>
			<span data-testid="events">
				{ result.events.map( ( ev ) => ev.post_id ).join( ',' ) }
			</span>
			<span data-testid="total">{ result.total }</span>
			<button type="button" onClick={ result.loadMore }>
				Load More
			</button>
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
		root.render( <SearchHarness { ...props } /> );
		await Promise.resolve();
	} );

	return {
		container,
		root,
		rerender: async ( nextProps ) => {
			await act( async () => {
				root.render( <SearchHarness { ...nextProps } /> );
				await Promise.resolve();
			} );
		},
	};
}

const flushDebounce = async () => {
	await act( async () => {
		jest.runOnlyPendingTimers();
		await Promise.resolve();
	} );
};

describe( 'useEventSearch', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'sends period=past when the period option is omitted', async () => {
		apiFetch.mockImplementation( () => Promise.resolve( response( [] ) ) );
		const harness = await renderHarness( {
			query: 'Dead',
			options: {},
		} );
		await flushDebounce();

		expect( apiFetch.mock.calls.at( -1 )[ 0 ].path ).toContain(
			'period=past'
		);
		await act( async () => harness.root.unmount() );
	} );

	it( 'sends period=upcoming when requested', async () => {
		apiFetch.mockImplementation( () =>
			Promise.resolve( response( [ 5 ] ) )
		);
		const harness = await renderHarness( {
			query: 'Dead',
			options: { period: 'upcoming' },
		} );
		await flushDebounce();

		const path = apiFetch.mock.calls.at( -1 )[ 0 ].path;
		expect( path ).toContain( 'period=upcoming' );
		expect( path ).toContain( '/concert-tracking/search' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'resets accumulated rows immediately when the period changes', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			Promise.resolve(
				path.includes( 'period=upcoming' )
					? response( [ 2 ] )
					: response( [ 1 ] )
			)
		);
		const harness = await renderHarness( {
			query: 'Dead',
			options: { period: 'past' },
		} );
		await flushDebounce();
		expect( value( harness.container, 'events' ) ).toBe( '1' );

		await harness.rerender( {
			query: 'Dead',
			options: { period: 'upcoming' },
		} );

		// Stale past rows clear before the debounced refetch resolves.
		expect( value( harness.container, 'events' ) ).toBe( '' );

		await flushDebounce();
		expect( value( harness.container, 'events' ) ).toBe( '2' );
		expect( apiFetch.mock.calls.at( -1 )[ 0 ].path ).toContain(
			'period=upcoming'
		);
		await act( async () => harness.root.unmount() );
	} );

	it( 'falls back to past for an unknown period value', async () => {
		apiFetch.mockImplementation( () => Promise.resolve( response( [] ) ) );
		const harness = await renderHarness( {
			query: 'Dead',
			options: { period: 'bogus' },
		} );
		await flushDebounce();

		expect( apiFetch.mock.calls.at( -1 )[ 0 ].path ).toContain(
			'period=past'
		);
		await act( async () => harness.root.unmount() );
	} );
} );
