import { createRoot } from '@wordpress/element';
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

jest.mock( 'wp-native-client', () => {
	const execute = jest.fn();
	return {
		WPNativeClient: jest.fn().mockImplementation( () => ( { execute } ) ),
		mockExecute: execute,
	};
} );
jest.mock( 'wp-native-client/wordpress', () => ( {
	WpApiFetchTransport: jest.fn(),
} ) );
jest.mock( '@extrachill/components', () => ( {
	BlockShell: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	BlockShellInner: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	BlockShellHeader: ( { title }: { title: string } ) => <h1>{ title }</h1>,
	Panel: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	PanelHeader: ( { description }: { description: string } ) => (
		<p>{ description }</p>
	),
	ActionRow: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	FieldGroup: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	ResponsiveTabs: ( {
		renderPanel,
	}: {
		renderPanel: ( id: string ) => React.ReactNode;
	} ) => <div>{ renderPanel( 'subscriptions' ) }</div>,
} ) );
jest.mock( '@wordpress/components', () => ( {
	ComboboxControl: () => <div />,
} ) );
jest.mock( '@extrachill/components/styles/components.scss', () => ( {} ) );

import { UserSettingsApp } from '../../src/blocks/user-settings/view';

const { mockExecute } = jest.requireMock( 'wp-native-client' ) as {
	mockExecute: jest.Mock;
};

const settings = {
	user_id: 7,
	display_name: 'Chubes',
	display_name_options: [ 'Chubes' ],
	email: 'chris@example.com',
	pending_email: null,
	local_scene: null,
	local_scene_visibility: 'public',
	concert_history_visibility: 'public',
	event_attendance_visibility: 'public',
};

function installMocks( identities: Array< Record< string, string > > ) {
	mockExecute.mockImplementation( ( ability: string, input?: unknown ) => {
		if ( ability === 'extrachill/get-user-settings' ) {
			return Promise.resolve( settings );
		}
		if ( ability === 'extrachill/get-user-profile' ) {
			return Promise.resolve( {
				artist_access: { status: 'none', type: '' },
			} );
		}
		if ( ability === 'extrachill/list-entity-subscriptions' ) {
			return Promise.resolve( {
				subscriptions: identities,
				page: 1,
				per_page: 100,
				total: identities.length,
				total_pages: 1,
			} );
		}
		if (
			ability === 'extrachill/community-resolve-subscription-entities'
		) {
			const versionedInput = input as {
				schema_version: string;
				identities: Array< Record< string, string > >;
			};
			const resolvedIdentities = versionedInput.identities;
			return Promise.resolve( {
				schema_version: '1',
				entities: resolvedIdentities.map( ( item ) => ( {
					...item,
					status: item.slug === 'missing' ? 'not_found' : 'resolved',
					name:
						item.slug === 'missing'
							? ''
							: item.slug.replaceAll( '-', ' ' ),
					url:
						item.slug === 'missing'
							? ''
							: `https://example.com/${ item.slug }/`,
					resolved: item.slug !== 'missing',
				} ) ),
			} );
		}
		return Promise.resolve( { success: true } );
	} );
}

async function renderInventory() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render(
			<UserSettingsApp
				artistSiteUrl="https://artist.example.com"
				hasArtists={ false }
				canCreateArtists={ false }
				userId={ 7 }
			/>
		);
	} );
	return { container, root };
}

describe( 'subscription inventory', () => {
	beforeAll( () => {
		(
			globalThis as typeof globalThis & {
				IS_REACT_ACT_ENVIRONMENT: boolean;
			}
		 ).IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterEach( () => {
		mockExecute.mockReset();
		document.body.innerHTML = '';
	} );

	it( 'suppresses retired email-sharing rows while preserving notifications', async () => {
		installMocks( [
			{ entity_type: 'artist', taxonomy: 'artist', slug: 'kid-lake' },
			{
				entity_type: 'festival',
				taxonomy: 'festival',
				slug: 'high-water',
			},
			{
				entity_type: 'venue',
				taxonomy: 'venue',
				slug: 'music-farm',
			},
			{
				entity_type: 'artist-email-sharing',
				taxonomy: 'artist',
				slug: 'retired-artist',
			},
			{
				entity_type: 'venue-email-sharing',
				taxonomy: 'venue',
				slug: 'retired-venue',
			},
		] );
		const { container, root } = await renderInventory();
		expect( container.textContent ).toContain(
			'Extra Chill update subscriptions'
		);
		expect( container.textContent ).toContain( 'kid lake' );
		expect( container.textContent ).toContain( 'high water' );
		expect( container.textContent ).toContain( 'music farm' );
		expect( container.textContent ).not.toContain( 'retired artist' );
		expect( container.textContent ).not.toContain( 'retired venue' );
		expect( container.textContent ).not.toContain(
			'Email-sharing permissions'
		);
		expect( mockExecute ).toHaveBeenCalledWith(
			'extrachill/community-resolve-subscription-entities',
			{
				schema_version: '1',
				identities: [
					{
						entity_type: 'artist',
						taxonomy: 'artist',
						slug: 'kid-lake',
					},
					{
						entity_type: 'festival',
						taxonomy: 'festival',
						slug: 'high-water',
					},
					{
						entity_type: 'venue',
						taxonomy: 'venue',
						slug: 'music-farm',
					},
				],
			}
		);
		expect( mockExecute ).not.toHaveBeenCalledWith(
			'extrachill/get-subscriptions'
		);
		act( () => root.unmount() );
	} );

	it( 'keeps unknown identities removable and routes Local Scene controls', async () => {
		installMocks( [
			{
				entity_type: 'local_scene_digest',
				taxonomy: 'location',
				slug: 'charleston',
			},
			{
				entity_type: 'future-purpose',
				taxonomy: 'artist',
				slug: 'missing',
			},
		] );
		const { container, root } = await renderInventory();
		expect( container.textContent ).toContain(
			'Unavailable identity: future-purpose/artist/missing'
		);
		expect(
			container.querySelector( 'a[href="#tab-notifications"]' )
		).not.toBeNull();
		const buttons = Array.from( container.querySelectorAll( 'button' ) );
		await act(
			async () =>
				buttons
					.find( ( button ) => button.textContent === 'Unsubscribe' )
					?.click()
		);
		expect( mockExecute ).toHaveBeenCalledWith(
			'extrachill/entity-unsubscribe',
			expect.objectContaining( {
				entity_type: 'local_scene_digest',
				slug: 'charleston',
			} )
		);
		act( () => root.unmount() );
	} );

	it( 'shows the empty state and reports listing failures accessibly', async () => {
		installMocks( [] );
		let rendered = await renderInventory();
		expect( rendered.container.textContent ).toContain(
			'You are not subscribed to any entity updates.'
		);
		act( () => rendered.root.unmount() );

		mockExecute.mockImplementation( ( ability: string ) => {
			if ( ability === 'extrachill/get-user-settings' ) {
				return Promise.resolve( settings );
			}
			if ( ability === 'extrachill/get-user-profile' ) {
				return Promise.resolve( {
					artist_access: { status: 'none', type: '' },
				} );
			}
			return Promise.reject( new Error( 'Inventory unavailable' ) );
		} );
		rendered = await renderInventory();
		expect(
			rendered.container.querySelector( '[role="alert"]' )?.textContent
		).toContain( 'Inventory unavailable' );
		act( () => rendered.root.unmount() );
	} );
} );
