/* global afterEach, describe, expect, it, jest */
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
