import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
// React is supplied by the WordPress test runtime rather than bundled here.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );
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
	ActionRow: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	BlockShell: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	BlockShellInner: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	BlockShellHeader: ( { title }: { title: string } ) => <h1>{ title }</h1>,
	FieldGroup: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	Panel: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	PanelHeader: ( { children }: { children: React.ReactNode } ) => (
		<div>{ children }</div>
	),
	ResponsiveTabs: ( {
		renderPanel,
	}: {
		renderPanel: ( id: string ) => React.ReactNode;
	} ) => <div>{ renderPanel( 'avatar-title' ) }</div>,
} ) );
jest.mock( '@extrachill/components/styles/components.scss', () => ( {} ) );

import { EditProfileApp } from '../../src/blocks/edit-profile/view';

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;
const { mockExecute } = jest.requireMock( 'wp-native-client' ) as {
	mockExecute: jest.Mock;
};

const profile = {
	user_id: 7,
	custom_title: 'Extra Chillian',
	bio: 'Music fan',
	links: [ { type_key: 'website', url: 'https://example.com' } ],
	link_types: { website: 'Website' },
	avatar_url: 'https://example.com/old-avatar.jpg',
	artist_access: { status: 'none', type: '' },
};

async function renderProfile() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	mockExecute.mockImplementation( ( ability: string ) => {
		if ( ability === 'extrachill/get-user-profile' ) {
			return Promise.resolve( profile );
		}
		return Promise.resolve( profile );
	} );

	await act( async () => {
		root.render(
			<EditProfileApp
				artistSiteUrl="https://artist.example.com"
				userId={ 7 }
				profileUrl="https://community.example.com/u/chubes"
				hasArtists={ false }
				canCreateArtists={ false }
			/>
		);
	} );

	return { container, root };
}

function saveButton( container: HTMLElement ) {
	return Array.from( container.querySelectorAll( 'button' ) ).find(
		( button ) => button.textContent === 'Save Profile Changes'
	);
}

function selectFile( input: HTMLInputElement, file: File ) {
	Object.defineProperty( input, 'files', {
		configurable: true,
		value: [ file ],
	} );
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

describe( 'edit profile mutation feedback', () => {
	beforeAll( () => {
		(
			globalThis as typeof globalThis & {
				IS_REACT_ACT_ENVIRONMENT: boolean;
			}
		 ).IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterEach( () => {
		mockExecute.mockReset();
		mockedApiFetch.mockReset();
		document.body.innerHTML = '';
	} );

	it( 'announces an atomic success when profile details and links save', async () => {
		const { container, root } = await renderProfile();

		await act( async () => saveButton( container )?.click() );

		const notice = container.querySelector( '[role="status"]' );
		expect( notice?.textContent ).toContain(
			'Profile details and links updated successfully.'
		);
		expect( notice?.getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( notice?.getAttribute( 'aria-atomic' ) ).toBe( 'true' );

		act( () => root.unmount() );
	} );

	it.each( [
		[
			'extrachill/update-user-links',
			'Profile details were saved, but links were not.',
		],
		[
			'extrachill/update-user-profile',
			'Links were saved, but profile details were not.',
		],
	] )(
		'reports partial persistence when %s fails',
		async ( failed, message ) => {
			const { container, root } = await renderProfile();
			mockExecute.mockImplementation( ( ability: string ) => {
				if ( ability === failed ) {
					return Promise.reject( new Error( 'Service unavailable' ) );
				}
				return Promise.resolve( profile );
			} );

			await act( async () => saveButton( container )?.click() );

			const notice = container.querySelector( '[role="alert"]' );
			expect( notice?.textContent ).toContain( message );
			expect( notice?.getAttribute( 'aria-live' ) ).toBe( 'assertive' );
			expect( notice?.getAttribute( 'aria-atomic' ) ).toBe( 'true' );

			act( () => root.unmount() );
		}
	);

	it( 'reports total failure without clearing editable values', async () => {
		const { container, root } = await renderProfile();
		mockExecute.mockRejectedValue( new Error( 'Offline' ) );

		await act( async () => saveButton( container )?.click() );

		expect(
			container.querySelector( '[role="alert"]' )?.textContent
		).toContain( 'Profile details and links were not saved.' );
		expect(
			container.querySelector< HTMLInputElement >( '#ec-custom-title' )
				?.value
		).toBe( 'Extra Chillian' );

		act( () => root.unmount() );
	} );

	it( 'ignores duplicate save activation while requests are pending', async () => {
		const { container, root } = await renderProfile();
		mockExecute.mockClear();
		let resolveProfile: ( value: typeof profile ) => void = () => {};
		let resolveLinks: ( value: typeof profile ) => void = () => {};
		mockExecute.mockImplementation( ( ability: string ) => {
			if ( ability === 'extrachill/update-user-profile' ) {
				return new Promise( ( resolve ) => {
					resolveProfile = resolve;
				} );
			}
			return new Promise( ( resolve ) => {
				resolveLinks = resolve;
			} );
		} );
		const save = saveButton( container );

		act( () => {
			save?.click();
			save?.click();
		} );

		expect( mockExecute ).toHaveBeenCalledTimes( 2 );
		await act( async () => {
			resolveProfile( profile );
			resolveLinks( profile );
		} );

		act( () => root.unmount() );
	} );

	it( 'announces avatar failure and permits retrying the same file', async () => {
		const { container, root } = await renderProfile();
		const file = new File( [ 'avatar' ], 'avatar.jpg', {
			type: 'image/jpeg',
		} );
		const input = container.querySelector< HTMLInputElement >(
			'#ec-edit-profile-avatar-upload'
		);
		mockedApiFetch
			.mockRejectedValueOnce( new Error( 'Image is too large.' ) )
			.mockResolvedValueOnce( {
				url: 'https://example.com/new-avatar.jpg',
			} as never );

		await act( async () => {
			if ( input ) {
				selectFile( input, file );
			}
		} );

		const error = container.querySelector( '[role="alert"]' );
		expect( error?.textContent ).toContain( 'Image is too large.' );
		expect( error?.textContent ).toContain(
			'choose the image again and retry'
		);
		expect( error?.getAttribute( 'aria-live' ) ).toBe( 'assertive' );
		expect( input?.value ).toBe( '' );

		await act( async () => {
			if ( input ) {
				selectFile( input, file );
			}
		} );

		expect( mockedApiFetch ).toHaveBeenCalledTimes( 2 );
		expect(
			container.querySelector( '[role="status"]' )?.textContent
		).toContain( 'Avatar updated successfully.' );
		expect(
			container.querySelector< HTMLImageElement >( 'img[alt="Avatar"]' )
				?.src
		).toBe( 'https://example.com/new-avatar.jpg' );

		act( () => root.unmount() );
	} );
} );
