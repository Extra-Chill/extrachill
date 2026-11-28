const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const root = path.join( __dirname, '..' );
const presentation = fs.readFileSync( path.join( root, 'assets/js/network-dropdown-experiment.js' ), 'utf8' );
const runtime = fs.readFileSync( path.join( root, 'assets/js/experiment-assignment.js' ), 'utf8' );

/** Minimal element: attributes, class matching and sibling links. */
function node( className, attributes = {} ) {
	const attrs = new Map( Object.entries( attributes ) );
	const el = {
		className,
		hidden: attrs.has( 'hidden' ),
		nextElementSibling: null,
		events: [],
		matches( selector ) {
			if ( selector.startsWith( '.' ) ) {
				return selector.slice( 1 ) === className;
			}
			return [ ...selector.matchAll( /\[([^=\]]+)="([^"]*)"\]/g ) ].every( ( m ) => attrs.get( m[ 1 ] ) === m[ 2 ] );
		},
		getAttribute: ( key ) => ( attrs.has( key ) ? attrs.get( key ) : null ),
		setAttribute: ( key, value ) => attrs.set( key, String( value ) ),
		removeAttribute: ( key ) => attrs.delete( key ),
		hasAttribute: ( key ) => attrs.has( key ),
		toggleAttribute: ( key, enabled ) => ( enabled ? attrs.set( key, '' ) : attrs.delete( key ) ),
		dispatchEvent( event ) {
			el.events.push( event );
			( listeners[ event.type ] || [] ).forEach( ( cb ) => cb( { target: el, detail: event.detail } ) );
		},
	};
	return el;
}

let listeners = {};

/** Mirror the server markup: measured wrapper, optional control crumb, hidden treatment. */
function breadcrumb( withSiteCrumb ) {
	const wrapper = node( 'ec-network-dropdown-experiment', {
		'data-ec-experiment-key': 'network-dropdown-everywhere',
		'data-ec-experiment-surface': 'breadcrumb-network-dropdown',
		'data-ec-experiment-variant': 'control',
		'data-ec-experiment-context': '{"blog_id":2,"existing_dropdown":"0"}',
	} );
	const control = withSiteCrumb ? node( 'ec-network-dropdown-experiment-control' ) : null;
	const treatment = node( 'ec-network-dropdown-experiment-treatment', { hidden: '', inert: '', 'aria-hidden': 'true' } );
	treatment.hidden = true;
	if ( control ) {
		wrapper.nextElementSibling = control;
		control.nextElementSibling = treatment;
	} else {
		wrapper.nextElementSibling = treatment;
	}
	return { wrapper, control, treatment };
}

function flush() {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

/** Run the real runtime + presentation scripts against one breadcrumb for an assigned variant. */
async function simulate( variant, withSiteCrumb ) {
	listeners = {};
	const crumb = breadcrumb( withSiteCrumb );
	const requests = [];
	const observers = [];
	class FakeCustomEvent {
		constructor( type, options ) {
			this.type = type;
			this.detail = options.detail;
		}
	}
	const window = {
		ecExperimentAssignment: { assignmentEndpoint: '/assignment', exposureEndpoint: '/exposure' },
		fetch( url, options ) {
			requests.push( { url, options } );
			const body = url === '/exposure' ? { accepted: true } : {
				experiment_key: 'network-dropdown-everywhere',
				definition_version: 3,
				assignment_policy: 'weighted_random',
				variant,
				surface: 'breadcrumb-network-dropdown',
				measurement_eligible: true,
				exposure_token: 'token',
			};
			return Promise.resolve( { ok: true, json: () => Promise.resolve( body ) } );
		},
		IntersectionObserver: class {
			constructor( callback ) {
				this.callback = callback;
				this.observed = [];
				observers.push( this );
			}
			observe( target ) {
				this.observed.push( target );
			}
			disconnect() {}
		},
	};
	const document = {
		addEventListener: ( name, cb ) => {
			( listeners[ name ] = listeners[ name ] || [] ).push( cb );
		},
		querySelectorAll: () => [ crumb.wrapper ],
	};
	const context = { window, document, CustomEvent: FakeCustomEvent, URLSearchParams, console };
	vm.runInNewContext( presentation, context );

	// Cached page before assignment renders today's breadcrumb.
	if ( crumb.control ) {
		assert.equal( crumb.control.hidden, false, 'control crumb visible before assignment' );
	}
	assert.equal( crumb.treatment.hidden, true, 'treatment hidden before assignment' );

	vm.runInNewContext( runtime, context );
	await flush();

	// The observed element is the always-visible wrapper, in both arms.
	assert.equal( observers.length, 1, variant + ': one exposure observer' );
	assert.equal( observers[ 0 ].observed[ 0 ], crumb.wrapper, variant + ': exposure measured on shared wrapper' );
	assert.equal( crumb.wrapper.hidden, false, variant + ': measured wrapper never hidden' );
	assert.equal( crumb.wrapper.hasAttribute( 'inert' ), false );
	observers[ 0 ].callback( [ { isIntersecting: true, intersectionRatio: 1 } ] );
	await flush();
	const exposures = requests.filter( ( r ) => r.url === '/exposure' );
	assert.equal( exposures.length, 1, variant + ': exposure recorded' );
	assert.equal( JSON.parse( exposures[ 0 ].options.body ).input.variant, variant );
	return crumb;
}

( async () => {
	// Site root `Extra Chill › Community`.
	let crumb = await simulate( 'control', true );
	assert.equal( crumb.control.hidden, false, 'control keeps site crumb' );
	assert.equal( crumb.treatment.hidden, true, 'control hides dropdown' );
	assert.equal( crumb.treatment.hasAttribute( 'inert' ), true );

	crumb = await simulate( 'treatment', true );
	assert.equal( crumb.control.hidden, true, 'treatment hides site crumb (no duplicate label)' );
	assert.equal( crumb.control.hasAttribute( 'inert' ), true );
	assert.equal( crumb.control.getAttribute( 'aria-hidden' ), 'true' );
	assert.equal( crumb.treatment.hidden, false, 'treatment reveals dropdown' );
	assert.equal( crumb.treatment.hasAttribute( 'inert' ), false );
	assert.equal( crumb.treatment.hasAttribute( 'aria-hidden' ), false );
	console.log( 'PASS: site root swaps crumb for dropdown without duplicate' );

	// Main-site root `Extra Chill` (no site crumb).
	crumb = await simulate( 'control', false );
	assert.equal( crumb.treatment.hidden, true, 'main control unchanged' );
	crumb = await simulate( 'treatment', false );
	assert.equal( crumb.treatment.hidden, false, 'main treatment appends Blog dropdown' );
	console.log( 'PASS: main-site root' );
	console.log( 'PASS: control and treatment exposed under identical condition' );
} )().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
