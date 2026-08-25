/* global afterAll, afterEach, beforeAll, beforeEach, describe, expect, HTMLInputElement, it, jest */

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
import {
	ManagedIdentitySelector,
	PromoterLinkPageManager,
	PromoterWorkspacePanel,
	VenuePromoterRelationships,
} from './managed-workspace';
import { runAbility } from './api';

jest.mock( './api', () => ( {
	runAbility: jest.fn(),
	errorDetails: ( error ) => ( {
		message: error?.message || 'Request failed.',
		status: error?.data?.status || 0,
	} ),
} ) );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children, compact, depth, ...props } ) => {
		void compact;
		void depth;
		return React.createElement( 'div', props, children );
	};
	return {
		Badge: ( { children } ) =>
			React.createElement( 'span', null, children ),
		InlineStatus: Wrapper,
		Panel: Wrapper,
		PanelHeader: ( { title, description } ) =>
			React.createElement(
				'header',
				null,
				React.createElement( 'h2', null, title ),
				React.createElement( 'p', null, description )
			),
	};
} );

async function render( component ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => root.render( component ) );
	return { container, root };
}

const workspace = ( overrides = {} ) => ( {
	selection: {
		reference: 'promoter:100',
		type: 'promoter',
		id: 100,
		state: 'active',
	},
	promoter: {
		reference: 'promoter:100',
		type: 'promoter',
		id: 100,
		name: 'Extra Chill',
		is_owner: true,
		permissions: [ 'access_promoter' ],
		link_page: {
			status: 'available',
			management_url:
				'https://events.example/promoter-settings/?promoter_id=100',
		},
	},
	granted_venues: [
		{
			id: 200,
			name: 'The Royal American',
			action: 'organize_local_support',
			action_label: 'Organize local support',
		},
	],
	...overrides,
} );

const linkDocument = ( overrides = {} ) => ( {
	promoter: { term_id: 100, title: 'Extra Chill' },
	link_page: {
		link_page_id: 55,
		public_url: 'https://extrachill.link/extra-chill/',
		links: [],
		link_sections: [
			{
				id: 'main',
				section_title: 'Main links',
				links: [
					{
						id: 'website',
						link_text: 'Website',
						link_url: 'https://extrachill.com/',
					},
				],
			},
		],
		...overrides,
	},
} );

const flush = async () => {
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
};

const deferred = () => {
	let resolve;
	const promise = new Promise( ( done ) => {
		resolve = done;
	} );
	return { promise, resolve };
};

describe( 'managed identity workspace', () => {
	let previousActEnvironment;
	beforeAll( () => {
		previousActEnvironment = global.IS_REACT_ACT_ENVIRONMENT;
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		if ( previousActEnvironment === undefined ) {
			delete global.IS_REACT_ACT_ENVIRONMENT;
		} else {
			global.IS_REACT_ACT_ENVIRONMENT = previousActEnvironment;
		}
	} );
	beforeEach( () => {
		document.body.innerHTML = '';
		runAbility.mockReset();
		runAbility.mockResolvedValue( linkDocument() );
	} );
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'renders a distinct labelled identity selector with typed groups', async () => {
		const onChange = jest.fn();
		const { container, root } = await render(
			<ManagedIdentitySelector
				identities={ [
					{
						reference: 'venue:200',
						type: 'venue',
						name: 'Royal American',
					},
					{
						reference: 'promoter:100',
						type: 'promoter',
						name: 'Extra Chill',
					},
				] }
				selectedReference="promoter:100"
				onChange={ onChange }
			/>
		);
		const section = container.querySelector( '.ec-managed-identity' );
		const select = container.querySelector( '#ec-managed-identity-select' );
		expect( section.getAttribute( 'aria-labelledby' ) ).toBe(
			'ec-managed-identity-heading'
		);
		expect( select.value ).toBe( 'promoter:100' );
		expect(
			[ ...select.querySelectorAll( 'optgroup' ) ].map(
				( group ) => group.label
			)
		).toEqual( [ 'Venue identities', 'Promoter organizations' ] );
		await act( async () => {
			select.value = 'venue:200';
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		expect( onChange ).toHaveBeenCalledWith( 'venue:200' );
		await act( async () => root.unmount() );
	} );

	it( 'renders only exact promoter grants and the venue-owned boundary', async () => {
		const { container, root } = await render(
			<PromoterWorkspacePanel workspace={ workspace() } />
		);
		expect( container.textContent ).toContain( 'Extra Chill' );
		expect( container.textContent ).toContain( 'The Royal American' );
		expect( container.textContent ).toContain( 'Organize local support' );
		expect( container.textContent ).toContain(
			'private booking data, team access, and finances remain venue-owned'
		);
		expect( container.textContent ).not.toContain( 'Booking inbox' );
		await flush();
		expect( container.querySelector( 'a' ).textContent ).toContain(
			'Open public Link Page'
		);
		expect(
			container.querySelector( '#promoter-link-page' )
		).not.toBeNull();
		await act( async () => root.unmount() );
	} );

	it( 'loads the current document and opens its public URL', async () => {
		const { container, root } = await render(
			<PromoterLinkPageManager
				promoterId={ 100 }
				initialStatus="available"
			/>
		);
		await flush();
		expect( runAbility ).toHaveBeenCalledWith(
			'extrachill/get-promoter-link-page',
			{ promoter_term_id: 100 }
		);
		const publicLink = container.querySelector(
			'a[href="https://extrachill.link/extra-chill/"]'
		);
		expect( publicLink.textContent ).toContain( 'Open public Link Page' );
		expect( publicLink.target ).toBe( '_blank' );
		await act( async () => root.unmount() );
	} );

	it( 'provisions an absent promoter Link Page without an initial read', async () => {
		const { container, root } = await render(
			<PromoterLinkPageManager
				promoterId={ 100 }
				initialStatus="not_provisioned"
			/>
		);
		expect( runAbility ).not.toHaveBeenCalled();
		await act( async () => {
			[ ...container.querySelectorAll( 'button' ) ]
				.find( ( button ) => button.textContent === 'Create Link Page' )
				.click();
		} );
		await flush();
		expect( runAbility ).toHaveBeenCalledWith(
			'extrachill/provision-promoter-link-page',
			{ promoter_term_id: 100 }
		);
		expect( container.textContent ).toContain( 'Link Page created.' );
		await act( async () => root.unmount() );
	} );

	it( 'shows a load error and retries the existing read ability', async () => {
		runAbility.mockRejectedValueOnce( new Error( 'Link Page offline.' ) );
		const { container, root } = await render(
			<PromoterLinkPageManager
				promoterId={ 100 }
				initialStatus="available"
			/>
		);
		await flush();
		expect(
			container.querySelector( '[role="alert"]' ).textContent
		).toContain( 'Link Page offline.' );
		runAbility.mockResolvedValueOnce( linkDocument() );
		await act( async () => {
			[ ...container.querySelectorAll( 'button' ) ]
				.find( ( button ) => button.textContent === 'Retry' )
				.click();
		} );
		await flush();
		expect( runAbility ).toHaveBeenCalledTimes( 2 );
		expect( container.textContent ).toContain( 'Open public Link Page' );
		await act( async () => root.unmount() );
	} );

	it( 'does not call an absent ability when the runtime is unavailable', async () => {
		const { container, root } = await render(
			<PromoterLinkPageManager
				promoterId={ 100 }
				initialStatus="unavailable"
			/>
		);
		await flush();
		expect( runAbility ).not.toHaveBeenCalled();
		expect( container.textContent ).toContain(
			'Link Page management is unavailable because its runtime is not active.'
		);
		expect(
			[ ...container.querySelectorAll( 'button' ) ].some(
				( button ) => button.textContent === 'Retry'
			)
		).toBe( false );
		await act( async () => root.unmount() );
	} );

	it( 'reports dirty edits and disables all editor controls during save', async () => {
		const onDirtyChange = jest.fn();
		const pending = deferred();
		const { container, root } = await render(
			<PromoterLinkPageManager
				promoterId={ 100 }
				initialStatus="available"
				onDirtyChange={ onDirtyChange }
			/>
		);
		await flush();
		expect( onDirtyChange ).toHaveBeenLastCalledWith( false );
		const textInput = container.querySelector(
			'input[type="text"][required]'
		);
		await act( async () => {
			Object.getOwnPropertyDescriptor(
				HTMLInputElement.prototype,
				'value'
			).set.call( textInput, 'Pending title' );
			textInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		expect( onDirtyChange ).toHaveBeenLastCalledWith( true );
		runAbility.mockReturnValueOnce( pending.promise );
		await act( async () => {
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
			await Promise.resolve();
		} );
		const controls = container.querySelector(
			'.ec-promoter-link-page-manager__controls'
		);
		expect( controls.disabled ).toBe( true );
		expect( textInput.matches( ':disabled' ) ).toBe( true );
		expect( onDirtyChange ).toHaveBeenLastCalledWith( true );
		await act( async () => pending.resolve( linkDocument() ) );
		expect( onDirtyChange ).toHaveBeenLastCalledWith( false );
		await act( async () => root.unmount() );
	} );

	it( 'edits and saves bounded promoter links through the existing ability', async () => {
		const { container, root } = await render(
			<PromoterLinkPageManager
				promoterId={ 100 }
				initialStatus="available"
			/>
		);
		await flush();
		const textInput = container.querySelector(
			'input[type="text"][required]'
		);
		await act( async () => {
			Object.getOwnPropertyDescriptor(
				HTMLInputElement.prototype,
				'value'
			).set.call( textInput, 'Official website' );
			textInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		runAbility.mockResolvedValueOnce(
			linkDocument( {
				link_sections: [
					{
						id: 'main',
						section_title: 'Main links',
						links: [
							{
								id: 'website',
								link_text: 'Official website',
								link_url: 'https://extrachill.com/',
							},
						],
					},
				],
			} )
		);
		await act( async () => {
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
		} );
		await flush();
		expect( runAbility ).toHaveBeenLastCalledWith(
			'extrachill/save-promoter-link-page-links',
			expect.objectContaining( {
				promoter_term_id: 100,
				links: [
					expect.objectContaining( {
						links: [
							expect.objectContaining( {
								link_text: 'Official website',
							} ),
						],
					} ),
				],
			} )
		);
		expect( container.textContent ).toContain( 'Links saved.' );
		await act( async () => root.unmount() );
	} );

	it( 'announces no grants, stale selection, and denied selection explicitly', async () => {
		const empty = await render(
			<PromoterWorkspacePanel
				workspace={ workspace( { granted_venues: [] } ) }
			/>
		);
		expect( empty.container.textContent ).toContain(
			'no active venue grants'
		);
		expect(
			empty.container.querySelector( '[role="status"]' )
		).not.toBeNull();
		await act( async () => empty.root.unmount() );
		runAbility.mockReset();

		const stale = await render(
			<PromoterWorkspacePanel
				workspace={ workspace( {
					selection: {
						reference: 'promoter:100',
						type: 'promoter',
						state: 'stale',
					},
					promoter: null,
				} ) }
			/>
		);
		expect( stale.container.textContent ).toContain( 'no longer active' );
		expect( runAbility ).not.toHaveBeenCalled();
		await act( async () => stale.root.unmount() );
		runAbility.mockReset();

		const denied = await render(
			<PromoterWorkspacePanel
				workspace={ workspace( {
					selection: {
						reference: 'promoter:101',
						type: 'promoter',
						state: 'denied',
					},
					promoter: null,
				} ) }
			/>
		);
		expect(
			denied.container.querySelector( '[role="alert"]' )
		).not.toBeNull();
		expect( denied.container.textContent ).toContain(
			'do not have access'
		);
		expect( runAbility ).not.toHaveBeenCalled();
		await act( async () => denied.root.unmount() );
	} );

	it( 'renders direct-owner promoter relationships as a read-only section', async () => {
		const { container, root } = await render(
			<VenuePromoterRelationships
				relationships={ [
					{
						promoter_term_id: 100,
						promoter_name: 'Extra Chill',
						action: 'organize_local_support',
						action_label: 'Organize local support',
						status: 'active',
					},
				] }
			/>
		);
		expect(
			container
				.querySelector( 'section' )
				.getAttribute( 'aria-labelledby' )
		).toBe( 'venue-promoter-collaborations-heading' );
		expect( container.textContent ).toContain(
			'Read-only delegated relationships'
		);
		await act( async () => root.unmount() );
	} );
} );
