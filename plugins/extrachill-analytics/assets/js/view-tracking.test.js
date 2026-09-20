/* global afterEach, beforeEach, describe, expect, it, jest */
/* eslint-disable no-redeclare -- Classic config already provides Jest globals. */

const runTracker = ( config, referrer = '' ) => {
	let body;
	global.ecViewTracking = config;
	global.Blob = class Blob {
		constructor( parts ) {
			this.body = parts.join( '' );
		}
	};
	Object.defineProperty( global.navigator, 'sendBeacon', {
		configurable: true,
		value: jest.fn( ( endpoint, blob ) => {
			body = blob;
			return true;
		} ),
	} );
	Object.defineProperty( document, 'referrer', {
		configurable: true,
		value: referrer,
	} );

	jest.isolateModules( () => require( './view-tracking' ) );

	return JSON.parse( body.body );
};

const routeConfig = {
	postId: 0,
	sourcePath: '/locations/charleston/',
	routeFamily: 'archive',
	proof: 'a'.repeat( 64 ),
	endpoint:
		'/wp-json/wp-abilities/v1/abilities/extrachill/track-page-view/run',
};

describe( 'anonymous pageview payload', () => {
	afterEach( () => {
		delete global.ecViewTracking;
		jest.restoreAllMocks();
	} );

	it.each( [
		[ 'location archive', routeConfig ],
		[ 'query scope and search', routeConfig ],
		[ 'back navigation cached config', { ...routeConfig, postId: '0' } ],
	] )( 'omits route-level post_id for %s', ( label, config ) => {
		const payload = runTracker( config );

		expect( label ).toBeTruthy();
		expect( payload.input ).toEqual( {
			source_path: '/locations/charleston/',
			route_family: 'archive',
			proof: 'a'.repeat( 64 ),
		} );
	} );

	it( 'normalizes a cached singular post ID to an integer', () => {
		const payload = runTracker( {
			...routeConfig,
			postId: '236584',
			sourcePath: '/events/charleston-indie-night/',
			routeFamily: 'singular',
		} );

		expect( payload.input.post_id ).toBe( 236584 );
	} );
} );

describe( 'client-side visitor cookie mint', () => {
	let cookieJar;
	let cookieSetter;

	const runMintTracker = ( config, navigatorOverrides = {} ) => {
		global.ecViewTracking = config;
		global.Blob = class Blob {
			constructor( parts ) {
				this.body = parts.join( '' );
			}
		};
		Object.defineProperty( global.navigator, 'sendBeacon', {
			configurable: true,
			value: jest.fn(),
		} );
		Object.defineProperty( document, 'referrer', {
			configurable: true,
			value: '',
		} );
		Object.keys( navigatorOverrides ).forEach( ( key ) => {
			Object.defineProperty( global.navigator, key, {
				configurable: true,
				value: navigatorOverrides[ key ],
			} );
			Object.defineProperty( window, key, {
				configurable: true,
				value: navigatorOverrides[ key ],
			} );
		} );

		jest.isolateModules( () => require( './view-tracking' ) );
	};

	const mintConfig = {
		...routeConfig,
		cookieName: 'ec_vid',
		cookieDomain: '.extrachill.com',
		cookieMaxAge: 31557600,
	};

	const UUID_V4_PATTERN =
		/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

	beforeEach( () => {
		cookieJar = '';
		cookieSetter = jest.fn( ( value ) => {
			cookieJar = cookieJar ? cookieJar + '; ' + value : value;
		} );
		Object.defineProperty( document, 'cookie', {
			configurable: true,
			get: () => cookieJar,
			set: cookieSetter,
		} );
	} );

	afterEach( () => {
		delete global.ecViewTracking;
		delete document.cookie;
		// defineProperty() overrides are not jest mocks; reset them manually
		// so an opt-out fixture never leaks into a later test.
		[ 'globalPrivacyControl', 'doNotTrack' ].forEach( ( key ) => {
			delete global.navigator[ key ];
			delete global[ key ];
		} );
		jest.restoreAllMocks();
	} );
	it( 'mints a UUIDv4 cookie when none exists', () => {
		runMintTracker( mintConfig );

		expect( cookieSetter ).toHaveBeenCalledTimes( 1 );
		const written = cookieSetter.mock.calls[ 0 ][ 0 ];
		const match = written.match( /^ec_vid=([^;]+);/ );
		expect( match ).not.toBeNull();
		expect( match[ 1 ] ).toMatch( UUID_V4_PATTERN );
	} );

	it( 'preserves the network-root cookie attributes', () => {
		runMintTracker( mintConfig );

		const written = cookieSetter.mock.calls[ 0 ][ 0 ];
		expect( written ).toContain( 'domain=.extrachill.com' );
		expect( written ).toContain( 'path=/' );
		expect( written ).toContain( 'max-age=31557600' );
		expect( written ).toContain( 'Secure' );
		expect( written ).toContain( 'SameSite=Lax' );
	} );

	it( 'never re-mints when a visitor cookie already exists', () => {
		cookieJar = 'ec_vid=123e4567-e89b-42d3-a456-426614174000';

		runMintTracker( mintConfig );

		expect( cookieSetter ).not.toHaveBeenCalled();
		expect( cookieJar ).toBe(
			'ec_vid=123e4567-e89b-42d3-a456-426614174000'
		);
	} );

	it.each( [
		[ 'GPC', 'globalPrivacyControl', true ],
		[ 'DNT', 'doNotTrack', '1' ],
	] )(
		'does not mint for a %s opt-out browser',
		( label, property, value ) => {
			expect( label ).toBeTruthy();
			runMintTracker( mintConfig, { [ property ]: value } );

			expect( cookieSetter ).not.toHaveBeenCalled();
			expect( cookieJar ).not.toContain( 'ec_vid=' );
		}
	);

	it( 'does not mint when the server supplies no first-party cookie domain', () => {
		runMintTracker( { ...mintConfig, cookieDomain: '' } );

		expect( cookieSetter ).not.toHaveBeenCalled();
	} );

	it( 'does not mint when the platform has no CSPRNG', () => {
		const originalCrypto = window.crypto;
		Object.defineProperty( window, 'crypto', {
			configurable: true,
			value: undefined,
		} );

		try {
			runMintTracker( mintConfig );
		} finally {
			Object.defineProperty( window, 'crypto', {
				configurable: true,
				value: originalCrypto,
			} );
		}

		expect( cookieSetter ).not.toHaveBeenCalled();
	} );

	it( 'mints before the pageview beacon fires so the first pageview carries identity', () => {
		let mintedBeforeBeacon = false;
		global.Blob = class Blob {
			constructor( parts ) {
				this.body = parts.join( '' );
			}
		};
		Object.defineProperty( global.navigator, 'sendBeacon', {
			configurable: true,
			value: jest.fn( () => {
				mintedBeforeBeacon = /ec_vid=[0-9a-f-]{36}/.test( cookieJar );
				return true;
			} ),
		} );
		Object.defineProperty( document, 'referrer', {
			configurable: true,
			value: '',
		} );
		global.ecViewTracking = mintConfig;

		jest.isolateModules( () => require( './view-tracking' ) );

		expect( mintedBeforeBeacon ).toBe( true );
	} );
} );
