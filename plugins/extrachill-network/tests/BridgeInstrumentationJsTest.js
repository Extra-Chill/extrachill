const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const source = fs.readFileSync(
	require.resolve( '../assets/js/bridge-instrumentation.js' ),
	'utf8'
);

let failures = 0;

function check( label, condition ) {
	if ( condition ) {
		console.log( `PASS: ${ label }` );
		return;
	}

	console.error( `FAIL: ${ label }` );
	failures++;
}

function makeLink( destination, label, rect ) {
	return {
		textContent: label,
		rect,
		unavailable: '',
		getAttribute( name ) {
			return name === 'href'
				? `https://${ destination }.extrachill.com/item?utm_campaign=${ destination }`
				: '';
		},
		getBoundingClientRect() {
			return this.rect;
		},
		closest( selector ) {
			if ( selector === '.ec-cross-site-link' ) {
				return this;
			}
			if ( ! this.unavailable ) {
				return null;
			}
			if ( this.unavailable === 'hidden' && selector.includes( '[hidden]' ) ) {
				return this;
			}
			if ( this.unavailable === 'inert' && selector.includes( '[inert]' ) ) {
				return this;
			}
			return this.unavailable === 'aria-hidden' && selector.includes( '[aria-hidden="true"]' )
				? this
				: null;
		},
	};
}

function createHarness( links, supportsObserver, transport = {} ) {
	const events = [];
	const beaconAttempts = [];
	const fetchAttempts = [];
	const listeners = {};
	const removedListeners = [];
	const timers = [];
	const observers = [];
	const beaconResults = [ ...( transport.beaconResults || [] ) ];
	const fetchResults = [ ...( transport.fetchResults || [] ) ];
	let clickHandler;

	class FakeBlob {
		constructor( parts ) {
			this.parts = parts;
		}
	}

	const window = {
		ecBridgeInstrumentation: {
			clickEndpoint: '/click',
			impressionEndpoint: '/impression',
			linkClass: 'ec-cross-site-link',
			sourcePost: 42,
			sourceSite: 'main',
		},
		location: {
			href: 'https://extrachill.com/source',
			origin: 'https://extrachill.com',
		},
		innerWidth: 1000,
		innerHeight: 800,
		addEventListener( type, handler ) {
			listeners[ type ] = handler;
		},
		removeEventListener( type, handler ) {
			if ( listeners[ type ] === handler ) {
				removedListeners.push( type );
				delete listeners[ type ];
			}
		},
		setTimeout( handler, delay ) {
			timers.push( { handler, delay } );
			return timers.length;
		},
		clearTimeout() {},
		fetch( endpoint, options ) {
			const result = fetchResults.length > 0 ? fetchResults.shift() : { ok: true };
			const attempt = {
				endpoint,
				payload: JSON.parse( options.body ),
				keepalive: options.keepalive,
			};
			fetchAttempts.push( attempt );

			if ( result === 'throw' ) {
				throw new Error( 'Synchronous fetch failure' );
			}
			if ( result instanceof Error ) {
				return Promise.reject( result );
			}

			return Promise.resolve( result ).then( ( response ) => {
				if ( response.ok ) {
					events.push( { ...attempt, transport: 'fetch' } );
				}
				return response;
			} );
		},
	};

	if ( supportsObserver ) {
		window.IntersectionObserver = class {
			constructor( callback, options ) {
				this.callback = callback;
				this.options = options;
				this.observed = [];
				this.unobserved = [];
				observers.push( this );
			}
			observe( link ) {
				this.observed.push( link );
			}
			unobserve( link ) {
				this.unobserved.push( link );
			}
		};
	}

	const context = {
		window,
		document: {
			getElementsByClassName() {
				return links;
			},
			addEventListener( type, handler ) {
				if ( type === 'click' ) {
					clickHandler = handler;
				}
			},
		},
		navigator: {
			sendBeacon( endpoint, blob ) {
				const result = beaconResults.length > 0 ? beaconResults.shift() : true;
				const attempt = {
					endpoint,
					payload: JSON.parse( blob.parts.join( '' ) ),
				};
				beaconAttempts.push( attempt );
				if ( result === 'throw' ) {
					throw new Error( 'Beacon failure' );
				}
				if ( result ) {
					events.push( { ...attempt, transport: 'beacon' } );
				}
				return result;
			},
		},
		Blob: FakeBlob,
		URL,
		console,
	};

	vm.runInNewContext( source, context );

	return {
		events,
		beaconAttempts,
		fetchAttempts,
		listeners,
		removedListeners,
		timers,
		observers,
		click( link ) {
			let prevented = false;
			clickHandler( {
				target: link,
				preventDefault() {
					prevented = true;
				},
			} );
			return prevented;
		},
	};
}

function flushPromises() {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

const visibleRect = {
	top: 100,
	right: 300,
	bottom: 140,
	left: 100,
	width: 200,
	height: 40,
};
const hiddenRect = {
	top: 900,
	right: 300,
	bottom: 940,
	left: 100,
	width: 200,
	height: 40,
};

const eventsLink = makeLink( 'events', 'Events', visibleRect );
const wireLink = makeLink( 'wire', 'Wire', hiddenRect );
const observerHarness = createHarness( [ eventsLink, wireLink ], true );
const observer = observerHarness.observers[ 0 ];

check(
	'IntersectionObserver uses a 50% threshold',
	observer.options.threshold[ 0 ] === 0.5
);
check( 'all initial bridge elements are observed', observer.observed.length === 2 );
check( 'renders do not count before viewport exposure', observerHarness.events.length === 0 );

observer.callback( [
	{ target: eventsLink, isIntersecting: true, intersectionRatio: 0.49 },
] );
check( 'less than 50% visibility does not count', observerHarness.events.length === 0 );

observer.callback( [
	{ target: eventsLink, isIntersecting: true, intersectionRatio: 0.5 },
] );
observer.callback( [
	{ target: eventsLink, isIntersecting: true, intersectionRatio: 1 },
] );
check( 'viewport exposure is deduped by element', observerHarness.events.length === 1 );
check( 'exposure retains destination identity', observerHarness.events[ 0 ].payload.dest_site === 'events' );
check( 'exposed elements stop being observed', observer.unobserved[ 0 ] === eventsLink );

check( 'click handling does not prevent navigation', observerHarness.click( eventsLink ) === false );
observerHarness.click( eventsLink );
check(
	'click is deduped by the same element opportunity',
	observerHarness.events.filter( ( event ) => event.endpoint === '/click' ).length === 1
);

observerHarness.click( wireLink );
check(
	'a click emits its missing exposure before the click',
	observerHarness.events
		.slice( -2 )
		.map( ( event ) => event.endpoint )
		.join( ',' ) === '/impression,/click'
);
check(
	'successful client submissions share one opportunity',
	observerHarness.events.filter( ( event ) => event.endpoint === '/click' ).length ===
		observerHarness.events.filter( ( event ) => event.endpoint === '/impression' ).length
);

const communityLink = makeLink( 'community', 'Community', visibleRect );
const hiddenLink = makeLink( 'events', 'Hidden Events', visibleRect );
const inertLink = makeLink( 'wire', 'Inert Wire', visibleRect );
communityLink.unavailable = 'aria-hidden';
hiddenLink.unavailable = 'hidden';
inertLink.unavailable = 'inert';
const hiddenHarness = createHarness( [ communityLink, hiddenLink, inertLink ], true );
const hiddenObserver = hiddenHarness.observers[ 0 ];
hiddenObserver.callback( [
	{ target: communityLink, isIntersecting: true, intersectionRatio: 1 },
	{ target: hiddenLink, isIntersecting: true, intersectionRatio: 1 },
	{ target: inertLink, isIntersecting: true, intersectionRatio: 1 },
] );
check( 'hidden, inert, and aria-hidden candidates do not count', hiddenHarness.events.length === 0 );
check( 'hidden candidates remain observed for later activation', ! hiddenObserver.unobserved.includes( communityLink ) );

communityLink.unavailable = '';
hiddenObserver.callback( [
	{ target: communityLink, isIntersecting: true, intersectionRatio: 1 },
] );
check( 'activated treatment candidate counts exactly once', hiddenHarness.events.length === 1 );
hiddenObserver.callback( [
	{ target: communityLink, isIntersecting: true, intersectionRatio: 1 },
] );
check( 'activated treatment exposure remains deduped', hiddenHarness.events.length === 1 );

const hiddenFallbackLink = makeLink( 'community', 'Hidden Community', visibleRect );
hiddenFallbackLink.unavailable = 'hidden';
const hiddenFallbackHarness = createHarness( [ hiddenFallbackLink ], false );
check( 'fallback does not expose hidden candidates', hiddenFallbackHarness.events.length === 0 );
hiddenFallbackHarness.click( hiddenFallbackLink );
check( 'delegated clicks ignore unavailable candidates', hiddenFallbackHarness.events.length === 0 );

const clickFirstLink = makeLink( 'events', 'Click First', visibleRect );
const clickFirstHarness = createHarness( [ clickFirstLink ], true );
const clickFirstObserver = clickFirstHarness.observers[ 0 ];
clickFirstHarness.click( clickFirstLink );
clickFirstObserver.callback( [
	{ target: clickFirstLink, isIntersecting: true, intersectionRatio: 1 },
] );
check( 'click-first exposed links stop being observed', clickFirstObserver.unobserved.includes( clickFirstLink ) );

const duplicateOne = makeLink( 'events', 'Events One', visibleRect );
const duplicateTwo = makeLink( 'events', 'Events Two', visibleRect );
const duplicateHarness = createHarness( [ duplicateOne, duplicateTwo ], true );
const duplicateObserver = duplicateHarness.observers[ 0 ];
duplicateObserver.callback( [
	{ target: duplicateOne, isIntersecting: true, intersectionRatio: 1 },
	{ target: duplicateTwo, isIntersecting: true, intersectionRatio: 1 },
] );
check(
	'separate elements remain separate opportunities',
	duplicateHarness.events.length === 2
);

const fallbackVisible = makeLink( 'community', 'Community', visibleRect );
const fallbackHidden = makeLink( 'artist', 'Artist', hiddenRect );
const fallbackHarness = createHarness( [ fallbackVisible, fallbackHidden ], false );

check(
	'fallback counts only initially visible elements',
	fallbackHarness.events.length === 1
);
check(
	'fallback lifetime is bounded to 30 seconds',
	fallbackHarness.timers[ 0 ].delay === 30000
);

fallbackHidden.rect = visibleRect;
fallbackHarness.listeners.scroll();
check(
	'fallback counts elements when they enter the viewport',
	fallbackHarness.events.length === 2
);
check(
	'fallback removes scroll and resize listeners after all exposures',
	fallbackHarness.removedListeners.includes( 'scroll' ) &&
		fallbackHarness.removedListeners.includes( 'resize' )
);

const timedOutLink = makeLink( 'shop', 'Shop', hiddenRect );
const timedOutHarness = createHarness( [ timedOutLink ], false );
timedOutHarness.timers[ 0 ].handler();
check(
	'fallback removes listeners when its time bound expires',
	timedOutHarness.removedListeners.includes( 'scroll' ) &&
		timedOutHarness.removedListeners.includes( 'resize' )
);
timedOutHarness.click( timedOutLink );
check(
	'click still creates one exposure after fallback expiry',
	timedOutHarness.events.length === 2
);

async function runDeliveryTests() {
	const fallbackLink = makeLink( 'community', 'Community', visibleRect );
	const beaconFallbackHarness = createHarness( [ fallbackLink ], true, {
		beaconResults: [ false ],
		fetchResults: [ { ok: true } ],
	} );
	beaconFallbackHarness.observers[ 0 ].callback( [
		{ target: fallbackLink, isIntersecting: true, intersectionRatio: 1 },
	] );
	await flushPromises();
	check(
		'sendBeacon false falls back to keepalive fetch',
		beaconFallbackHarness.beaconAttempts.length === 1 &&
			beaconFallbackHarness.fetchAttempts.length === 1 &&
			beaconFallbackHarness.fetchAttempts[ 0 ].keepalive === true &&
			beaconFallbackHarness.events[ 0 ].transport === 'fetch'
	);

	const retryLink = makeLink( 'artist', 'Artist', visibleRect );
	const retryHarness = createHarness( [ retryLink ], true, {
		beaconResults: [ false ],
		fetchResults: [ new Error( 'Network failure' ), { ok: true } ],
	} );
	retryHarness.observers[ 0 ].callback( [
		{ target: retryLink, isIntersecting: true, intersectionRatio: 1 },
	] );
	await flushPromises();
	check(
		'failed fallback fetch retries once',
		retryHarness.fetchAttempts.length === 2 && retryHarness.events.length === 1
	);

	const lossyLink = makeLink( 'shop', 'Shop', visibleRect );
	const lossyHarness = createHarness( [ lossyLink ], true, {
		beaconResults: [ false, true ],
		fetchResults: [ new Error( 'Network failure' ), new Error( 'Retry failure' ) ],
	} );
	lossyHarness.observers[ 0 ].callback( [
		{ target: lossyLink, isIntersecting: true, intersectionRatio: 1 },
	] );
	await flushPromises();
	check( 'failed exposure delivery exhausts one retry', lossyHarness.fetchAttempts.length === 2 );
	check( 'delivery failure does not prevent navigation', lossyHarness.click( lossyLink ) === false );
	check(
		'independent delivery can store a click without its exposure',
		lossyHarness.events.length === 1 && lossyHarness.events[ 0 ].endpoint === '/click'
	);

	const initialLink = makeLink( 'events', 'Events', visibleRect );
	const dynamicLinks = [ initialLink ];
	const dynamicHarness = createHarness( dynamicLinks, true );
	const dynamicLink = makeLink( 'wire', 'Wire', visibleRect );
	dynamicLinks.push( dynamicLink );
	check( 'dynamic links remain navigable', dynamicHarness.click( dynamicLink ) === false );
	check( 'dynamic links are explicitly outside instrumentation', dynamicHarness.events.length === 0 );
}

runDeliveryTests().then( () => {
	if ( failures > 0 ) {
		process.exit( 1 );
	}

	console.log( 'All bridge instrumentation JS tests passed.' );
} );
