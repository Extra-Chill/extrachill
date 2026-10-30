const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const source = fs.readFileSync(
	require.resolve( '../assets/js/experiment-assignment.js' ),
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

function createElement() {
	const attributes = {
		'data-ec-experiment-key': 'geo-bridge-holdout',
		'data-ec-experiment-surface': 'single-post-bridge',
		'data-ec-experiment-variant': 'control',
		'data-ec-experiment-context': '{"post_id":42}',
	};
	const events = [];

	return {
		attributes,
		events,
		getAttribute( name ) {
			return attributes[ name ] || '';
		},
		setAttribute( name, value ) {
			attributes[ name ] = value;
		},
		dispatchEvent( event ) {
			events.push( event );
		},
	};
}

function createHarness( result, exposureAccepted = true, hasElements = true ) {
	const element = createElement();
	const requests = [];
	const observers = [];

	class FakeCustomEvent {
		constructor( type, options ) {
			this.type = type;
			this.detail = options.detail;
			this.bubbles = options.bubbles;
		}
	}

	const window = {
		ecExperimentAssignment: {
			assignmentEndpoint: '/assignment',
			exposureEndpoint: '/exposure',
		},
		fetch( url, options ) {
			requests.push( { url, options } );
			const response = url === '/exposure'
				? { accepted: exposureAccepted }
				: result;
			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( response ),
			} );
		},
		IntersectionObserver: class {
			constructor( callback, options ) {
				this.callback = callback;
				this.options = options;
				this.observed = [];
				this.disconnected = false;
				observers.push( this );
			}
			observe( target ) {
				this.observed.push( target );
			}
			disconnect() {
				this.disconnected = true;
			}
		},
	};
	const context = {
		window,
		document: {
			querySelectorAll() {
				return hasElements ? [ element ] : [];
			},
		},
		CustomEvent: FakeCustomEvent,
		URLSearchParams,
		console,
	};

	vm.runInNewContext( source, context );
	return { element, requests, observers };
}

function flushPromises() {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

async function run() {
	const noExperiment = createHarness( {}, true, false );
	check( 'page without experiment attributes makes no Ability request', noExperiment.requests.length === 0 );
	check( 'page without experiment attributes starts no exposure observer', noExperiment.observers.length === 0 );

	const assigned = createHarness( {
		experiment_key: 'geo-bridge-holdout',
		definition_version: 1,
		assignment_policy: 'weighted_random',
		variant: 'treatment',
		surface: 'single-post-bridge',
		measurement_eligible: true,
		exposure_token: '1700000000.' + '1'.repeat( 32 ) + '.' + 'a'.repeat( 64 ),
	} );

	check(
		'cached markup starts in control before assignment resolves',
		assigned.element.attributes[ 'data-ec-experiment-variant' ] === 'control'
	);
	check( 'assignment is requested through the configured ability endpoint', assigned.requests.length === 1 );
	check(
		'request carries bounded eligibility context',
		decodeURIComponent( assigned.requests[ 0 ].url ).includes( 'input[context][post_id]=42' )
	);
	await flushPromises();
	check(
		'eligible response applies the deterministic treatment',
		assigned.element.attributes[ 'data-ec-experiment-variant' ] === 'treatment'
	);
	check(
		'assignment emits bounded neutral metadata',
		assigned.element.events.length === 1 &&
			assigned.element.events[ 0 ].type === 'extrachill:experiment-assignment' &&
			Object.keys( assigned.element.events[ 0 ].detail ).sort().join( ',' ) ===
				'assignment_policy,definition_version,experiment_key,surface,variant'
	);
	check( 'assignment alone is not an exposure', assigned.element.events.length === 1 );
	check( 'exposure observer uses a 50% threshold', assigned.observers[ 0 ].options.threshold[ 0 ] === 0.5 );

	assigned.observers[ 0 ].callback( [
		{ isIntersecting: true, intersectionRatio: 0.49 },
	] );
	check( 'sub-threshold visibility is not exposure', assigned.element.events.length === 1 );
	assigned.observers[ 0 ].callback( [
		{ isIntersecting: true, intersectionRatio: 0.5 },
	] );
	check( 'viewport visibility alone is not trusted exposure', assigned.element.events.length === 1 );
	check( 'viewport visibility calls the signed exposure ability', assigned.requests.length === 2 && assigned.requests[ 1 ].url === '/exposure' );
	const exposureInput = JSON.parse( assigned.requests[ 1 ].options.body ).input;
	check( 'exposure request carries the server-issued proof', exposureInput.exposure_token === '1700000000.' + '1'.repeat( 32 ) + '.' + 'a'.repeat( 64 ) );
	check( 'exposure request carries signed definition version', exposureInput.definition_version === 1 );
	check( 'exposure request carries signed assignment policy', exposureInput.assignment_policy === 'weighted_random' );
	await flushPromises();
	check(
		'server-accepted viewport exposure emits separately',
		assigned.element.events.length === 2 &&
			assigned.element.events[ 1 ].type === 'extrachill:experiment-exposure'
	);
	check( 'exposure is one-shot', assigned.observers[ 0 ].disconnected === true );

	const excluded = createHarness( {
		experiment_key: 'geo-bridge-holdout',
		definition_version: 1,
		assignment_policy: 'weighted_random',
		variant: 'control',
		surface: 'single-post-bridge',
		measurement_eligible: false,
		exposure_token: '',
	} );
	await flushPromises();
	check(
		'privacy-excluded response leaves cache control unchanged',
		excluded.element.attributes[ 'data-ec-experiment-variant' ] === 'control'
	);
	check( 'privacy-excluded response emits no events', excluded.element.events.length === 0 );
	check( 'privacy-excluded response starts no exposure observer', excluded.observers.length === 0 );

	const rejected = createHarness( {
		experiment_key: 'geo-bridge-holdout',
		definition_version: 1,
		assignment_policy: 'weighted_random',
		variant: 'treatment',
		surface: 'single-post-bridge',
		measurement_eligible: true,
		exposure_token: '1700000000.' + '2'.repeat( 32 ) + '.' + 'b'.repeat( 64 ),
	}, false );
	await flushPromises();
	rejected.observers[ 0 ].callback( [
		{ isIntersecting: true, intersectionRatio: 1 },
	] );
	await flushPromises();
	check( 'server-rejected exposure emits no exposure event', rejected.element.events.length === 1 );

	if ( failures > 0 ) {
		process.exit( 1 );
	}

	console.log( 'All experiment assignment JS tests passed.' );
}

run();
