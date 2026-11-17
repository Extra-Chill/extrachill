/**
 * Inline two-factor step of the login block.
 */

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import {
	AUTH_CHALLENGE_EVENT,
	LoginRegisterApp,
} from '../../blocks/login-register/view';

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	return {
		BlockShell: ( { children } ) =>
			React.createElement( 'div', null, children ),
		BlockShellInner: ( { children } ) =>
			React.createElement( 'div', null, children ),
		Panel: ( { children } ) => React.createElement( 'div', null, children ),
		ResponsiveTabs: ( { active, renderPanel } ) =>
			React.createElement( 'div', null, renderPanel( active ) ),
	};
} );

const DEVICE_ID = '550e8400-e29b-41d4-a716-446655440000';
const REST_ROOT = 'https://community.extrachill.com/wp-json/';
const CONTINUE_URL = `${ REST_ROOT }extrachill/v1/auth/login/continue`;
const DESTINATION = 'https://community.extrachill.com/r/new-topic/';

function challengeResponse( overrides = {} ) {
	return {
		challenge_required: true,
		challenge_policy: 'two-factor',
		challenge: {
			type: 'two_factor',
			provider: 'Two_Factor_Totp',
			providers: [
				{
					key: 'Two_Factor_Totp',
					label: 'Authenticator app',
					resend: false,
				},
				{ key: 'Two_Factor_Email', label: 'Email', resend: true },
				{
					key: 'Two_Factor_Backup_Codes',
					label: 'Backup codes',
					resend: false,
				},
			],
		},
		continuation_token: 'continuation-token',
		continuation_expires_at: '2030-01-01T00:00:00+00:00',
		...overrides,
	};
}

function jsonResponse( status, body ) {
	return Promise.resolve( {
		ok: status >= 200 && status < 300,
		status,
		json: async () => body,
	} );
}

function flushPromises() {
	return act( async () => {
		for ( let i = 0; i < 5; i++ ) {
			await Promise.resolve();
		}
	} );
}

function setInput( input, value ) {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	setter.call( input, value );
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

function buttonByText( container, text ) {
	return Array.from( container.querySelectorAll( 'button' ) ).find(
		( button ) => button.textContent === text
	);
}

describe( 'login block two-factor step', () => {
	let container;
	let root;
	let assign;

	beforeEach( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
		document.body.innerHTML = '';
		window.ECAuthUtils = {
			getDeviceId: jest.fn( () => DEVICE_ID ),
			getRestRoot: jest.fn( () => REST_ROOT ),
			setSubmitting: jest.fn( () => jest.fn() ),
		};
		global.fetch = jest.fn();
		assign = jest.fn();

		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		act( () => {
			root.render(
				<LoginRegisterApp
					navigate={ assign }
					config={ {
						loginRedirectUrl: DESTINATION,
						successRedirectUrl: DESTINATION,
						resetPasswordUrl:
							'https://community.extrachill.com/reset-password/',
						turnstileHtml: '',
						googleOAuthEnabled: false,
					} }
				/>
			);
		} );
	} );

	afterEach( () => {
		act( () => root.unmount() );
	} );

	async function submitPassword( loginBody ) {
		fetch.mockImplementationOnce( () => jsonResponse( 200, loginBody ) );
		container.querySelector( 'input[name="log"]' ).value = 'chubes';
		container.querySelector( 'input[name="pwd"]' ).value = 'password';
		await act( async () => {
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
		} );
		await flushPromises();
	}

	async function submitCode( code ) {
		act( () =>
			setInput( container.querySelector( '#two_factor_code' ), code )
		);
		await act( async () => {
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
		} );
		await flushPromises();
	}

	function lastContinueBody() {
		const call = fetch.mock.calls[ fetch.mock.calls.length - 1 ];
		expect( call[ 0 ] ).toBe( CONTINUE_URL );
		return JSON.parse( call[ 1 ].body );
	}

	test( 'asks for the code inline instead of leaving the page', async () => {
		await submitPassword( challengeResponse() );

		expect( assign ).not.toHaveBeenCalled();
		expect( container.querySelector( '#two_factor_code' ) ).not.toBeNull();
		expect( container.querySelector( 'input[name="pwd"]' ) ).toBeNull();
		expect( container.textContent ).toContain( 'authenticator app' );
	} );

	test( 'submits the code and goes to the bound destination', async () => {
		await submitPassword( challengeResponse() );
		fetch.mockImplementationOnce( () =>
			jsonResponse( 200, {
				access_token: 'a',
				redirect_url: DESTINATION,
			} )
		);

		await submitCode( '123456' );

		expect( lastContinueBody() ).toEqual( {
			continuation_token: 'continuation-token',
			device_id: DEVICE_ID,
			challenge_response: { provider: 'Two_Factor_Totp', code: '123456' },
		} );
		expect( assign ).toHaveBeenCalledWith( DESTINATION );
	} );

	test( 'switching to email sends a code and offers resend', async () => {
		await submitPassword( challengeResponse() );
		fetch.mockImplementation( () =>
			jsonResponse(
				200,
				challengeResponse( {
					challenge: {
						...challengeResponse().challenge,
						status: 'code_sent',
					},
				} )
			)
		);

		await act( async () => {
			buttonByText( container, 'Use Email' ).click();
		} );
		await flushPromises();

		expect( lastContinueBody().challenge_response ).toEqual( {
			provider: 'Two_Factor_Email',
			resend: true,
		} );
		expect( container.textContent ).toContain(
			'We sent a new code to your email.'
		);
		expect(
			container.querySelector( '.notice.notice-success' )
		).not.toBeNull();

		await act( async () => {
			buttonByText( container, 'Resend code' ).click();
		} );
		await flushPromises();
		expect( lastContinueBody().challenge_response ).toEqual( {
			provider: 'Two_Factor_Email',
			resend: true,
		} );
	} );

	test( 'a wrong code shows an error and keeps the step open', async () => {
		await submitPassword( challengeResponse() );
		fetch.mockImplementationOnce( () =>
			jsonResponse( 401, {
				code: 'two_factor_invalid',
				message: 'Invalid code.',
				data: { status: 401 },
			} )
		);

		await submitCode( '000000' );

		expect(
			container.querySelector( '.notice.notice-error' ).textContent
		).toContain( 'That code did not work' );
		expect( container.querySelector( '#two_factor_code' ) ).not.toBeNull();
		expect( assign ).not.toHaveBeenCalled();
	} );

	test( 'rate limiting locks the form for retry_after seconds', async () => {
		jest.useFakeTimers();
		await submitPassword( challengeResponse() );
		fetch.mockImplementationOnce( () =>
			jsonResponse( 429, {
				code: 'two_factor_too_fast',
				message: 'Slow down.',
				data: { status: 429, retry_after: 2 },
			} )
		);

		await submitCode( '000000' );

		const verify = container.querySelector( 'button[type="submit"]' );
		expect( verify.disabled ).toBe( true );
		expect( verify.textContent ).toBe( 'Try again in 2s' );

		for ( let second = 0; second < 2; second++ ) {
			await act( async () => {
				jest.advanceTimersByTime( 1000 );
			} );
		}
		expect(
			container.querySelector( 'button[type="submit"]' ).disabled
		).toBe( false );
		jest.useRealTimers();
	} );

	test( 'a forced password reset returns to the login form with a reset link', async () => {
		await submitPassword( challengeResponse() );
		fetch.mockImplementationOnce( () =>
			jsonResponse( 403, {
				code: 'two_factor_password_reset_required',
				message: 'Reset.',
				data: { status: 403 },
			} )
		);

		await submitCode( '000000' );

		expect( container.querySelector( '#two_factor_code' ) ).toBeNull();
		expect( container.querySelector( 'input[name="pwd"]' ) ).not.toBeNull();
		expect(
			container.querySelector(
				'a[href="https://community.extrachill.com/reset-password/"]'
			)
		).not.toBeNull();
	} );

	test( 'an expired continuation returns to the login form', async () => {
		await submitPassword( challengeResponse() );
		fetch.mockImplementationOnce( () =>
			jsonResponse( 401, {
				code: 'continuation_expired',
				message: 'Expired.',
				data: { status: 401 },
			} )
		);

		await submitCode( '123456' );

		expect( container.querySelector( 'input[name="pwd"]' ) ).not.toBeNull();
		expect( container.textContent ).toContain( 'Your sign-in timed out' );
	} );

	test( 'takes over a Google sign-in challenge', async () => {
		const event = new window.CustomEvent( AUTH_CHALLENGE_EVENT, {
			cancelable: true,
			detail: {
				challenge: challengeResponse(),
				deviceId: DEVICE_ID,
				continueUrl: CONTINUE_URL,
			},
		} );

		let notHandled;
		await act( async () => {
			notHandled = window.dispatchEvent( event );
		} );

		expect( notHandled ).toBe( false );
		expect( container.querySelector( '#two_factor_code' ) ).not.toBeNull();
	} );
} );
