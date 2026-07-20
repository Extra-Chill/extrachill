/**
 * Concert stats public/owner request boundaries.
 */
/* eslint-env jest */

import { createRoot } from '@wordpress/element';
import { act } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { ConcertStatsApp } from './view';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children } ) =>
		React.createElement( 'div', null, children );

	return {
		ActionRow: Wrapper,
		BlockShell: Wrapper,
		BlockShellInner: Wrapper,
		BlockShellHeader: ( { title } ) =>
			React.createElement( 'h2', null, title ),
		Grid: Wrapper,
		InlineStatus: Wrapper,
		ResponsiveTabs: ( { tabs, active, renderPanel } ) =>
			React.createElement(
				'div',
				null,
				tabs.map( ( tab ) =>
					React.createElement( 'button', { key: tab.id }, tab.label )
				),
				renderPanel( active )
			),
		Section: Wrapper,
		SearchBox: ( { value } ) =>
			React.createElement( 'input', { value, readOnly: true } ),
		StatGroup: Wrapper,
		StatTile: ( { label, value } ) =>
			React.createElement( 'span', null, `${ label }: ${ value }` ),
	};
} );

const emptyStats = {
	total_shows: 0,
	unique_venues: 0,
	unique_artists: 0,
	unique_cities: 0,
	top_artists: [],
	top_venues: [],
	top_cities: [],
	shows_by_year: {},
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

function mockApi() {
	apiFetch.mockImplementation( ( { path } ) => {
		if ( path.includes( '/stats' ) ) {
			return Promise.resolve( emptyStats );
		}
		if ( path.includes( '/shows' ) ) {
			const upcoming = path.includes( 'period=upcoming' );
			return Promise.resolve( {
				shows: [
					{
						event_id: upcoming ? 99 : 11,
						title: upcoming
							? 'Secret Future Concert'
							: 'Past Concert',
						event_date: upcoming ? '2026-08-01' : '2026-01-01',
						artists: [],
					},
				],
				total: 1,
				pages: 1,
			} );
		}
		if ( path.includes( '/concert-import/sources' ) ) {
			return Promise.resolve( { sources: [ { id: 'setlistfm' } ] } );
		}
		if ( path.includes( '/concert-import/status' ) ) {
			return Promise.resolve( { runs: [] } );
		}
		if ( path.includes( '/concert-tracking/search' ) ) {
			return Promise.resolve( { events: [], total: 0, pages: 0 } );
		}
		return Promise.resolve( {} );
	} );
}

async function renderApp( { isOwn, tab, userId = 34 } ) {
	window.history.replaceState(
		{},
		'',
		`/my-shows/?user_id=34${ tab ? `&tab=${ tab }` : '' }`
	);
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	const render = ( nextUserId ) => (
		<ConcertStatsApp
			userId={ nextUserId }
			eventsUrl="https://events.example"
			isOwn={ isOwn }
			publicDateTo="2026-07-18"
			hasCalendar={ isOwn }
			hasMap={ isOwn }
			containerRef={ { current: container } }
		/>
	);

	await act( async () => {
		root.render( render( userId ) );
		await Promise.resolve();
	} );

	return {
		container,
		root,
		rerenderUser: async ( nextUserId ) => {
			await act( async () => {
				root.render( render( nextUserId ) );
				await Promise.resolve();
			} );
		},
	};
}

describe( 'ConcertStatsApp request boundaries', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		mockApi();
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it.each( [
		[ 'logged-out calendar link', 'calendar' ],
		[ 'logged-in non-owner map link', 'map' ],
		[ 'public import link', 'import' ],
	] )( 'renders past-only for a %s', async ( label, tab ) => {
		const { container, root } = await renderApp( { isOwn: false, tab } );
		const paths = apiFetch.mock.calls.map(
			( [ request ] ) => request.path
		);

		expect( window.location.search ).toContain( 'tab=past' );
		expect( container.textContent ).toContain( 'Past' );
		expect( container.textContent ).toContain( 'Past Concert' );
		expect( container.textContent ).not.toContain( 'Upcoming' );
		expect( container.textContent ).not.toContain(
			'Secret Future Concert'
		);
		expect( paths ).toContain(
			'/extrachill/v1/concert-tracking/user/34/stats?date_to=2026-07-18'
		);
		expect(
			paths.some( ( path ) => path.includes( 'period=upcoming' ) )
		).toBe( false );
		expect( paths.some( ( path ) => path.includes( 'period=past' ) ) ).toBe(
			true
		);
		expect(
			paths.some( ( path ) => path.includes( '/concert-import/' ) )
		).toBe( false );
		expect(
			paths.some( ( path ) =>
				path.includes( '/concert-tracking/search' )
			)
		).toBe( false );

		await act( async () => root.unmount() );
	} );

	it( 'retains owner upcoming and write requests', async () => {
		const { container, root } = await renderApp( {
			isOwn: true,
			tab: 'past',
		} );

		await act( async () => {
			jest.runOnlyPendingTimers();
			await Promise.resolve();
		} );

		const paths = apiFetch.mock.calls.map(
			( [ request ] ) => request.path
		);
		expect( container.textContent ).toContain( 'Upcoming' );
		expect( paths ).toContain(
			'/extrachill/v1/concert-tracking/user/34/stats'
		);
		expect( paths.some( ( path ) => path.includes( 'date_to=' ) ) ).toBe(
			false
		);
		expect(
			paths.some( ( path ) => path.includes( 'period=upcoming' ) )
		).toBe( true );
		expect( paths ).toContain( '/extrachill/v1/concert-import/sources' );
		expect( paths ).toContain( '/extrachill/v1/concert-import/status' );
		expect(
			paths.some( ( path ) =>
				path.includes( '/concert-tracking/search' )
			)
		).toBe( true );

		await act( async () => root.unmount() );
	} );

	it( 'renders private history without requesting or displaying shows', async () => {
		apiFetch.mockImplementation( ( { path } ) => {
			if ( path.includes( '/stats' ) ) {
				return Promise.reject( {
					code: 'concert_history_private',
					message: 'Concert history is private.',
					data: { status: 403 },
				} );
			}
			return Promise.resolve( {} );
		} );

		const { container, root } = await renderApp( {
			isOwn: false,
			tab: 'past',
		} );
		const paths = apiFetch.mock.calls.map(
			( [ request ] ) => request.path
		);

		expect( container.textContent ).toContain(
			'This concert history is private.'
		);
		expect( container.textContent ).not.toContain( 'Past Concert' );
		expect( paths.some( ( path ) => path.includes( '/shows' ) ) ).toBe(
			false
		);

		await act( async () => root.unmount() );
	} );

	it( 'does not authorize a new private profile from an old success', async () => {
		const oldStats = deferred();
		const newStats = deferred();
		apiFetch
			.mockImplementationOnce( () => oldStats.promise )
			.mockImplementationOnce( () => newStats.promise );
		const { container, root, rerenderUser } = await renderApp( {
			isOwn: false,
			tab: 'past',
			userId: 34,
		} );

		expect(
			apiFetch.mock.calls.some( ( [ request ] ) =>
				request.path.includes( '/shows' )
			)
		).toBe( false );

		await rerenderUser( 35 );
		await act( async () =>
			newStats.reject( {
				code: 'concert_history_private',
				message: 'Concert history is private.',
				data: { status: 403 },
			} )
		);
		await act( async () => oldStats.resolve( emptyStats ) );

		expect( container.textContent ).toContain(
			'This concert history is private.'
		);
		expect(
			apiFetch.mock.calls.some( ( [ request ] ) =>
				request.path.includes( '/shows' )
			)
		).toBe( false );

		await act( async () => root.unmount() );
	} );
} );
