/* global beforeAll, describe, expect, it, jest */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import { ClaimPanel } from './claims-tab';

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
		FieldGroup: ( { label, children } ) =>
			React.createElement( 'label', null, label, children ),
		Badge: Wrapper,
		InlineStatus: Wrapper,
		Panel: Wrapper,
		PanelHeader: ( { title, description } ) =>
			React.createElement( 'header', null, title, ' ', description ),
	};
} );

beforeAll( () => {
	global.IS_REACT_ACT_ENVIRONMENT = true;
} );

const render = async ( props ) => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => root.render( <ClaimPanel { ...props } /> ) );
	return { container, root };
};

const venues = [
	{ id: 11, name: 'A Venue Someone Else Runs' },
	{ id: 22, name: 'The Royal American' },
];

describe( 'ClaimPanel', () => {
	it( 'starts with no venue chosen, so nobody claims the first one by accident', async () => {
		const { container, root } = await render( { venues } );
		expect( container.querySelector( '#venue-claim-select' ).value ).toBe(
			'0'
		);
		expect(
			container.querySelector( 'button[type="submit"]' ).disabled
		).toBe( true );
		expect( container.textContent ).toContain( 'Claim your venue' );
		expect( container.textContent ).not.toContain( 'canonical' );
		await act( async () => root.unmount() );
	} );

	it( 'keeps a requested venue preselected', async () => {
		const { container, root } = await render( {
			venues,
			initialVenueId: 22,
		} );
		expect( container.querySelector( '#venue-claim-select' ).value ).toBe(
			'22'
		);
		await act( async () => root.unmount() );
	} );

	it( 'offers a way out when the venue is not listed', async () => {
		const { container, root } = await render( {
			venues,
			missingVenueUrl:
				'https://extrachill.com/contact-us/?subject=venue-link-page',
		} );
		const link = container.querySelector( '.ec-venue-claim__missing a' );
		expect( link.getAttribute( 'href' ) ).toBe(
			'https://extrachill.com/contact-us/?subject=venue-link-page'
		);
		await act( async () => root.unmount() );
	} );
} );
