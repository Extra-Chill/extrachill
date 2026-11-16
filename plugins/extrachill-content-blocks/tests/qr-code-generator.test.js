const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const createLoader = require( './helpers/load-typescript' );

function harness( request, config = {} ) {
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
	}, {
		'@wordpress/element': element,
		'@wordpress/api-fetch': { __esModule: true, default: request },
		'@wordpress/i18n': { __: ( text ) => text, sprintf: ( format, ...args ) => args.reduce( ( out, arg ) => out.replace( '%s', arg ), format ) },
	} );
	const { QRCodeGenerator } = load( path.join( __dirname, '../src/blocks/qr-code-generator/view.tsx' ) );
	return () => { cursor = 0; return QRCodeGenerator( { config } ); };
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
const submitButton = ( tree ) => find( tree, ( node ) => node.type === 'button' && node.props.type === 'submit' );
const download = ( tree ) => find( tree, ( node ) => node.type === 'a' && node.props.download );
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
	assert.equal( download( tree ).props.href, image );
	assert.equal( download( tree ).props.download, 'qr-code.png' );
	type( tree, 'input' ).props.onChange( { target: { value: 'https://other.example' } } );
	assert.equal( download( render() ), null, 'editing clears stale download' );
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
	assert.equal( submitButton( render() ).props.disabled, true );
	await submit( render() );
	assert.equal( calls, 1 );
	reject( { message: 'You can generate 30 QR codes per hour. Please try again later.', data: { status: 429 } } );
	await pending;
	assert.match( find( render(), ( node ) => node.props.role === 'alert' ).children[ 0 ], /30 QR codes/ );
	assert.equal( submitButton( render() ).props.disabled, false );
	assert.equal( download( render() ), null );
} );

test( 'music presets label the download without restricting the URL', async () => {
	const image = 'data:image/png;base64,cG5n';
	const render = harness( async () => ( { image_url: image } ) );
	find( render(), ( node ) => node.props[ 'data-preset' ] === 'bandcamp' ).props.onClick();
	assert.match( type( render(), 'input' ).props.placeholder, /bandcamp/ );
	type( render(), 'input' ).props.onChange( { target: { value: 'https://example.com/anything' } } );
	await submit( render() );
	assert.equal( download( render() ).props.download, 'bandcamp-qr-code.png' );
} );

test( 'after generating, the CTA leads to the extrachill.link join flow', async () => {
	const image = 'data:image/png;base64,cG5n';
	const render = harness( async () => ( { image_url: image } ), { joinUrl: 'https://extrachill.link/join' } );
	assert.equal( find( render(), ( node ) => node.props[ 'data-qr-join' ] ), null );
	type( render(), 'input' ).props.onChange( { target: { value: 'https://example.com' } } );
	await submit( render() );
	const cta = find( render(), ( node ) => node.props[ 'data-qr-join' ] );
	assert.equal( type( cta, 'a' ).props.href, 'https://extrachill.link/join' );
} );
