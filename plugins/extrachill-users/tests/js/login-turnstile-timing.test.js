/**
 * Turnstile token timing on the login/register forms.
 *
 * The widget is invisible (appearance "interaction-only") and mints its token
 * a few seconds after load. Autofilled credentials let users submit before the
 * token exists; the form must wait for it instead of failing with a
 * "complete the challenge" error for a challenge nobody can see.
 */

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import {
	LoginPanel,
	RegisterPanel,
	TURNSTILE_TIMEOUT_MESSAGE,
	TURNSTILE_TOKEN_TIMEOUT_MS,
} from '../../blocks/login-register/view';

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) =>
		React.createElement( 'div', null, children );
	return {
		BlockShell: Passthrough,
		BlockShellInner: Passthrough,
		Panel: Passthrough,
		ResponsiveTabs: () => null,
	};
} );

const TURNSTILE_HTML =
	'<div class="cf-turnstile" data-sitekey="site-key" data-appearance="interaction-only"></div>';

function config() {
	return {
		loginRedirectUrl: 'https://extrachill.com/',
		successRedirectUrl: 'https://extrachill.com/',
		resetPasswordUrl: 'https://extrachill.com/reset-password/',
		turnstileHtml: TURNSTILE_HTML,
		googleOAuthEnabled: false,
	};
}

/**
 * Simulate Cloudflare injecting its hidden response field with a token.
 *
 * @param {Element} container Rendered panel container.
 * @param {string}  token     Token value.
 */
function mintToken( container, token ) {
	const widget = container.querySelector( '.cf-turnstile' );
	let field = widget.querySelector( '[name="cf-turnstile-response"]' );
	if ( ! field ) {
		field = document.createElement( 'input' );
		field.type = 'hidden';
		field.name = 'cf-turnstile-response';
		widget.appendChild( field );
	}
	field.value = token;
}

async function submit( container ) {
	await act( async () => {
		container
			.querySelector( 'form' )
			.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
	} );
}

async function advance( ms ) {
	await act( async () => {
		jest.advanceTimersByTime( ms );
	} );
	// Let the resolved token promise continue into fetch().
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
}

describe.each( [
	[
		'login',
		LoginPanel,
		( container ) => {
			container.querySelector( 'input[name="log"]' ).value =
				'cluckinchuck';
			container.querySelector( 'input[name="pwd"]' ).value = 'password';
		},
	],
	[
		'register',
		RegisterPanel,
		( container ) => {
			container.querySelector( '#extrachill_email' ).value =
				'person@example.com';
			container.querySelector( '#extrachill_password' ).value =
				'password123';
			container.querySelector( '#extrachill_password_confirm' ).value =
				'password123';
		},
	],
] )( '%s form Turnstile timing', ( _name, Panel, fillForm ) => {
	let container;
	let root;
	let setNotice;
	let restore;

	beforeEach( () => {
		jest.useFakeTimers();
		global.IS_REACT_ACT_ENVIRONMENT = true;
		restore = jest.fn();
		window.ECAuthUtils = {
			getDeviceId: jest.fn(
				() => '550e8400-e29b-41d4-a716-446655440000'
			),
			getRestRoot: jest.fn( () => 'https://extrachill.com/wp-json/' ),
			setSubmitting: jest.fn( () => restore ),
		};
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: false,
				json: async () => ( { message: 'Expected test stop.' } ),
			} )
		);
		delete window.turnstile;

		setNotice = jest.fn();
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		act( () => {
			root.render(
				<Panel
					config={ config() }
					notice={ null }
					setNotice={ setNotice }
				/>
			);
		} );
		fillForm( container );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		document.body.innerHTML = '';
		jest.useRealTimers();
	} );

	test( 'submits immediately when the token already exists', async () => {
		mintToken( container, 'ready-token' );

		await submit( container );
		await advance( 0 );

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		expect(
			JSON.parse( fetch.mock.calls[ 0 ][ 1 ].body ).turnstile_response
		).toBe( 'ready-token' );
	} );

	test( 'waits for a token that arrives after submit instead of erroring', async () => {
		await submit( container );

		// Early submit: no request yet, and no captcha error shown.
		expect( fetch ).not.toHaveBeenCalled();
		expect( setNotice ).not.toHaveBeenCalledWith(
			expect.objectContaining( { type: 'error' } )
		);

		mintToken( container, 'late-token' );
		await advance( 300 );

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		expect(
			JSON.parse( fetch.mock.calls[ 0 ][ 1 ].body ).turnstile_response
		).toBe( 'late-token' );
	} );

	test( 'gives up with an actionable message when no token ever arrives', async () => {
		await submit( container );
		await advance( TURNSTILE_TOKEN_TIMEOUT_MS + 300 );

		expect( fetch ).not.toHaveBeenCalled();
		expect( setNotice ).toHaveBeenLastCalledWith( {
			type: 'error',
			message: TURNSTILE_TIMEOUT_MESSAGE,
		} );
		expect( restore ).toHaveBeenCalled();
	} );
} );
