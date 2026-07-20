/**
 * ShowList load-more behavior.
 */
/* eslint-env jest */

import { createRoot } from '@wordpress/element';
import { act } from 'react';
import apiFetch from '@wordpress/api-fetch';
import ShowList from './ShowList';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children } ) =>
		React.createElement( 'div', null, children );
	return { ActionRow: Wrapper, InlineStatus: Wrapper };
} );

jest.mock( './ShowCard', () => ( { show } ) => <div>{ show.title }</div> );

describe( 'ShowList', () => {
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

	it( 'appends Upcoming pages and keeps remaining state active', async () => {
		apiFetch.mockImplementation( ( { path } ) => {
			const page = Number(
				new URLSearchParams( path.split( '?' )[ 1 ] ).get( 'page' )
			);
			return Promise.resolve( {
				shows: [ { event_id: page, title: `Upcoming ${ page }` } ],
				total: 2,
				pages: 2,
				page,
			} );
		} );
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		await act( async () => {
			root.render(
				<ShowList userId={ 7 } period="upcoming" year={ 0 } />
			);
			await Promise.resolve();
		} );
		expect( container.textContent ).toContain( 'Upcoming 1' );
		expect( container.textContent ).toContain( 'Load More (1 remaining)' );

		await act( async () => {
			container
				.querySelector( 'button' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain( 'Upcoming 1' );
		expect( container.textContent ).toContain( 'Upcoming 2' );
		expect( container.textContent ).not.toContain( 'Load More' );
		await act( async () => root.unmount() );
	} );
} );
