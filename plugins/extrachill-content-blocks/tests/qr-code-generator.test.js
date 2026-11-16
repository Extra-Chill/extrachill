const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const createLoader = require( './helpers/load-typescript' );

function harness( request ) {
	const states = [];
	let cursor = 0;
	const element = {
		createElement: ( type, props, ...children ) => ( { type, props: props || {}, children: children.flat() } ),
		useState: ( initial ) => {
			const index = cursor++;
			if ( ! ( index in states ) ) states[ index ] = initial;
			return [ states[ index ], ( value ) => { states[ index ] = value; } ];
		},
		useRef: ( initial ) => {
			const index = cursor++;
			return states[ index ] ||= { current: initial };
		},
	};
	const load = createLoader( {
		URL,
		React: { Fragment: 'fragment' },
		document: { readyState: 'loading', addEventListener() {} },
	}, { '@wordpress/element': element, '@wordpress/api-fetch': { default: request } } );
	const { QRCodeGenerator } = load( path.join( __dirname, '../src/blocks/qr-code-generator/view.tsx' ) );
	return () => { cursor = 0; return QRCodeGenerator(); };
}
function find( tree, predicate ) {
	if ( ! tree || typeof tree !== 'object' ) return null;
	if ( predicate( tree ) ) return tree;
	for ( const child of tree.children || [] ) {
		const found = find( child, predicate );
		if ( found ) return found;
	}
	return null;
}
const type = ( tree, name ) => find( tree, ( node ) => node.type === name );
const submit = ( tree ) => type( tree, 'form' ).props.onSubmit( { preventDefault() {} } );

test( 'public form generates a preview and downloadable PNG via existing route', async () => {
	let called;
	const image = 'data:image/png;base64,cG5n';
	const render = harness( async ( options ) => { called = options; return { image_url: image }; } );
	let tree = render();
	assert.equal( type( tree, 'input' ).props.type, 'url' );
	type( tree, 'input' ).props.onChange( { target: { value: 'https://example.com' } } );
	await submit( render() );
	assert.equal( called.path, '/extrachill/v1/tools/qr-code' );
	assert.equal( called.method, 'POST' );
	assert.equal( called.data.url, 'https://example.com' );
	tree = render();
	assert.equal( type( tree, 'img' ).props.src, image );
	assert.equal( type( tree, 'a' ).props.href, image );
	assert.equal( type( tree, 'a' ).props.download, 'qr-code.png' );
	type( tree, 'input' ).props.onChange( { target: { value: 'https://other.example' } } );
	assert.equal( type( render(), 'a' ), null, 'editing clears stale download' );
} );

test( 'invalid schemes never request generation', async () => {
	let calls = 0;
	const render = harness( async () => { ++calls; } );
	for ( const value of [ 'ftp://example.com', 'javascript:alert(1)', 'invalid' ] ) {
		type( render(), 'input' ).props.onChange( { target: { value } } );
		await submit( render() );
		assert.equal( type( render(), 'p' ).children.length > 0, true );
		assert.ok( find( render(), ( node ) => node.props.role === 'alert' ) );
	}
	assert.equal( calls, 0 );
} );

test( 'pending requests disable Generate, block duplicate submits, and show 429 messages', async () => {
	let reject;
	let calls = 0;
	const render = harness( () => { ++calls; return new Promise( ( resolve, fail ) => { reject = fail; } ); } );
	type( render(), 'input' ).props.onChange( { target: { value: 'https://example.com' } } );
	const pending = submit( render() );
	assert.equal( type( render(), 'button' ).props.disabled, true );
	await submit( render() );
	assert.equal( calls, 1 );
	reject( { message: 'You can generate 30 QR codes per hour. Please try again later.', data: { status: 429 } } );
	await pending;
	assert.match( find( render(), ( node ) => node.props.role === 'alert' ).children[ 0 ], /30 QR codes/ );
	assert.equal( type( render(), 'button' ).props.disabled, false );
	assert.equal( type( render(), 'a' ), null );
} );
