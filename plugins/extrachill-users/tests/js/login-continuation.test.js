/**
 * Behavioral browser contracts for resolved authentication continuations.
 */

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { GoogleButtons, LoginPanel } from '../../blocks/login-register/view';

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	return {
		BlockShell: ( { children } ) => React.createElement( 'div', null, children ),
		BlockShellHeader: () => null,
		BlockShellInner: ( { children } ) => React.createElement( 'div', null, children ),
		Panel: ( { children } ) => React.createElement( 'div', null, children ),
		ResponsiveTabs: () => null,
	};
} );

const continuation = 'https://community.extrachill.com/?compose=discussion&entity_taxonomy=artist&entity_slug=kid-lake';

function authConfig() {
	return {
		loginRedirectUrl: continuation,
		successRedirectUrl: continuation,
		resetPasswordUrl: 'https://community.extrachill.com/reset-password/',
		turnstileHtml: '',
		googleOAuthEnabled: true,
		googleSignInRedirectUrl: null,
	};
}

function renderLoginPanel() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	act( () => {
		root.render( <LoginPanel config={ authConfig() } notice={ null } setNotice={ jest.fn() } /> );
	} );

	return { container, root };
}

function flushPromises() {
	return act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
}

describe( 'login continuation requests', () => {
	let credentialCallback;
	let originalGlobals;

	function restoreProperty( target, property, original ) {
		if ( original.exists ) {
			target[ property ] = original.value;
		} else {
			delete target[ property ];
		}
	}

	beforeEach( () => {
		originalGlobals = {
			actEnvironment: {
				exists: Object.prototype.hasOwnProperty.call( global, 'IS_REACT_ACT_ENVIRONMENT' ),
				value: global.IS_REACT_ACT_ENVIRONMENT,
			},
			authUtils: {
				exists: Object.prototype.hasOwnProperty.call( window, 'ECAuthUtils' ),
				value: window.ECAuthUtils,
			},
			googleSignIn: {
				exists: Object.prototype.hasOwnProperty.call( window, 'ECGoogleSignIn' ),
				value: window.ECGoogleSignIn,
			},
			google: {
				exists: Object.prototype.hasOwnProperty.call( global, 'google' ),
				value: global.google,
			},
			fetch: {
				exists: Object.prototype.hasOwnProperty.call( global, 'fetch' ),
				value: global.fetch,
			},
		};
		global.IS_REACT_ACT_ENVIRONMENT = true;
		document.body.innerHTML = '';
		window.ECAuthUtils = {
			getDeviceId: jest.fn( () => '550e8400-e29b-41d4-a716-446655440000' ),
			getRestRoot: jest.fn( () => 'https://community.extrachill.com/wp-json/' ),
			setSubmitting: jest.fn( () => jest.fn() ),
			renderNotice: jest.fn(),
		};
		global.google = {
			accounts: {
				id: {
					initialize: jest.fn( ( config ) => {
						credentialCallback = config.callback;
					} ),
					renderButton: jest.fn(),
				},
			},
		};
		global.fetch = jest.fn();
		jest.resetModules();
	} );

	afterEach( () => {
		restoreProperty( global, 'IS_REACT_ACT_ENVIRONMENT', originalGlobals.actEnvironment );
		restoreProperty( window, 'ECGoogleSignIn', originalGlobals.googleSignIn );
		restoreProperty( window, 'ECAuthUtils', originalGlobals.authUtils );
		restoreProperty( global, 'google', originalGlobals.google );
		restoreProperty( global, 'fetch', originalGlobals.fetch );
	} );

	test( 'default Login tab exposes one resolved continuation to password and Google flows', () => {
		const { container, root } = renderLoginPanel();

		expect( container.querySelector( 'input[name="redirect_to"]' ).value ).toBe( continuation );
		expect( container.querySelector( 'input[name="success_redirect_url"]' ).value ).toBe( continuation );

		act( () => root.unmount() );
	} );

	test( 'canonical Google request reads the resolved continuation from the default Login DOM', async () => {
		const { root } = renderLoginPanel();
		fetch.mockResolvedValue( {
			ok: false,
			json: async () => ( { message: 'Expected test stop.' } ),
		} );
		require( '../../assets/js/google-signin.js' );
		window.ECGoogleSignIn.init( {
			clientId: 'client-id',
			restUrl: 'https://community.extrachill.com/wp-json/extrachill/v1/',
		} );

		credentialCallback( { credential: 'google-id-token' } );
		await flushPromises();

		const request = JSON.parse( fetch.mock.calls[0][1].body );
		expect( request.success_redirect_url ).toBe( continuation );

		act( () => root.unmount() );
	} );

	test( 'password request submits the continuation used by the 2FA challenge', async () => {
		const { container, root } = renderLoginPanel();
		container.querySelector( 'input[name="log"]' ).value = 'chubes';
		container.querySelector( 'input[name="pwd"]' ).value = 'password';
		fetch.mockResolvedValue( {
			ok: false,
			json: async () => ( { message: 'Expected test stop.' } ),
		} );

		await act( async () => {
			container.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
		} );
		await flushPromises();

		const request = JSON.parse( fetch.mock.calls[0][1].body );
		expect( request.redirect_to ).toBe( continuation );

		act( () => root.unmount() );
	} );
} );

describe( 'Google authentication intent', () => {
	test( 'uses sign-up language only for the registration control', () => {
		const previousActEnvironment = global.IS_REACT_ACT_ENVIRONMENT;
		global.IS_REACT_ACT_ENVIRONMENT = true;
		const container = document.createElement( 'div' );
		const root = createRoot( container );

		act( () => {
			root.render( <GoogleButtons redirectUrl="https://extrachill.com/login/" registration={ true } /> );
		} );
		expect( container.textContent ).toContain( 'Sign up with Google' );

		act( () => {
			root.render( <GoogleButtons redirectUrl={ null } registration={ true } /> );
		} );
		expect( container.querySelector( '.google-signin-button' ).dataset.googleText ).toBe( 'signup_with' );

		act( () => root.unmount() );
		global.IS_REACT_ACT_ENVIRONMENT = previousActEnvironment;
	} );
} );
