import { createRoot } from '@wordpress/element';
// React is supplied by the WordPress test runtime rather than bundled here.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

jest.mock( '../../src/abilities', () => ( { executeAbility: jest.fn() } ) );
jest.mock( '@wordpress/api-fetch', () => {
	const fetch = jest.fn();
	return { __esModule: true, default: fetch, mockApiFetch: fetch };
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
	ResponsiveTabs: () => <div />,
} ) );
jest.mock( '@wordpress/components', () => ( {
	ComboboxControl: () => <div />,
} ) );
jest.mock( '@extrachill/components/styles/components.scss', () => ( {} ) );

import {
	TwoStepSignInSection,
	type TwoStepRequest,
} from '../../src/blocks/user-settings/two-step-sign-in';
import { SecurityTab } from '../../src/blocks/user-settings/view';
import {
	backupCodesDownloadHref,
	formatSecret,
	isRevalidationRequired,
	isRouteMissing,
	parseProviderSettings,
	providerUpdate,
	runWithRevalidation,
	safeOtpauthUri,
	securityError,
	statusLine,
	settingsSummary,
} from '../../src/blocks/user-settings/two-step-model';
import type { UserSettings } from '../../src/types/users';

const { mockApiFetch } = jest.requireMock( '@wordpress/api-fetch' ) as {
	mockApiFetch: jest.Mock;
};

const PROVIDERS = 'two-factor/1.0/users/7/providers';

type Row = {
	key: string;
	label: string;
	configured?: boolean;
	enabled?: boolean;
	primary?: boolean;
	remaining?: number | null;
};

function providersResponse( rows: Row[] ) {
	return {
		user_id: 7,
		providers: rows.map( ( row ) => ( {
			supported: true,
			configured: false,
			enabled: false,
			primary: false,
			remaining: null,
			...row,
		} ) ),
		revalidation: { required: false },
	};
}

const OFF = providersResponse( [
	{ key: 'Two_Factor_Totp', label: 'Authenticator app' },
	{ key: 'Two_Factor_Email', label: 'Email', configured: true },
	{ key: 'Two_Factor_Backup_Codes', label: 'Recovery Codes' },
] );

const APP_ON = providersResponse( [
	{
		key: 'Two_Factor_Totp',
		label: 'Authenticator app',
		configured: true,
		enabled: true,
		primary: true,
	},
	{ key: 'Two_Factor_Email', label: 'Email', configured: true },
	{
		key: 'Two_Factor_Backup_Codes',
		label: 'Recovery Codes',
		configured: true,
		enabled: true,
		remaining: 9,
	},
] );

const EMAIL_ON = providersResponse( [
	{ key: 'Two_Factor_Totp', label: 'Authenticator app' },
	{
		key: 'Two_Factor_Email',
		label: 'Email',
		configured: true,
		enabled: true,
		primary: true,
	},
	{ key: 'Two_Factor_Backup_Codes', label: 'Recovery Codes' },
] );

/**
 * Fake REST server: responses by "METHOD path", consumed in order.
 * @param routes Queued responses keyed by "METHOD path".
 */
function fakeRequest( routes: Record< string, unknown[] > ) {
	const calls: TwoStepRequest[] = [];
	const request = jest.fn( ( req: TwoStepRequest ) => {
		calls.push( req );
		const queue = routes[ `${ req.method } ${ req.path }` ];
		if ( ! queue || queue.length === 0 ) {
			return Promise.reject( {
				code: 'unexpected',
				data: { status: 500 },
			} );
		}
		const next = queue.length > 1 ? queue.shift() : queue[ 0 ];
		if ( next && typeof next === 'object' && 'reject' in next ) {
			return Promise.reject( ( next as { reject: unknown } ).reject );
		}
		return Promise.resolve( next );
	} );
	return { request: request as never, calls };
}

async function render( element: React.ReactElement ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( element );
	} );
	return { container, root };
}

function button( container: HTMLElement, label: string ) {
	return Array.from(
		container.querySelectorAll< HTMLButtonElement >( 'button' )
	).find( ( candidate ) => candidate.textContent === label );
}

async function click( container: HTMLElement, label: string ) {
	const target = button( container, label );
	if ( ! target ) {
		throw new Error(
			`No button "${ label }" in: ${ container.textContent }`
		);
	}
	await act( async () => target.click() );
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

beforeAll( () => {
	(
		globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }
	 ).IS_REACT_ACT_ENVIRONMENT = true;
} );

afterEach( () => {
	mockApiFetch.mockReset();
	document.body.innerHTML = '';
} );

describe( 'two-step sign-in model', () => {
	const off = parseProviderSettings( OFF );
	const appOn = parseProviderSettings( APP_ON );

	it( 'summarizes status in plain words', () => {
		expect( statusLine( settingsSummary( off ) ) ).toContain(
			'Two-step sign-in is off'
		);
		expect( statusLine( settingsSummary( appOn ) ) ).toContain(
			'code from your authenticator app'
		);
	} );

	it( 'builds full provider payloads with an enabled primary', () => {
		expect( providerUpdate( off, { enable: 'Two_Factor_Email' } ) ).toEqual(
			{
				enabled_providers: [ 'Two_Factor_Email' ],
				primary_provider: 'Two_Factor_Email',
			}
		);
		expect(
			providerUpdate( appOn, { primary: 'Two_Factor_Email' } )
		).toEqual( {
			enabled_providers: [
				'Two_Factor_Totp',
				'Two_Factor_Backup_Codes',
				'Two_Factor_Email',
			],
			primary_provider: 'Two_Factor_Email',
		} );
	} );

	it( 'turns backup codes off with the last real method', () => {
		expect(
			providerUpdate( appOn, { disable: 'Two_Factor_Totp' } )
		).toEqual( { enabled_providers: [], primary_provider: '' } );
		expect(
			providerUpdate( appOn, { disable: 'Two_Factor_Backup_Codes' } )
		).toEqual( {
			enabled_providers: [ 'Two_Factor_Totp' ],
			primary_provider: 'Two_Factor_Totp',
		} );
	} );

	it( 'retries a refused change exactly once after confirmation', async () => {
		const action = jest
			.fn()
			.mockRejectedValueOnce( {
				code: 'revalidation_required',
				data: { status: 403 },
			} )
			.mockResolvedValueOnce( 'done' );
		await expect(
			runWithRevalidation( action, () => Promise.resolve( true ) )
		).resolves.toBe( 'done' );
		expect( action ).toHaveBeenCalledTimes( 2 );

		const cancelled = jest.fn().mockRejectedValue( {
			code: 'x',
			data: { revalidation: { required: true } },
		} );
		await expect(
			runWithRevalidation( cancelled, () => Promise.resolve( false ) )
		).resolves.toBeUndefined();
		expect( cancelled ).toHaveBeenCalledTimes( 1 );

		const other = jest.fn().mockRejectedValue( { code: 'boom' } );
		await expect(
			runWithRevalidation( other, () => Promise.resolve( true ) )
		).rejects.toEqual( { code: 'boom' } );
	} );

	it( 'classifies errors and sanitizes setup data', () => {
		expect( isRouteMissing( { code: 'rest_no_route' } ) ).toBe( true );
		expect( isRouteMissing( { data: { status: 404 } } ) ).toBe( true );
		expect( isRevalidationRequired( { code: 'rest_forbidden' } ) ).toBe(
			false
		);
		expect( securityError( { code: 'invalid_key_code' } ) ).toBe(
			"That code didn't work. Check it and try again."
		);
		expect(
			securityError( { code: 'totp_enrollment_expired' } )
		).toContain( 'Setup timed out' );
		expect( safeOtpauthUri( 'javascript:alert(1)' ) ).toBe( '' );
		expect(
			safeOtpauthUri( 'otpauth://totp/Extra%20Chill:c?secret=A' )
		).toBe( 'otpauth://totp/Extra%20Chill:c?secret=A' );
		expect( formatSecret( 'ABCDEFGHIJ' ) ).toBe( 'ABCD EFGH IJ' );
		expect( backupCodesDownloadHref( [ '12345678' ] ) ).toMatch(
			/^data:text\/plain;charset=utf-8,.*12345678$/
		);
	} );
} );

describe( 'two-step sign-in section', () => {
	it( 'renders nothing when the routes are missing', async () => {
		const { request } = fakeRequest( {
			[ `GET ${ PROVIDERS }` ]: [
				{ reject: { code: 'rest_no_route', data: { status: 404 } } },
			],
		} );
		const { container } = await render(
			<TwoStepSignInSection userId={ 7 } request={ request } />
		);
		expect( container.textContent ).toBe( '' );
	} );

	it( 'sets up an authenticator app with a server key, QR code and code check', async () => {
		const { request, calls } = fakeRequest( {
			[ `GET ${ PROVIDERS }` ]: [ OFF, APP_ON ],
			'POST two-factor/1.0/totp/enrollment': [
				{
					secret: 'JBSWY3DPEHPK3PXP',
					otpauth_uri:
						'otpauth://totp/Extra%20Chill:chubes?secret=JBSWY3DPEHPK3PXP',
					expires_at: 1,
				},
			],
			'POST two-factor/1.0/totp': [ { success: true } ],
		} );
		const qrImage = jest.fn( () => 'data:image/gif;base64,QR' );
		const { container } = await render(
			<TwoStepSignInSection
				userId={ 7 }
				request={ request }
				qrImage={ qrImage }
			/>
		);

		expect( container.textContent ).toContain( 'Two-step sign-in is off' );
		await click( container, 'Set up authenticator app' );

		expect( calls[ 1 ] ).toEqual( {
			path: 'two-factor/1.0/totp/enrollment',
			method: 'POST',
			data: { user_id: 7 },
		} );
		expect( container.textContent ).toContain( 'JBSW Y3DP EHPK 3PXP' );
		expect( qrImage ).toHaveBeenCalledWith(
			'otpauth://totp/Extra%20Chill:chubes?secret=JBSWY3DPEHPK3PXP'
		);
		expect( container.querySelector( 'img' )?.getAttribute( 'src' ) ).toBe(
			'data:image/gif;base64,QR'
		);
		const link = Array.from( container.querySelectorAll( 'a' ) ).find(
			( a ) => a.textContent === 'Open in authenticator app'
		);
		expect( link?.getAttribute( 'href' ) ).toMatch( /^otpauth:\/\/totp\// );

		expect( button( container, 'Turn on' )?.disabled ).toBe( true );
		typeInto(
			container.querySelector(
				'#ec-two-step-setup-code'
			) as HTMLInputElement,
			'123 456'
		);
		expect( button( container, 'Turn on' )?.disabled ).toBe( false );
		await click( container, 'Turn on' );

		expect( calls[ 2 ] ).toEqual( {
			path: 'two-factor/1.0/totp',
			method: 'POST',
			data: { user_id: 7, code: '123456', enable_provider: true },
		} );
		expect( container.textContent ).toContain(
			'Your authenticator app is set up'
		);
		expect( container.textContent ).toContain(
			'On, used first when you sign in'
		);
		expect( container.textContent ).toContain( '9 unused codes left' );
	} );

	it( 'asks the member to confirm it is them, then retries the change', async () => {
		const { request, calls } = fakeRequest( {
			[ `GET ${ PROVIDERS }` ]: [ OFF ],
			[ `POST ${ PROVIDERS }` ]: [
				{
					reject: {
						code: 'revalidation_required',
						data: {
							status: 403,
							revalidation: {
								required: true,
								rest_route: '/two-factor/1.0/revalidate',
							},
						},
					},
				},
				EMAIL_ON,
			],
			// Challenge first, then the email send, then the code check.
			'POST two-factor/1.0/revalidate': [
				{
					result: 'challenge',
					provider: 'Two_Factor_Email',
					providers: [ { key: 'Two_Factor_Email', label: 'Email' } ],
				},
				{ result: 'code_sent', provider: 'Two_Factor_Email' },
				{ result: 'revalidated' },
			],
		} );
		const { container } = await render(
			<TwoStepSignInSection userId={ 7 } request={ request } />
		);

		await click( container, 'Turn on email codes' );
		expect( container.querySelector( '[role="dialog"]' ) ).not.toBeNull();
		expect( container.textContent ).toContain( "Confirm it's you" );

		await click( container, 'Email me a code' );
		expect( container.textContent ).toContain(
			'We sent a code to your email.'
		);

		typeInto(
			container.querySelector(
				'#ec-two-step-confirm-code'
			) as HTMLInputElement,
			'12345678'
		);
		await click( container, 'Confirm' );

		const revalidateCalls = calls.filter(
			( call ) => call.path === 'two-factor/1.0/revalidate'
		);
		expect( revalidateCalls.map( ( call ) => call.data ) ).toEqual( [
			{},
			{ provider: 'Two_Factor_Email', resend: true },
			{ provider: 'Two_Factor_Email', code: '12345678' },
		] );
		const writes = calls.filter(
			( call ) => call.path === PROVIDERS && call.method === 'POST'
		);
		expect( writes ).toHaveLength( 2 );
		expect( writes[ 1 ].data ).toEqual( {
			enabled_providers: [ 'Two_Factor_Email' ],
			primary_provider: 'Two_Factor_Email',
		} );
		expect( container.querySelector( '[role="dialog"]' ) ).toBeNull();
		expect( container.textContent ).toContain( 'Email codes are on.' );
	} );

	it( 'warns before replacing backup codes and offers copy and download', async () => {
		const { request, calls } = fakeRequest( {
			[ `GET ${ PROVIDERS }` ]: [ APP_ON ],
			'POST two-factor/1.0/generate-backup-codes': [
				{ codes: [ '11112222', '33334444' ] },
			],
		} );
		const { container } = await render(
			<TwoStepSignInSection userId={ 7 } request={ request } />
		);

		await click( container, 'Get new backup codes' );
		expect( container.textContent ).toContain(
			'Your old backup codes will stop working.'
		);
		expect(
			calls.some( ( call ) =>
				call.path.includes( 'generate-backup-codes' )
			)
		).toBe( false );

		await click( container, 'Get new codes' );
		expect(
			calls.find( ( call ) =>
				call.path.includes( 'generate-backup-codes' )
			)?.data
		).toEqual( { user_id: 7, enable_provider: true } );
		expect( container.textContent ).toContain( '11112222' );
		expect( container.textContent ).toContain( '33334444' );
		expect( button( container, 'Copy codes' ) ).toBeDefined();
		const download = Array.from( container.querySelectorAll( 'a' ) ).find(
			( a ) => a.textContent === 'Download codes'
		);
		expect( download?.getAttribute( 'download' ) ).toBe(
			'extra-chill-backup-codes.txt'
		);
	} );

	it( 'confirms before turning off the last method', async () => {
		const { request, calls } = fakeRequest( {
			[ `GET ${ PROVIDERS }` ]: [ APP_ON ],
			[ `POST ${ PROVIDERS }` ]: [ OFF ],
		} );
		const { container } = await render(
			<TwoStepSignInSection userId={ 7 } request={ request } />
		);

		const turnOff = Array.from(
			container.querySelectorAll< HTMLButtonElement >( 'li button' )
		).find( ( b ) => b.textContent === 'Turn off' );
		await act( async () => turnOff?.click() );
		expect( container.textContent ).toContain(
			'backup codes stop working too'
		);
		await click( container, 'Turn off two-step sign-in' );

		expect(
			calls.find( ( call ) => call.method === 'POST' )?.data
		).toEqual( { enabled_providers: [], primary_provider: '' } );
		expect( container.textContent ).toContain( 'Two-step sign-in is off.' );
	} );

	it( 'is absent from the Security tab unless the server enabled it', async () => {
		mockApiFetch.mockResolvedValue( { sessions: [] } );
		const { container } = await render(
			<SecurityTab
				settings={ settings }
				onSettingsChange={ () => undefined }
				userId={ 7 }
			/>
		);
		expect( container.textContent ).not.toContain( 'Two-step sign-in' );
		expect(
			mockApiFetch.mock.calls.some( ( [ options ] ) =>
				String( options.path ).startsWith( 'two-factor/' )
			)
		).toBe( false );
	} );

	it( 'loads through cookie-authenticated REST on the Security tab when enabled', async () => {
		mockApiFetch.mockImplementation( ( options: { path: string } ) =>
			Promise.resolve(
				options.path === PROVIDERS ? OFF : { sessions: [] }
			)
		);
		const { container } = await render(
			<SecurityTab
				settings={ settings }
				onSettingsChange={ () => undefined }
				userId={ 7 }
				twoFactor={ true }
			/>
		);
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: PROVIDERS,
			method: 'GET',
		} );
		expect( container.textContent ).toContain( 'Two-step sign-in is off' );
		expect( container.innerHTML ).not.toMatch( /wp-admin|profile\.php/ );
	} );
} );
