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
	PanelHeader: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
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
	} ) => <div>{ renderPanel( 'account-details' ) }</div>,
} ) );
jest.mock( '@wordpress/components', () => ( {
	ComboboxControl: () => <div />,
} ) );
jest.mock( '@extrachill/components/styles/components.scss', () => ( {} ) );

import { UserSettingsApp } from '../../src/blocks/user-settings/view';
import type { UserSettings } from '../../src/types/users';

const { mockExecute } = jest.requireMock( 'wp-native-client' ) as {
	mockExecute: jest.Mock;
};

const settings: UserSettings = {
	user_id: 7,
	first_name: 'Chris',
	last_name: 'Huber',
	display_name: 'Chubes',
	display_name_options: [ 'Chubes' ],
	email: 'chris@example.com',
	pending_email: null,
	local_scene: null,
	local_scene_visibility: 'public',
	concert_history_visibility: 'public',
	event_attendance_visibility: 'public',
};

async function renderSettings( {
	profileFails = false,
	updateFails = false,
}: {
	profileFails?: boolean;
	updateFails?: boolean;
} = {} ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	mockExecute.mockImplementation( ( ability: string ) => {
		if ( ability === 'extrachill/get-user-settings' ) {
			return Promise.resolve( settings );
		}
		if ( ability === 'extrachill/get-user-profile' ) {
			if ( profileFails ) {
				return Promise.reject( new Error( 'Profile unavailable' ) );
			}
			return Promise.resolve( {
				artist_access: { status: 'none', type: '' },
			} );
		}
		if ( ability === 'extrachill/update-user-settings' && updateFails ) {
			return Promise.reject( new Error( 'Settings update failed' ) );
		}
		return Promise.resolve( settings );
	} );

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

describe( 'concert privacy settings', () => {
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

	it( 'loads both independent visibility controls', async () => {
		const { container, root } = await renderSettings();
		const history = container.querySelector< HTMLInputElement >(
			'#ec-concert-history-visibility'
		);
		const attendance = container.querySelector< HTMLInputElement >(
			'#ec-event-attendance-visibility'
		);

		expect( history?.checked ).toBe( true );
		expect( attendance?.checked ).toBe( true );
		expect( history?.getAttribute( 'aria-describedby' ) ).toBe(
			'ec-concert-history-visibility-help'
		);
		expect( attendance?.getAttribute( 'aria-describedby' ) ).toBe(
			'ec-event-attendance-visibility-help'
		);
		expect(
			container.querySelector( '#ec-concert-history-visibility-help' )
		).not.toBeNull();
		expect(
			container.querySelector( '#ec-event-attendance-visibility-help' )
		).not.toBeNull();
		expect( container.textContent ).toContain(
			'Show my concert history publicly'
		);
		expect( container.textContent ).toContain(
			'Show me in event attendee lists'
		);
		expect( mockExecute ).toHaveBeenCalledWith(
			'extrachill/get-user-settings'
		);

		act( () => root.unmount() );
	} );

	it( 'loads privacy settings when the optional profile request fails', async () => {
		const { container, root } = await renderSettings( {
			profileFails: true,
		} );

		expect(
			container.querySelector( '#ec-concert-history-visibility' )
		).not.toBeNull();
		expect( container.textContent ).not.toContain( 'Profile unavailable' );

		act( () => root.unmount() );
	} );

	it.each( [
		[ 'history', '#ec-concert-history-visibility', 'private', 'public' ],
		[
			'attendance',
			'#ec-event-attendance-visibility',
			'public',
			'private',
		],
	] )(
		'saves the %s toggle without changing the other setting',
		async ( _label, selector, historyValue, attendanceValue ) => {
			const { container, root } = await renderSettings();
			const toggle =
				container.querySelector< HTMLInputElement >( selector );
			const save = Array.from(
				container.querySelectorAll( 'button' )
			).find(
				( button ) => button.textContent === 'Save Account Details'
			);

			act( () => toggle?.click() );
			await act( async () => save?.click() );

			expect( mockExecute ).toHaveBeenLastCalledWith(
				'extrachill/update-user-settings',
				expect.objectContaining( {
					concert_history_visibility: historyValue,
					event_attendance_visibility: attendanceValue,
				} )
			);
			const payload = mockExecute.mock.calls.at( -1 )?.[ 1 ];
			expect( payload ).not.toHaveProperty( 'email' );
			expect( payload ).not.toHaveProperty( 'user_id' );
			expect( payload ).not.toHaveProperty( 'local_scene' );
			const notice = container.querySelector( '[role="status"]' );
			expect( notice?.getAttribute( 'aria-live' ) ).toBe( 'polite' );
			expect( notice?.getAttribute( 'aria-atomic' ) ).toBe( 'true' );

			act( () => root.unmount() );
		}
	);

	it( 'announces a save failure as an assertive alert', async () => {
		const { container, root } = await renderSettings( {
			updateFails: true,
		} );
		const save = Array.from( container.querySelectorAll( 'button' ) ).find(
			( button ) => button.textContent === 'Save Account Details'
		);

		await act( async () => save?.click() );

		const notice = container.querySelector( '[role="alert"]' );
		expect( notice?.textContent ).toContain( 'Settings update failed' );
		expect( notice?.getAttribute( 'aria-live' ) ).toBe( 'assertive' );
		expect( notice?.getAttribute( 'aria-atomic' ) ).toBe( 'true' );

		act( () => root.unmount() );
	} );
} );
