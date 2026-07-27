import { createRoot } from '@wordpress/element';
// React is supplied by the WordPress test runtime rather than bundled here.
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
	} ) => <div>{ renderPanel( 'notifications' ) }</div>,
} ) );
jest.mock( '@wordpress/components', () => ( {
	ComboboxControl: () => <div />,
} ) );
jest.mock( '@extrachill/components/styles/components.scss', () => ( {} ) );

import { UserSettingsApp } from '../../src/blocks/user-settings/view';

const { mockExecute } = jest.requireMock( 'wp-native-client' ) as {
	mockExecute: jest.Mock;
};

function settings( hasLocalScene: boolean ) {
	return {
		user_id: 7,
		first_name: 'Chris',
		last_name: 'Huber',
		display_name: 'Chubes',
		display_name_options: [ 'Chubes' ],
		email: 'chris@example.com',
		pending_email: null,
		local_scene: hasLocalScene
			? {
					term_id: 88,
					name: 'Charleston',
					slug: 'charleston-sc',
					url: 'https://events.example.com/location/charleston-sc/',
					coordinates: null,
			  }
			: null,
		local_scene_visibility: 'public',
		concert_history_visibility: 'public',
		event_attendance_visibility: 'public',
	};
}

async function renderSettings( hasLocalScene = true, subscribed = false ) {
	mockExecute.mockImplementation( ( ability: string ) => {
		if ( ability === 'extrachill/get-user-settings' ) {
			return Promise.resolve( settings( hasLocalScene ) );
		}
		if ( ability === 'extrachill/get-user-profile' ) {
			return Promise.resolve( {
				artist_access: { status: 'none', type: '' },
			} );
		}
		if ( ability === 'extrachill/get-notification-preferences' ) {
			return Promise.resolve( {
				user_id: 7,
				emails_enabled: true,
				auto_subscribe_replies: true,
			} );
		}
		if ( ability === 'extrachill/entity-subscription-status' ) {
			return Promise.resolve( { subscribed } );
		}
		return Promise.resolve( { success: true, subscribed: true } );
	} );

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

describe( 'Local Scene digest settings', () => {
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

	it( 'loads and explicitly subscribes the saved Local Scene', async () => {
		const { container, root } = await renderSettings();
		const digest = container.querySelector< HTMLInputElement >(
			'#ec-local-scene-digest'
		);
		const save = Array.from( container.querySelectorAll( 'button' ) ).find(
			( button ) => button.textContent === 'Save Preferences'
		);

		expect( digest?.checked ).toBe( false );
		expect( container.textContent ).toContain(
			'Weekly Charleston Local Scene digest'
		);
		expect( container.textContent ).toContain(
			'Email delivery also requires Email notifications above.'
		);
		expect( mockExecute ).toHaveBeenCalledWith(
			'extrachill/entity-subscription-status',
			{
				entity_type: 'local_scene_digest',
				taxonomy: 'location',
				slug: 'charleston-sc',
			}
		);

		act( () => digest?.click() );
		await act( async () => save?.click() );

		expect( mockExecute ).toHaveBeenCalledWith(
			'extrachill/entity-subscribe',
			{
				entity_type: 'local_scene_digest',
				taxonomy: 'location',
				slug: 'charleston-sc',
			}
		);

		act( () => root.unmount() );
	} );

	it( 'links to Account Details without creating consent when no scene exists', async () => {
		const { container, root } = await renderSettings( false );

		expect(
			container.querySelector( '#ec-local-scene-digest' )
		).toBeNull();
		expect(
			container.querySelector< HTMLAnchorElement >(
				'a[href="#tab-account-details"]'
			)
		).not.toBeNull();
		expect( mockExecute ).not.toHaveBeenCalledWith(
			'extrachill/entity-subscription-status',
			expect.anything()
		);

		act( () => root.unmount() );
	} );

	it( 'unsubscribes an existing archive-created consent row', async () => {
		const { container, root } = await renderSettings( true, true );
		const digest = container.querySelector< HTMLInputElement >(
			'#ec-local-scene-digest'
		);
		const save = Array.from( container.querySelectorAll( 'button' ) ).find(
			( button ) => button.textContent === 'Save Preferences'
		);

		expect( digest?.checked ).toBe( true );
		act( () => digest?.click() );
		await act( async () => save?.click() );

		expect( mockExecute ).toHaveBeenCalledWith(
			'extrachill/entity-unsubscribe',
			{
				entity_type: 'local_scene_digest',
				taxonomy: 'location',
				slug: 'charleston-sc',
			}
		);

		act( () => root.unmount() );
	} );
} );
