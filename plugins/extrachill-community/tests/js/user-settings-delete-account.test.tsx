import { createRoot } from '@wordpress/element';
// React is supplied by the WordPress test runtime rather than bundled here.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

jest.mock( '../../src/abilities', () => {
	const execute = jest.fn();
	return {
		executeAbility: execute,
		mockExecute: execute,
	};
} );
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
	FieldGroup: ( {
		children,
		label,
		htmlFor,
	}: {
		children: React.ReactNode;
		label?: string;
		htmlFor?: string;
	} ) => (
		<div>
			{ label && <label htmlFor={ htmlFor }>{ label }</label> }
			{ children }
		</div>
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

import {
	DeleteAccountSection,
	UserSettingsApp,
	type AccountDeletionStatus,
} from '../../src/blocks/user-settings/view';
import type { UserSettings } from '../../src/types/users';

const { mockExecute } = jest.requireMock( '../../src/abilities' ) as {
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

function mount( element: React.ReactElement ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	return { container, root, element };
}

async function renderSection(
	status: AccountDeletionStatus,
	redirect: jest.Mock = jest.fn()
) {
	const { container, root } = mount( <div /> );
	await act( async () => {
		root.render(
			<DeleteAccountSection
				status={ status }
				homeUrl="https://community.example.com/"
				redirect={ redirect }
				redirectDelayMs={ 0 }
			/>
		);
	} );
	return { container, root, redirect };
}

function typeInto( input: HTMLInputElement, value: string ) {
	const setter = Object.getOwnPropertyDescriptor(
		HTMLInputElement.prototype,
		'value'
	)?.set;
	act( () => {
		setter?.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

function deleteButton( container: HTMLElement ) {
	return Array.from(
		container.querySelectorAll< HTMLButtonElement >( 'button' )
	).find(
		( button ) =>
			button.textContent === 'Delete my account' ||
			button.textContent === 'Deleting...'
	);
}

describe( 'delete account section', () => {
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

	it( 'explains what is deleted and keeps the button disabled until DELETE is typed', async () => {
		const { container, root } = await renderSection( 'allowed' );
		const text = container.textContent ?? '';

		expect( text ).toContain( 'Delete account' );
		expect( text ).toContain( 'permanent' );
		expect( text ).toContain( 'every Extra Chill site' );
		expect( text ).toContain( 'Your profile' );
		expect( text ).toContain( 'Your sign-in on every Extra Chill site' );
		expect( text ).toContain( 'Your posts, forum topics and replies' );
		expect( text ).toContain( 'Your concert history and ratings' );

		const button = deleteButton( container );
		expect( button?.className ).toBe( 'button-danger button-medium' );
		expect( button?.disabled ).toBe( true );

		const input = container.querySelector< HTMLInputElement >(
			'#ec-delete-account-confirm'
		) as HTMLInputElement;
		typeInto( input, 'delete' );
		expect( deleteButton( container )?.disabled ).toBe( true );

		typeInto( input, 'DELETE' );
		expect( deleteButton( container )?.disabled ).toBe( false );
		expect( mockExecute ).not.toHaveBeenCalled();

		act( () => root.unmount() );
	} );

	it( 'runs the ability with the typed confirmation and sends the member home', async () => {
		mockExecute.mockResolvedValue( { deleted: true } );
		const { container, root, redirect } = await renderSection( 'allowed' );
		const input = container.querySelector< HTMLInputElement >(
			'#ec-delete-account-confirm'
		) as HTMLInputElement;

		typeInto( input, 'DELETE' );
		await act( async () => deleteButton( container )?.click() );

		expect( mockExecute ).toHaveBeenCalledTimes( 1 );
		expect( mockExecute ).toHaveBeenCalledWith(
			'extrachill/delete-my-account',
			{ confirm: 'DELETE' }
		);
		expect(
			container.querySelector( '.notice.notice-success' )?.textContent
		).toContain( 'Your account has been deleted' );

		await act(
			() => new Promise< void >( ( resolve ) => setTimeout( resolve, 5 ) )
		);
		expect( redirect ).toHaveBeenCalledWith(
			'https://community.example.com/'
		);

		act( () => root.unmount() );
	} );

	it( 'shows the ability refusal in an error notice and does not redirect', async () => {
		mockExecute.mockRejectedValue( {
			code: 'account_deletion_refused',
			message:
				"Staff accounts can't be deleted here. Please contact the Extra Chill team.",
		} );
		const { container, root, redirect } = await renderSection( 'allowed' );
		const input = container.querySelector< HTMLInputElement >(
			'#ec-delete-account-confirm'
		) as HTMLInputElement;

		typeInto( input, 'DELETE' );
		await act( async () => deleteButton( container )?.click() );

		const notice = container.querySelector( '.notice.notice-error' );
		expect( notice?.getAttribute( 'role' ) ).toBe( 'alert' );
		expect( notice?.textContent ).toContain(
			'Please contact the Extra Chill team.'
		);
		expect( deleteButton( container )?.disabled ).toBe( false );
		expect( redirect ).not.toHaveBeenCalled();

		act( () => root.unmount() );
	} );

	it( 'shows staff a contact note instead of the form', async () => {
		const { container, root } = await renderSection( 'staff' );

		expect( container.textContent ).toContain(
			"Staff accounts can't be deleted here. Please contact the Extra Chill team."
		);
		expect( deleteButton( container ) ).toBeUndefined();
		expect(
			container.querySelector( '#ec-delete-account-confirm' )
		).toBeNull();

		act( () => root.unmount() );
	} );

	it( 'renders nothing when deletion is unavailable', async () => {
		const { container, root } = await renderSection( 'unavailable' );

		expect( container.textContent ).toBe( '' );

		act( () => root.unmount() );
	} );

	it.each( [
		[ 'allowed' as const, true ],
		[ 'unavailable' as const, false ],
	] )(
		'places the section under Account Details when status is %s',
		async ( status, expected ) => {
			mockExecute.mockImplementation( ( ability: string ) => {
				if ( ability === 'extrachill/get-user-profile' ) {
					return Promise.resolve( {
						artist_access: { status: 'none', type: '' },
					} );
				}
				return Promise.resolve( settings );
			} );
			const { container, root } = mount( <div /> );
			await act( async () => {
				root.render(
					<UserSettingsApp
						artistSiteUrl="https://artist.example.com"
						hasArtists={ false }
						canCreateArtists={ false }
						userId={ 7 }
						accountDeletion={ status }
						homeUrl="https://community.example.com/"
					/>
				);
			} );

			expect(
				container.textContent?.includes( 'Save Account Details' )
			).toBe( true );
			expect( deleteButton( container ) !== undefined ).toBe( expected );

			act( () => root.unmount() );
		}
	);
} );
