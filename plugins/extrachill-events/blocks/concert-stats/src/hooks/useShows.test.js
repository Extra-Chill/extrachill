/**
 * useShows pagination and query identity behavior.
 */
/* eslint-env jest */

import { createRoot } from '@wordpress/element';
import { act } from 'react';
import apiFetch from '@wordpress/api-fetch';
import useShows from './useShows';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const response = ( shows, total, pages, page ) => ( {
	shows: shows.map( ( eventId ) => ( {
		event_id: eventId,
		title: `Show ${ eventId }`,
	} ) ),
	total,
	pages,
	page,
} );

const deferred = () => {
	let resolve;
	const promise = new Promise( ( done ) => {
		resolve = done;
	} );
	return { promise, resolve };
};

const ShowsHarness = ( { userId, filters } ) => {
	const result = useShows( userId, filters );
	return (
		<div>
			<span data-testid="shows">
				{ result.shows.map( ( show ) => show.event_id ).join( ',' ) }
			</span>
			<span data-testid="page">{ result.page }</span>
			<span data-testid="total">{ result.total }</span>
			<span data-testid="loading">
				{ result.loading ? 'loading' : 'ready' }
			</span>
			<span data-testid="error-code">{ result.error?.code || '' }</span>
			<span data-testid="error-status">
				{ result.error?.data?.status || '' }
			</span>
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
		root.render( <ShowsHarness { ...props } /> );
		await Promise.resolve();
	} );

	return {
		container,
		root,
		rerender: async ( nextProps ) => {
			await act( async () => {
				root.render( <ShowsHarness { ...nextProps } /> );
				await Promise.resolve();
			} );
		},
		loadMore: async () => {
			await act( async () => {
				container
					.querySelector( 'button' )
					.dispatchEvent(
						new MouseEvent( 'click', { bubbles: true } )
					);
				await Promise.resolve();
			} );
		},
	};
}

describe( 'useShows', () => {
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

	it( 'starts a selected year at page one after multiple pages', async () => {
		apiFetch.mockImplementation( ( { path } ) => {
			const page = Number(
				new URLSearchParams( path.split( '?' )[ 1 ] ).get( 'page' )
			);
			if ( path.includes( 'year=2025' ) ) {
				return Promise.resolve( response( [ 25 ], 1, 1, 1 ) );
			}
			return Promise.resolve( response( [ page ], 3, 3, page ) );
		} );
		const initial = {
			userId: 7,
			filters: { period: 'past', year: 0, perPage: 1 },
		};
		const harness = await renderHarness( initial );

		await harness.loadMore();
		await harness.loadMore();
		expect( value( harness.container, 'shows' ) ).toBe( '1,2,3' );

		await harness.rerender( {
			...initial,
			filters: { ...initial.filters, year: 2025 },
		} );

		expect( value( harness.container, 'shows' ) ).toBe( '25' );
		expect( value( harness.container, 'page' ) ).toBe( '1' );
		expect( apiFetch.mock.calls.at( -1 )[ 0 ].path ).toContain( 'page=1' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'resets rows when the user changes', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			Promise.resolve(
				path.includes( '/user/8/' )
					? response( [ 80 ], 1, 1, 1 )
					: response( [ 70 ], 1, 1, 1 )
			)
		);
		const filters = { period: 'past', year: 0 };
		const harness = await renderHarness( { userId: 7, filters } );

		await harness.rerender( { userId: 8, filters } );

		expect( value( harness.container, 'shows' ) ).toBe( '80' );
		expect( value( harness.container, 'total' ) ).toBe( '1' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'resets rows when period, visibility scope, or page size changes', async () => {
		apiFetch
			.mockResolvedValueOnce( response( [ 1 ], 1, 1, 1 ) )
			.mockResolvedValueOnce( response( [ 4 ], 1, 1, 1 ) )
			.mockResolvedValueOnce( response( [ 2 ], 1, 1, 1 ) )
			.mockResolvedValueOnce( response( [ 3 ], 1, 1, 1 ) );
		const harness = await renderHarness( {
			userId: 7,
			filters: {
				period: 'past',
				perPage: 20,
				queryScope: 'owner',
			},
		} );

		await harness.rerender( {
			userId: 7,
			filters: {
				period: 'past',
				perPage: 20,
				queryScope: 'public',
			},
		} );
		expect( value( harness.container, 'shows' ) ).toBe( '4' );

		await harness.rerender( {
			userId: 7,
			filters: {
				period: 'upcoming',
				perPage: 20,
				queryScope: 'public',
			},
		} );
		expect( value( harness.container, 'shows' ) ).toBe( '2' );

		await harness.rerender( {
			userId: 7,
			filters: {
				period: 'upcoming',
				perPage: 10,
				queryScope: 'public',
			},
		} );
		expect( value( harness.container, 'shows' ) ).toBe( '3' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'ignores an overlapping response from the previous query', async () => {
		const oldRequest = deferred();
		const newRequest = deferred();
		apiFetch
			.mockImplementationOnce( () => oldRequest.promise )
			.mockImplementationOnce( () => newRequest.promise );
		const harness = await renderHarness( {
			userId: 7,
			filters: { period: 'past', year: 2024 },
		} );

		await harness.rerender( {
			userId: 7,
			filters: { period: 'past', year: 2025 },
		} );
		await act( async () =>
			newRequest.resolve( response( [ 25 ], 1, 1, 1 ) )
		);
		expect( value( harness.container, 'shows' ) ).toBe( '25' );

		await act( async () =>
			oldRequest.resolve( response( [ 24 ], 1, 1, 1 ) )
		);
		expect( value( harness.container, 'shows' ) ).toBe( '25' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'deduplicates rows across retries and pages', async () => {
		apiFetch
			.mockResolvedValueOnce( response( [ 1, 2, 2 ], 3, 2, 1 ) )
			.mockResolvedValueOnce( response( [ 2, 3, 3 ], 3, 2, 2 ) );
		const harness = await renderHarness( {
			userId: 7,
			filters: { period: 'past' },
		} );

		await harness.loadMore();

		expect( value( harness.container, 'shows' ) ).toBe( '1,2,3' );
		expect( value( harness.container, 'total' ) ).toBe( '3' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'preserves canonical error code and status', async () => {
		apiFetch.mockRejectedValue( {
			code: 'concert_history_private',
			message: 'Concert history is private.',
			data: { status: 403 },
		} );

		const harness = await renderHarness( {
			userId: 7,
			filters: { period: 'past' },
		} );

		expect( value( harness.container, 'error-code' ) ).toBe(
			'concert_history_private'
		);
		expect( value( harness.container, 'error-status' ) ).toBe( '403' );
		expect( value( harness.container, 'shows' ) ).toBe( '' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'clears accumulated rows for an empty year', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			Promise.resolve(
				path.includes( 'year=2023' )
					? response( [], 0, 0, 1 )
					: response( [ 1 ], 1, 1, 1 )
			)
		);
		const harness = await renderHarness( {
			userId: 7,
			filters: { period: 'past', year: 0 },
		} );

		await harness.rerender( {
			userId: 7,
			filters: { period: 'past', year: 2023 },
		} );

		expect( value( harness.container, 'shows' ) ).toBe( '' );
		expect( value( harness.container, 'total' ) ).toBe( '0' );
		expect( value( harness.container, 'page' ) ).toBe( '1' );
		await act( async () => harness.root.unmount() );
	} );

	it( 'recovers a server-clamped page at page one', async () => {
		apiFetch
			.mockResolvedValueOnce( response( [ 1 ], 2, 2, 1 ) )
			.mockResolvedValueOnce( response( [ 2 ], 1, 1, 1 ) )
			.mockResolvedValueOnce( response( [ 9 ], 1, 1, 1 ) );
		const harness = await renderHarness( {
			userId: 7,
			filters: { period: 'past' },
		} );

		await harness.loadMore();

		expect( apiFetch.mock.calls.at( -1 )[ 0 ].path ).toContain( 'page=1' );
		expect( value( harness.container, 'shows' ) ).toBe( '9' );
		expect( value( harness.container, 'page' ) ).toBe( '1' );
		await act( async () => harness.root.unmount() );
	} );
} );
