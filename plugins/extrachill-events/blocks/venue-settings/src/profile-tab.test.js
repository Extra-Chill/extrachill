/* global afterAll, beforeAll, beforeEach, describe, expect, it, jest */

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
import { ProfileTab, visibleProfileFields } from './profile-tab';
import { profileChanges } from './state';

jest.mock( './status', () => ( {
	__esModule: true,
	Status: () => null,
} ) );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children, className } ) =>
		React.createElement( 'div', { className }, children );
	return {
		ActionRow: Wrapper,
		Panel: Wrapper,
		PanelHeader: ( { title } ) => React.createElement( 'h2', null, title ),
		FieldGroup: ( { children, label } ) =>
			React.createElement(
				'div',
				null,
				React.createElement( 'span', null, label ),
				children
			),
	};
} );

const profile = ( extra = {} ) => ( {
	term_id: 1524,
	name: 'Lo-Fi Brewing',
	description: '',
	address: '',
	city: '',
	state: '',
	zip: '',
	country: '',
	phone: '',
	website: '',
	ticketing_url: '',
	capacity: '',
	logo_attachment_id: 0,
	logo: null,
	revision: '0'.repeat( 64 ),
	...extra,
} );

async function renderTab( doc ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () =>
		root.render(
			<ProfileTab
				profile={ doc }
				baseline={ doc }
				setProfile={ jest.fn() }
				onSave={ jest.fn() }
				saving={ false }
				status={ null }
			/>
		)
	);
	return container;
}

describe( 'venue profile tab', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'renders the Instagram handle the canonical profile carries', async () => {
		const container = await renderTab(
			profile( { instagram: 'lofibrewing' } )
		);

		expect(
			container.querySelector( '#venue-profile-instagram' ).value
		).toBe( 'lofibrewing' );
	} );

	it( 'omits the Instagram field when the profile does not carry it', async () => {
		const container = await renderTab( profile() );

		expect( container.querySelector( '#venue-profile-instagram' ) ).toBe(
			null
		);
		expect(
			visibleProfileFields( profile() ).map( ( [ key ] ) => key )
		).not.toContain( 'instagram' );
	} );

	it( 'sends only a changed Instagram handle as a profile change', () => {
		const baseline = profile( { instagram: 'lofibrewing' } );

		expect(
			profileChanges(
				{ ...baseline, instagram: '@lofi.brewing' },
				baseline
			)
		).toEqual( { instagram: '@lofi.brewing' } );
	} );
} );
