/**
 * Google Sign-In render ordering contracts.
 *
 * GSI refuses to render a button before google.accounts.id.initialize() has
 * been called ("Failed to render button before calling initialize()"). The
 * module must treat render requests as no-ops until init() has run, and must
 * never mark a container rendered when nothing was drawn — otherwise the
 * container is permanently blocked from re-render (issue #463).
 */
/* global google */

function restoreProperty( target, property, original ) {
	if ( original.exists ) {
		target[ property ] = original.value;
	} else {
		delete target[ property ];
	}
}

describe( 'Google Sign-In render-before-init ordering', () => {
	let originalGlobals;

	beforeEach( () => {
		originalGlobals = {
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
		};
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
					initialize: jest.fn(),
					renderButton: jest.fn(),
				},
			},
		};
		jest.resetModules();
	} );

	afterEach( () => {
		restoreProperty( window, 'ECGoogleSignIn', originalGlobals.googleSignIn );
		restoreProperty( window, 'ECAuthUtils', originalGlobals.authUtils );
		restoreProperty( global, 'google', originalGlobals.google );
	} );

	function mountContainer() {
		const container = document.createElement( 'div' );
		container.className = 'google-signin-button';
		container.dataset.googleText = 'continue_with';
		document.body.appendChild( container );
		return container;
	}

	test( 'renderAllButtons before init is a no-op and does not mark the container rendered', () => {
		const container = mountContainer();
		require( '../../assets/js/google-signin.js' );

		window.ECGoogleSignIn.renderAllButtons();

		expect( google.accounts.id.renderButton ).not.toHaveBeenCalled();
		expect( container.dataset.ecGoogleRendered ).toBeUndefined();
	} );

	test( 'button renders after init even when renderAllButtons was called too early', () => {
		const container = mountContainer();
		require( '../../assets/js/google-signin.js' );

		// Premature call — the exact ordering observed on community.extrachill.com.
		window.ECGoogleSignIn.renderAllButtons();
		expect( google.accounts.id.renderButton ).not.toHaveBeenCalled();

		window.ECGoogleSignIn.init( {
			clientId: 'client-id',
			restUrl: 'https://community.extrachill.com/wp-json/extrachill/v1/',
		} );

		// init() calls initialize() before any renderButton().
		expect( google.accounts.id.initialize ).toHaveBeenCalledTimes( 1 );
		expect( google.accounts.id.renderButton ).toHaveBeenCalledTimes( 1 );
		expect( google.accounts.id.renderButton.mock.invocationCallOrder[ 0 ] ).toBeGreaterThan(
			google.accounts.id.initialize.mock.invocationCallOrder[ 0 ]
		);
		expect( google.accounts.id.renderButton ).toHaveBeenCalledWith(
			container,
			expect.objectContaining( { text: 'continue_with' } )
		);
		expect( container.dataset.ecGoogleRendered ).toBe( '1' );
	} );

	test( 'renderAllButtons after init renders containers added later, and only once', () => {
		require( '../../assets/js/google-signin.js' );

		window.ECGoogleSignIn.init( {
			clientId: 'client-id',
			restUrl: 'https://community.extrachill.com/wp-json/extrachill/v1/',
		} );

		// Tab switch mounts a new container after init.
		const container = mountContainer();
		window.ECGoogleSignIn.renderAllButtons();

		expect( google.accounts.id.renderButton ).toHaveBeenCalledTimes( 1 );
		expect( container.dataset.ecGoogleRendered ).toBe( '1' );

		// Subsequent calls skip already-rendered containers.
		window.ECGoogleSignIn.renderAllButtons();
		expect( google.accounts.id.renderButton ).toHaveBeenCalledTimes( 1 );
	} );
} );
