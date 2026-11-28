const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const assert = require( 'node:assert/strict' );

const source = fs.readFileSync( __dirname + '/../../assets/js/link-page-edit-button.js', 'utf8' );
const origin = 'https://links.example';

async function run( { referrer = '', status = 401, cookie = '', attempted = false, canEdit = true } = {} ) {
	const requests = [];
	const navigations = [];
	const buttons = [];
	const document = {
		readyState: 'complete',
		referrer,
		cookie,
		body: {
			dataset: {
				extrchPermissionsApiUrl: origin + '/permissions',
				extrchSessionUrl: origin + '/session',
				extrchSessionHandoffUrl: origin + '/handoff',
				extrchNetworkUrl: 'https://example.com/',
			},
			appendChild: ( button ) => buttons.push( button ),
		},
		createElement: () => ( { setAttribute() {} } ),
	};
	const window = {
		location: { origin, pathname: '/band', assign: ( url ) => navigations.push( url ) },
		sessionStorage: { getItem: () => attempted ? '1' : null, setItem() {}, removeItem() {} },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	};
	const fetch = async ( url, options ) => {
		requests.push( { url, options } );
		return url.endsWith( '/session' )
			? { status, ok: status === 200, json: async () => ( { nonce: 'rest-nonce' } ) }
			: { ok: true, json: async () => ( { can_edit: canEdit, manage_url: origin + '/edit?link_page=42' } ) };
	};
	vm.runInNewContext( source, { window, document, fetch, URL } );
	await new Promise( setImmediate );
	return { requests, navigations, buttons };
}

async function verify() {
	for ( const referrer of [ '', 'https://other.example/', 'https://example.com.evil.net/' ] ) {
		const result = await run( { referrer } );
		assert.equal( result.navigations.length, 0 );
		assert.equal( result.requests.length, 1 );
		assert.equal( result.buttons.length, 0 );
	}
	const referrer = 'https://artist.example.com/manage/';
	const result = await run( { referrer } );
	assert.equal( new URL( result.navigations[ 0 ] ).searchParams.get( 'return' ), origin + '/band' );
	assert.equal( ( await run( { referrer, attempted: true } ) ).navigations.length, 0 );
	assert.equal( ( await run( { cookie: 'ec_link_editor=1' } ) ).navigations.length, 1 );
	assert.equal( ( await run( { cookie: 'not_ec_link_editor=1' } ) ).navigations.length, 0 );
	const signedIn = await run( { status: 200 } );
	assert.equal( signedIn.requests[ 1 ].options.credentials, 'same-origin' );
	assert.equal( signedIn.requests[ 1 ].options.headers[ 'X-WP-Nonce' ], 'rest-nonce' );
	assert.equal( signedIn.buttons[ 0 ].href, origin + '/edit?link_page=42' );
	assert.equal( signedIn.navigations.length, 0 );
	assert.equal( ( await run( { status: 200, canEdit: false } ) ).buttons.length, 0 );
}

verify().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
