const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const source = fs.readFileSync(
	require.resolve( '../assets/js/geographic-bridge-experiment.js' ),
	'utf8'
);

let listener;
const document = {
	addEventListener( name, callback ) {
		if ( name === 'extrachill:experiment-assignment' ) {
			listener = callback;
		}
	},
	querySelectorAll() {
		return [];
	},
};

vm.runInNewContext( source, { document } );

function check( label, condition ) {
	if ( ! condition ) {
		console.error( `FAIL: ${ label }` );
		process.exit( 1 );
	}
	console.log( `PASS: ${ label }` );
}

function harness() {
	const candidateAttributes = new Set( [ 'hidden', 'inert', 'aria-hidden' ] );
	const sectionAttributes = new Set( [ 'hidden' ] );
	const candidates = {
		style: {},
		removeAttribute( name ) {
			candidateAttributes.delete( name );
		},
	};
	const section = {
		querySelectorAll( selector ) {
			return selector === '.extrachill-blog-geographic-bridge-candidates'
				? [ candidates ]
				: [];
		},
		removeAttribute( name ) {
			sectionAttributes.delete( name );
		},
	};

	return { candidateAttributes, sectionAttributes, candidates, section };
}

const control = harness();
check( 'missing provider event leaves candidates inert', control.candidateAttributes.has( 'inert' ) );
listener( {
	target: control.section,
	detail: {
		experiment_key: 'geo-bridge-holdout',
		definition_version: 1,
		assignment_policy: 'weighted_random',
		variant: 'control',
		surface: 'single-post-bridge',
	},
} );
check( 'deterministic control leaves candidates inert', control.candidateAttributes.has( 'inert' ) );
check( 'deterministic control leaves geography-only section hidden', control.sectionAttributes.has( 'hidden' ) );

const treatment = harness();
listener( {
	target: treatment.section,
	detail: {
		experiment_key: 'geo-bridge-holdout',
		definition_version: 1,
		assignment_policy: 'weighted_random',
		variant: 'treatment',
		surface: 'single-post-bridge',
	},
} );
check( 'deterministic treatment reveals candidates', ! treatment.candidateAttributes.has( 'hidden' ) );
check( 'deterministic treatment makes candidates focusable', ! treatment.candidateAttributes.has( 'inert' ) );
check( 'deterministic treatment restores accessibility', ! treatment.candidateAttributes.has( 'aria-hidden' ) );
check( 'deterministic treatment preserves responsive card layout', treatment.candidates.style.display === 'contents' );
check( 'deterministic treatment reveals geography-only section', ! treatment.sectionAttributes.has( 'hidden' ) );

const invalid = harness();
listener( {
	target: invalid.section,
	detail: {
		experiment_key: 'geo-bridge-holdout',
		variant: 'treatment',
		surface: 'wrong-surface',
	},
} );
check( 'invalid assignments fail closed', invalid.candidateAttributes.has( 'inert' ) );

check( 'activation emits no independent analytics requests', ! source.includes( 'fetch(' ) && ! source.includes( 'sendBeacon' ) );
check( 'activation consumes Network assignment event', source.includes( 'extrachill:experiment-assignment' ) );
check( 'Network retains viewport exposure ownership', ! source.includes( 'IntersectionObserver' ) );
check( 'Network retains versioned assignment proof ownership', ! source.includes( 'exposure_token' ) );
check( 'preassigned treatment reconciles listener timing', source.includes( 'data-ec-experiment-variant="treatment"' ) );

console.log( 'Geographic bridge experiment JS tests passed.' );
