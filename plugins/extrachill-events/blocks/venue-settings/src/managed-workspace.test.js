/* global afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, jest */

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
	PromoterWorkspacePanel,
	SharedLinkPageEditor,
	VenuePromoterRelationships,
} from './managed-workspace';
import { runAbility } from './api';

jest.mock( './api', () => ( {
	runAbility: jest.fn(),
	errorDetails: ( error ) => ( {
		message: error?.message || 'Request failed.',
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
			React.createElement( 'header', null, title, description ),
	};
} );

const render = async ( component ) => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => root.render( component ) );
	return { container, root };
};

const documentResponse = ( type ) => ( {
	[ type ]: {
		title: 'Example identity',
		snapshot: { image_url: '', social_links: [] },
	},
	link_page: {
		link_page_id: 55,
		public_url: 'https://extrachill.link/example/',
		link_sections: [],
		css_vars: {},
		settings: {},
		bio: '',
		background_image_id: 0,
		background_image_url: '',
		revision: 'a'.repeat( 64 ),
	},
} );

describe( 'managed identity workspace', () => {
	let mounted;
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		document.body.innerHTML = '';
		runAbility.mockReset();
		window.ecLinkPageEditorAdapters = {};
		window.ExtraChillLinkPageEditor = {
			registerAdapter: jest.fn( ( name, adapter ) => {
				window.ecLinkPageEditorAdapters[ name ] = adapter;
			} ),
			mount: jest.fn( ( target, configuration ) => {
				mounted = { target, configuration };
				return jest.fn();
			} ),
		};
	} );
	afterEach( () => {
		delete window.ExtraChillLinkPageEditor;
		delete window.ecLinkPageEditorAdapters;
	} );

	it( 'renders typed identity choices', async () => {
		const onChange = jest.fn();
		const { container, root } = await render(
			<ManagedIdentitySelector
				identities={ [
					{ reference: 'venue:20', type: 'venue', name: 'Room' },
					{
						reference: 'promoter:10',
						type: 'promoter',
						name: 'Crew',
					},
				] }
				selectedReference="promoter:10"
				onChange={ onChange }
			/>
		);
		expect(
			[ ...container.querySelectorAll( 'optgroup' ) ].map(
				( item ) => item.label
			)
		).toEqual( [ 'Venue identities', 'Promoter organizations' ] );
		await act( async () => root.unmount() );
	} );

	it.each( [ 'promoter', 'venue' ] )(
		'adapts the shared editor for a %s context',
		async ( type ) => {
			const onDirtyChange = jest.fn();
			const { root } = await render(
				<SharedLinkPageEditor
					identityType={ type }
					identityId={ 10 }
					identityName="Example identity"
					initialStatus="available"
					onDirtyChange={ onDirtyChange }
				/>
			);
			const adapter =
				window.ecLinkPageEditorAdapters[
					mounted.configuration.adapter
				];
			runAbility.mockResolvedValue( documentResponse( type ) );
			await adapter.read();
			expect( runAbility ).toHaveBeenLastCalledWith(
				`extrachill/get-${ type }-link-page`,
				{ [ `${ type }_term_id` ]: 10 }
			);
			runAbility.mockClear();
			await adapter.save(
				10,
				{
					page: {
						revision: 'a'.repeat( 64 ),
						links: [],
						styles: { '--link-page-background-color': '#000000' },
						settings: { redirect_enabled: false },
						backgroundImageId: 0,
					},
				},
				{ dirtyAreas: [ 'styles', 'links' ] }
			);
			expect( runAbility ).toHaveBeenCalledTimes( 1 );
			expect( runAbility ).toHaveBeenCalledWith(
				`extrachill/patch-${ type }-link-page`,
				expect.objectContaining( {
					[ `${ type }_term_id` ]: 10,
					expected_revision: 'a'.repeat( 64 ),
					links: [],
					css_vars: { '--link-page-background-color': '#000000' },
				} )
			);
			expect(
				runAbility.mock.calls.some(
					( [ name ] ) =>
						name === `extrachill/save-${ type }-link-page`
				)
			).toBe( false );
			expect( mounted.configuration.capabilities ).toEqual(
				expect.objectContaining( {
					identity: false,
					bio: false,
					socials: false,
				} )
			);
			adapter.onDirtyChange( true );
			expect( onDirtyChange ).toHaveBeenCalledWith( true );
			await act( async () => root.unmount() );
		}
	);

	it( 'waits for either script order then fails closed after a bounded timeout', async () => {
		jest.useFakeTimers();
		delete window.ExtraChillLinkPageEditor;
		const { container, root } = await render(
			<SharedLinkPageEditor
				identityType="venue"
				identityId={ 10 }
				identityName="Example identity"
				initialStatus="available"
			/>
		);
		expect( container.textContent ).toContain( 'Loading Link Page editor' );
		await act( async () => {
			jest.advanceTimersByTime( 3100 );
			await Promise.resolve();
		} );
		expect( container.textContent ).toContain(
			'Link Page management is unavailable'
		);
		await act( async () => root.unmount() );
		jest.useRealTimers();
	} );

	it( 'keeps restricted workspace resources out of promoter mode', async () => {
		const { container, root } = await render(
			<PromoterWorkspacePanel
				workspace={ {
					selection: { state: 'active' },
					promoter: {
						id: 10,
						name: 'Crew',
						link_page: { status: 'available' },
					},
					granted_venues: [],
				} }
			/>
		);
		expect( container.textContent ).toContain( 'private booking data' );
		expect( container.textContent ).not.toContain( 'Booking inbox' );
		expect( container.textContent ).not.toContain( 'Finance' );
		await act( async () => root.unmount() );
	} );

	it( 'renders relationships as read-only records', async () => {
		const { container, root } = await render(
			<VenuePromoterRelationships
				relationships={ [
					{
						promoter_term_id: 10,
						promoter_name: 'Crew',
						action: 'organize',
						action_label: 'Organize',
						status: 'active',
					},
				] }
			/>
		);
		expect( container.textContent ).toContain(
			'Read-only delegated relationships'
		);
		expect( container.querySelector( 'button' ) ).toBeNull();
		await act( async () => root.unmount() );
	} );
} );
