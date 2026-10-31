const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const createLoader = require( './helpers/load-typescript' );

const load = createLoader( { URLSearchParams } );
const { selectFocusedEntry } = load(
	path.join( __dirname, '../src/blocks/photo-contest/focused-entry.ts' )
);
const entries = [ { id: 1 }, { id: 2 }, { id: 3, hidden: true } ];

test( 'valid deep link selects only the requested visible entry', () => {
	assert.equal(
		selectFocusedEntry( entries, '?other=ok&entry=2' ),
		entries[ 1 ]
	);
} );

test( 'invalid or hidden ids fall back to the gallery', () => {
	for ( const id of [
		'99',
		'3',
		'0',
		'-1',
		'1.5',
		'nope',
		'',
		'Infinity',
	] ) {
		assert.equal( selectFocusedEntry( entries, `?entry=${ id }` ), null );
	}
	assert.equal( selectFocusedEntry( [], '?entry=2' ), null );
} );

test( 'no entry parameter selects the normal gallery', () => {
	assert.equal( selectFocusedEntry( entries, '' ), null );
	assert.equal( selectFocusedEntry( entries, '?other=2' ), null );
} );
