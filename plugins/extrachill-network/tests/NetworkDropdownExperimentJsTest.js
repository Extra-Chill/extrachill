const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const listeners = {};
const attrs = new Map( [ [ 'data-ec-experiment-variant', 'control' ], [ 'inert', '' ], [ 'aria-hidden', 'true' ] ] );
const element = {
	hidden: true,
	matches: () => true,
	getAttribute: ( key ) => attrs.get( key ),
	setAttribute: ( key, value ) => attrs.set( key, value ),
	removeAttribute: ( key ) => attrs.delete( key ),
	toggleAttribute: ( key, enabled ) => enabled ? attrs.set( key, '' ) : attrs.delete( key ),
};
vm.runInNewContext( fs.readFileSync( 'assets/js/network-dropdown-experiment.js', 'utf8' ), {
	document: {
		addEventListener: ( name, callback ) => { listeners[ name ] = callback; },
		querySelectorAll: () => [ element ],
	},
} );
assert.equal( element.hidden, true, 'control stays hidden on load' );
listeners[ 'extrachill:experiment-assignment' ]( { target: element } );
assert.equal( element.hidden, true, 'resolved control stays hidden' );
attrs.set( 'data-ec-experiment-variant', 'treatment' );
listeners[ 'extrachill:experiment-assignment' ]( { target: element } );
assert.equal( element.hidden, false, 'treatment revealed' );
assert.equal( attrs.has( 'inert' ), false );
assert.equal( attrs.has( 'aria-hidden' ), false );
attrs.set( 'data-ec-experiment-variant', 'control' );
listeners[ 'extrachill:experiment-assignment' ]( { target: element } );
assert.equal( element.hidden, true );
console.log( 'PASS: dropdown treatment/control visibility' );
