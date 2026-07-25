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

describe( 'artist email access settings', () => {
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

	it( 'uses private email-consent language and the canonical response field', async () => {
		mockExecute.mockImplementation( ( ability: string ) => {
			if ( ability === 'extrachill/get-user-settings' ) {
				return Promise.resolve( {
					user_id: 7,
					display_name: 'Chubes',
					display_name_options: [ 'Chubes' ],
					email: 'chris@example.com',
					pending_email: null,
					local_scene: null,
					local_scene_visibility: 'public',
					concert_history_visibility: 'public',
					event_attendance_visibility: 'public',
				} );
			}
			if ( ability === 'extrachill/get-user-profile' ) {
				return Promise.resolve( {
					artist_access: { status: 'none', type: '' },
				} );
			}
			if ( ability === 'extrachill/get-subscriptions' ) {
				return Promise.resolve( {
					user_id: 7,
					artist_email_consents: [
						{
							artist_id: 42,
							name: 'Kid Lake',
							url: 'https://artist.example.com/kid-lake/',
							email_consent: true,
						},
					],
				} );
			}
			return Promise.resolve( { success: true } );
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

		expect( container.textContent ).toContain(
			'Choose which artists may access your email'
		);
		expect( container.textContent ).toContain(
			'Share my email with Kid Lake'
		);
		expect( container.textContent ).not.toMatch(
			/\bfollow(?:er|ers|ing|s|ed)?\b/i
		);

		const checkbox =
			container.querySelector< HTMLInputElement >( '#ec-consent-42' );
		const save = Array.from( container.querySelectorAll( 'button' ) ).find(
			( button ) => button.textContent === 'Save Preferences'
		);

		act( () => checkbox?.click() );
		await act( async () => save?.click() );

		expect( mockExecute ).toHaveBeenLastCalledWith(
			'extrachill/update-subscriptions',
			{ consented_artists: [] }
		);
		expect( mockExecute.mock.calls.at( -1 )?.[ 1 ] ).not.toHaveProperty(
			'user_id'
		);

		act( () => root.unmount() );
	} );
} );
