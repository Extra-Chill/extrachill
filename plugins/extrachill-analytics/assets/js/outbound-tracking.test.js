/* global afterEach, beforeEach, describe, expect, it, jest, MouseEvent, HTMLFormElement */

const CTA_ENDPOINT = '/wp-json/wp-abilities/v1/abilities/extrachill/track-cta-click/run';
const FORM_ENDPOINT = '/wp-json/wp-abilities/v1/abilities/extrachill/track-form-submit/run';
const OUTBOUND_ENDPOINT = '/wp-json/extrachill/v1/analytics/click';

const baseConfig = {
	endpoint: OUTBOUND_ENDPOINT,
	// Includes the jsdom test origin itself, exactly like production always
	// appends home_url()'s own host (see extrachill_analytics_enqueue_outbound_tracking()) —
	// otherwise a same-page anchor click would spuriously also read as an
	// outbound exit in these tests.
	networkHosts: [ 'extrachill.com', 'localhost' ],
	ctaEndpoint: CTA_ENDPOINT,
	formEndpoint: FORM_ENDPOINT,
	route: 'singular',
};

// outbound-tracking.js attaches its click/submit listeners directly to the
// shared jsdom `document`, which persists across tests in this file.
// `jest.isolateModules()` only resets the MODULE cache, not those already-
// attached DOM listeners, so without explicit cleanup every subsequent test
// would re-trigger every prior test's listeners on top of its own. Track and
// remove them after every test instead.
let attachedListeners = [];

function loadTracker( config ) {
	const calls = [];
	global.ecOutboundTracking = config;
	global.Blob = class Blob {
		constructor( parts ) {
			this.body = parts.join( '' );
		}
	};
	Object.defineProperty( global.navigator, 'sendBeacon', {
		configurable: true,
		value: jest.fn( ( endpoint, blob ) => {
			calls.push( { endpoint, input: JSON.parse( blob.body ) } );
			return true;
		} ),
	} );

	const originalAddEventListener =
		document.addEventListener.bind( document );
	const addSpy = jest
		.spyOn( document, 'addEventListener' )
		.mockImplementation( ( type, handler, options ) => {
			attachedListeners.push( [ type, handler, options ] );
			originalAddEventListener( type, handler, options );
		} );

	jest.isolateModules( () => require( './outbound-tracking' ) );

	addSpy.mockRestore();

	return calls;
}

afterEach( () => {
	attachedListeners.forEach( ( [ type, handler, options ] ) => {
		document.removeEventListener( type, handler, options );
	} );
	attachedListeners = [];
} );

describe( 'cta_click', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
		window.history.pushState( {}, '', '/power/' );
	} );

	afterEach( () => {
		delete global.ecOutboundTracking;
		jest.restoreAllMocks();
	} );

	it( 'fires for a design-system button click with an automatic id', () => {
		document.body.innerHTML =
			'<main><a class="button-1" href="/power/join/">Join the Scene</a></main>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		const ctaCalls = calls.filter( ( c ) => c.endpoint === CTA_ENDPOINT );
		expect( ctaCalls ).toHaveLength( 1 );
		expect( ctaCalls[ 0 ].input.input ).toEqual( {
			label: 'Join the Scene',
			dest: 'localhost/power/join/',
			placement: 'main',
			route: 'singular',
			source_url: 'http://localhost/power/',
		} );
	} );

	it( 'fires for an explicitly-tracked element with no design-system class', () => {
		document.body.innerHTML =
			'<button data-ec-track="power-join">Sign Up</button>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'button' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		const ctaCalls = calls.filter( ( c ) => c.endpoint === CTA_ENDPOINT );
		expect( ctaCalls ).toHaveLength( 1 );
		expect( ctaCalls[ 0 ].input.input.cta_override ).toBe( 'power-join' );
	} );

	it( 'suppresses tracking when data-ec-track is "off"', () => {
		document.body.innerHTML =
			'<a class="button-1" data-ec-track="off" href="/power/join/">Join</a>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( calls ).toHaveLength( 0 );
	} );

	it( 'does not fire inside wp-admin', () => {
		window.history.pushState( {}, '', '/wp-admin/post.php' );
		document.body.innerHTML = '<a class="button-1" href="/x/">Save</a>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( calls ).toHaveLength( 0 );
	} );

	it( 'does not fire inside a contenteditable region', () => {
		document.body.innerHTML =
			'<div contenteditable="true"><a class="button-1" href="/x/">Save</a></div>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( calls ).toHaveLength( 0 );
	} );

	it( 'resolves placement from the nearest data-ec-track-placement override', () => {
		document.body.innerHTML =
			'<main><div data-ec-track-placement="hero"><a class="button-1" href="/x/">Go</a></div></main>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( calls[ 0 ].input.input.placement ).toBe( 'hero' );
	} );

	it( 'strips query string and fragment from dest', () => {
		document.body.innerHTML =
			'<a class="button-1" href="https://open.spotify.com/artist/123?utm_source=x#top">Listen</a>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		const ctaCall = calls.find( ( c ) => c.endpoint === CTA_ENDPOINT );
		expect( ctaCall.input.input.dest ).toBe(
			'open.spotify.com/artist/123'
		);
	} );

	it( 'can fire both outbound_click and cta_click for one off-network button click', () => {
		document.body.innerHTML =
			'<a class="button-1" href="https://open.spotify.com/artist/123">Listen</a>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect(
			calls.some( ( c ) => c.endpoint === OUTBOUND_ENDPOINT )
		).toBe( true );
		expect( calls.some( ( c ) => c.endpoint === CTA_ENDPOINT ) ).toBe(
			true
		);
	} );
} );

describe( 'form_submit', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
		window.history.pushState( {}, '', '/' );
		HTMLFormElement.prototype.submit = jest.fn();
	} );

	afterEach( () => {
		delete global.ecOutboundTracking;
		jest.restoreAllMocks();
	} );

	it( 'names the form from data-ec-track, then id, then name', () => {
		document.body.innerHTML =
			'<footer><form id="fallback-id" data-ec-track="newsletter-signup"></form></footer>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'form' )
			.dispatchEvent( new Event( 'submit', { bubbles: true } ) );

		expect( calls ).toHaveLength( 1 );
		expect( calls[ 0 ].endpoint ).toBe( FORM_ENDPOINT );
		expect( calls[ 0 ].input.input ).toEqual( {
			form: 'newsletter-signup',
			placement: 'footer',
			route: 'singular',
			source_url: 'http://localhost/',
		} );
	} );

	it( 'falls back to id when data-ec-track is absent', () => {
		document.body.innerHTML = '<form id="newsletter-form"></form>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'form' )
			.dispatchEvent( new Event( 'submit', { bubbles: true } ) );

		expect( calls[ 0 ].input.input.form ).toBe( 'newsletter-form' );
	} );

	it( 'falls back to name when id and data-ec-track are absent', () => {
		document.body.innerHTML = '<form name="newsletter-name"></form>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'form' )
			.dispatchEvent( new Event( 'submit', { bubbles: true } ) );

		expect( calls[ 0 ].input.input.form ).toBe( 'newsletter-name' );
	} );

	it( 'never emits when the form has no identifiable name', () => {
		document.body.innerHTML = '<form></form>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'form' )
			.dispatchEvent( new Event( 'submit', { bubbles: true } ) );

		expect( calls ).toHaveLength( 0 );
	} );

	it( 'suppresses tracking when data-ec-track is "off"', () => {
		document.body.innerHTML = '<form id="x" data-ec-track="off"></form>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'form' )
			.dispatchEvent( new Event( 'submit', { bubbles: true } ) );

		expect( calls ).toHaveLength( 0 );
	} );

	it( 'never includes any field value in the payload', () => {
		document.body.innerHTML =
			'<form id="newsletter"><input name="email" value="reader@example.test" /></form>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'form' )
			.dispatchEvent( new Event( 'submit', { bubbles: true } ) );

		const serialized = JSON.stringify( calls[ 0 ].input );
		expect( serialized ).not.toContain( 'reader@example.test' );
		expect( serialized ).not.toContain( 'email' );
	} );
} );

describe( 'outbound_click regression', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
		window.history.pushState( {}, '', '/story/' );
	} );

	afterEach( () => {
		delete global.ecOutboundTracking;
		jest.restoreAllMocks();
	} );

	it( 'still ignores network-host anchors', () => {
		document.body.innerHTML =
			'<a href="https://extrachill.com/other-post/">Related</a>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect(
			calls.some( ( c ) => c.endpoint === OUTBOUND_ENDPOINT )
		).toBe( false );
	} );

	it( 'still records an off-network anchor click unchanged', () => {
		document.body.innerHTML =
			'<a href="https://tickets.example/show/?ref=x#top">Tickets</a>';
		const calls = loadTracker( baseConfig );

		document
			.querySelector( 'a' )
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		const outboundCalls = calls.filter(
			( c ) => c.endpoint === OUTBOUND_ENDPOINT
		);
		expect( outboundCalls ).toHaveLength( 1 );
		expect( outboundCalls[ 0 ].input ).toEqual( {
			click_type: 'outbound',
			source_url: 'http://localhost/story/',
			destination_url: 'https://tickets.example/show/',
			dest_host: 'tickets.example',
		} );
	} );
} );
