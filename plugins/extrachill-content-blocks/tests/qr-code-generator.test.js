const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const createLoader = require( './helpers/load-typescript' );

function harness( request, config = {}, managed = false ) {
	const effects = [];
	const states = [];
	let cursor = 0;
	const element = {
		createElement: ( type, props, ...children ) => {
			if ( typeof type === 'function' && type.mock ) return type( { ...props, children: children.flat() } );
			return { type, props: props || {}, children: children.flat() };
		},
		useEffect: ( effect ) => { if ( ! effects.length ) effects.push( effect ); },
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
		'@extrachill/components/styles/components.scss': {},
		'@extrachill/components': Object.fromEntries( [ 'Panel', 'PanelHeader', 'ActionRow', 'FieldGroup', 'BlockShell', 'BlockIntro', 'InlineStatus', 'ImagePreview', 'DataTable' ].map( ( name ) => {
			const component = ( props ) => {
				if ( name === 'ImagePreview' ) return element.createElement( 'img', props );
				if ( name === 'DataTable' ) return element.createElement( 'table', props, ...props.data.map( ( row ) => element.createElement( 'tr', {}, ...props.columns.map( ( column ) => column.render ? column.render( row[ column.key ], row ) : row[ column.key ] ) ) ) );
				return element.createElement( name, props, props.title, props.description, props.actions, ...( props.children || [] ) );
			};
			component.mock = true;
			return [ name, component ];
		} ) ),
		'@wordpress/element': element,
		'@wordpress/api-fetch': { __esModule: true, default: request },
		'@wordpress/i18n': { __: ( text ) => text, sprintf: ( format, ...args ) => args.reduce( ( out, arg, i ) => out.replace( new RegExp( `%(${ i + 1 }\\$)?[sd]` ), arg ), format ) },
	} );
	const { ManagedCodes } = load( path.join( __dirname, '../src/blocks/qr-code-generator/managed.tsx' ) );
	const { QRCodeGenerator } = load( path.join( __dirname, '../src/blocks/qr-code-generator/view.tsx' ) );
	const render = () => { cursor = 0; return managed ? ManagedCodes( config ) : QRCodeGenerator( { config } ); };
	render.flushEffects = () => Promise.all( effects.splice( 0 ).map( ( effect ) => effect() ) );
	return render;
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
	assert.match( type( render(), 'InlineStatus' ).children.join( '' ), /30 QR codes/ );
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

const teams = [ { type: 'artist', id: 10, name: 'The Band' }, { type: 'venue', id: 20, name: 'The Room' } ];
const code = { id: 1, label: 'Flyer', destination: 'https://example.com', short_url: 'https://example.org/q/abc', team_type: 'artist', team_id: 10, scans: 12 };
const button = ( tree, text ) => find( tree, ( node ) => node.type === 'button' && node.children.includes( text ) );
const textOf = ( tree ) => JSON.stringify( tree );
function manager( request, openInitially = true ) {
	return harness( request, { teams, supporterUrl: 'https://shop.example/supporter', openInitially }, true );
}
async function loadCodes( render ) {
	await button( render(), 'Load QR codes' ).props.onClick();
}
function fillManaged( render, label, destination ) {
	const name = find( render(), ( node ) => node.type === 'input' && node.props.id.endsWith( '-name' ) );
	name.props.onChange( { target: { value: label } } );
	const url = find( render(), ( node ) => node.type === 'input' && node.props.type === 'url' );
	url.props.onChange( { target: { value: destination } } );
}

test( 'logged-out visitors see the editable-code pitch before generating', () => {
	assert.match( textOf( harness( async () => {}, { joinUrl: '/join' } )() ), /Want codes you can edit after printing/ );
} );

test( 'logged-in visitors without teams get the profile join CTA and static form', () => {
	const tree = harness( async () => {}, { loggedIn: true, teams: [], profileUrl: '/artist/join' } )();
	assert.ok( type( tree, 'form' ) );
	const link = find( tree, ( node ) => node.type === 'a' && node.props.href === '/artist/join' );
	assert.match( link.children.join( '' ), /Claim or create your artist \/ venue \/ promoter profile/ );
} );

test( 'panel lists codes grouped by team with fan reach and remaining quota', async () => {
	const other = { ...code, id: 2, team_type: 'venue', team_id: 20, label: 'Door' };
	const usage = [
		{ team_type: 'artist', team_id: 10, quota: 10, used: 1, unlimited: false },
		{ team_type: 'venue', team_id: 20, quota: 0, used: 1, unlimited: true },
	];
	const render = manager( async () => ( { items: [ code, other ], teams: usage } ) );
	await loadCodes( render );
	const tree = render();
	assert.match( textOf( tree ), /The Band/ );
	assert.match( textOf( tree ), /The Room/ );
	assert.match( textOf( tree ), /Scanned 12 times/ );
	assert.match( textOf( tree ), /1 of 10 free QR codes used/ );
	assert.match( textOf( tree ), /Unlimited QR codes/ );
	assert.doesNotMatch( textOf( tree ), /Become a supporter/ );
	const table = type( tree, 'table' );
	assert.equal( table.props.data.length, 1 );
	assert.equal( table.props.data[ 0 ].team_type, 'artist' );
} );

test( 'create calls core ability and shows the returned code', async () => {
	const requests = [];
	const render = manager( async ( options ) => {
		requests.push( options );
		return options.path.includes( '/list-' ) ? { items: [] } : code;
	} );
	await loadCodes( render );
	fillManaged( render, 'Flyer', 'https://example.com' );
	await submit( render() );
	assert.match( requests[ 1 ].path, /^\/wp-abilities\/v1\/abilities\/extrachill\/create-managed-qr-code\/run$/ );
	assert.equal( requests[ 1 ].method, 'POST' );
	assert.equal( requests[ 1 ].data.input.team_id, 10 );
	assert.equal( requests[ 1 ].data.input.destination, 'https://example.com' );
	assert.match( textOf( render() ), /Flyer/ );
	assert.match( textOf( render() ), /QR code saved/ );
} );

test( 'edit destination and rename preserve the code identity', async () => {
	let called;
	const render = manager( async ( options ) => {
		if ( options.path.includes( '/list-' ) ) return { items: [ code ] };
		called = options;
		return { ...code, ...options.data.input };
	} );
	await loadCodes( render );
	button( render(), 'Edit / rename' ).props.onClick();
	fillManaged( render, 'New flyer', 'https://new.example.com' );
	await submit( render() );
	assert.match( called.path, /update-managed-qr-code/ );
	assert.equal( called.data.input.id, code.id );
	assert.equal( called.data.input.destination, 'https://new.example.com' );
	assert.match( textOf( render() ), /New flyer/ );
} );

test( 'delete requires confirmation and frees quota', async () => {
	let deletes = 0;
	const render = manager( async ( options ) => {
		if ( options.path.includes( '/delete-' ) ) { deletes++; return { deleted: true }; }
		return { items: [ code ], teams: [ { team_type: 'artist', team_id: 10, quota: 10, used: 1, unlimited: false } ] };
	} );
	await loadCodes( render );
	button( render(), 'Delete' ).props.onClick();
	assert.equal( deletes, 0 );
	button( render(), 'Cancel' ).props.onClick();
	assert.equal( button( render(), 'Confirm delete' ), null );
	button( render(), 'Delete' ).props.onClick();
	await button( render(), 'Confirm delete' ).props.onClick();
	assert.equal( deletes, 1 );
	assert.match( textOf( render() ), /0 of 10 free QR codes used/ );
} );

test( '?view=mine config opens panel and gives logged-out visitors a return-aware login', () => {
	assert.equal( button( manager( async () => {} )(), 'Close' ).props[ 'aria-expanded' ], true );
	assert.equal( button( manager( async () => {}, false )(), 'Open' ).props[ 'aria-expanded' ], false );
	const tree = harness( async () => {}, { viewMine: true, loginUrl: '/login?redirect_to=%2Fqr-code-generator%3Fview%3Dmine' } )();
	assert.ok( find( tree, ( node ) => node.type === 'a' && node.props.href?.includes( 'view%3Dmine' ) ) );
	const source = require( 'node:fs' ).readFileSync( path.join( __dirname, '../src/blocks/qr-code-generator/view.tsx' ), 'utf8' );
	assert.match( source, /URLSearchParams.*get\( 'view' \) ===\s*'mine'/ );
} );

test( 'backend errors are surfaced through InlineStatus and can be retried', async () => {
	const render = manager( async () => { throw { message: 'An active membership on the owning team is required.' }; } );
	await loadCodes( render );
	assert.equal( type( render(), 'InlineStatus' ).props.tone, 'error' );
	assert.match( type( render(), 'InlineStatus' ).children.join( '' ), /active membership/ );
	assert.equal( button( render(), 'Load QR codes' ).props.disabled, false );
} );


test( 'deep-linked panel automatically loads codes on mount', async () => {
	let called;
	const render = manager( async ( options ) => { called = options; return { items: [ code ] }; } );
	render();
	await render.flushEffects();
	await Promise.resolve();
	assert.match( called.path, /list-managed-qr-code/ );
	assert.match( textOf( render() ), /Flyer/ );
} );

test( 'managed create rejects non-HTTP destinations before invoking ability', async () => {
	let mutations = 0;
	const render = manager( async ( options ) => {
		if ( options.path.includes( '/list-' ) ) return { items: [] };
		mutations++;
	} );
	await loadCodes( render );
	for ( const destination of [ 'ftp://example.com', 'javascript:alert(1)', '//example.com' ] ) {
		fillManaged( render, 'Flyer', destination );
		await submit( render() );
		assert.match( textOf( type( render(), 'InlineStatus' ) ), /valid HTTP or HTTPS URL/ );
	}
	assert.equal( mutations, 0 );
} );

test( 'managed PNG generation uses the immutable short URL and offers download', async () => {
	let called;
	const image = 'data:image/png;base64,cG5n';
	const render = manager( async ( options ) => {
		if ( options.path.includes( '/list-' ) ) return { items: [ code ] };
		called = options;
		return { image_url: image };
	} );
	await loadCodes( render );
	await button( render(), 'Prepare PNG' ).props.onClick();
	assert.equal( called.path, '/extrachill/v1/tools/qr-code' );
	assert.equal( called.data.url, code.short_url );
	assert.equal( download( render() ).props.href, image );
} );

test( 'teams at the free cap see the supporter upgrade, and existing codes stay editable', async () => {
	const full = Array.from( { length: 10 }, ( _, i ) => ( { ...code, id: i + 1 } ) );
	const usage = [ { team_type: 'artist', team_id: 10, quota: 10, used: 10, unlimited: false } ];
	const render = manager( async () => ( { items: full, teams: usage } ) );
	await loadCodes( render );
	const tree = render();
	assert.match( textOf( tree ), /10 of 10 free QR codes used/ );
	const link = find( tree, ( node ) => node.type === 'a' && node.props.href === 'https://shop.example/supporter' );
	assert.match( link.children.join( '' ), /Become a supporter/ );
	assert.match( textOf( tree ), /Supporters get unlimited QR codes and no ads/ );
	assert.ok( button( tree, 'Edit / rename' ) );
} );
