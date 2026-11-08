const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const assert = require( 'node:assert/strict' );

const source = fs.readFileSync( __dirname + '/../../assets/js/link-page-edit-button.js', 'utf8' );
const HANDOFF = 'https://artist.example.com/wp-admin/admin-post.php?action=ec_link_token_handoff';

function store( initial = {} ) {
	const data = { ...initial };
	return {
		data,
		getItem: ( key ) => ( key in data ? data[ key ] : null ),
		setItem: ( key, value ) => { data[ key ] = String( value ); },
		removeItem: ( key ) => { delete data[ key ]; },
	};
}

function run( { referrer = '', hash = '', local = {}, session = {} } = {} ) {
	const navigations = [];
	const fetches = [];
	const localStorage = store( local );
	const sessionStorage = store( session );
	const location = { hash, href: 'https://links.example/band' + hash, pathname: '/band', search: '', assign: ( url ) => navigations.push( url ) };
	const window = { localStorage, sessionStorage, location, history: { replaceState() {} } };
	const document = {
		readyState: 'complete',
		referrer,
		body: { dataset: { extrchPermissionsApiUrl: 'https://artist.example.com/wp-json/perm', extrchTokenHandoffUrl: HANDOFF }, appendChild() {} },
		querySelector: () => null,
		createElement: () => ( { setAttribute() {} } ),
	};
	const fetch = ( url, options ) => { fetches.push( { url, options } ); return new Promise( () => {} ); };
	vm.runInNewContext( source, { window, document, fetch, URL, URLSearchParams, Date, JSON } );
	return { navigations, fetches, localStorage, sessionStorage };
}

// Anonymous visitor from anywhere off-network: one page load, no redirect.
for ( const referrer of [ '', 'https://www.instagram.com/', 'https://l.facebook.com/', 'https://links.example/other' ] ) {
	const r = run( { referrer } );
	assert.equal( r.navigations.length, 0, 'no handoff redirect for referrer ' + JSON.stringify( referrer ) );
	assert.equal( r.sessionStorage.getItem( 'ecLinkEditHandoffAttempted' ), null );
}

// Lookalike domains are not the host network.
assert.equal( run( { referrer: 'https://evilexample.com/' } ).navigations.length, 0 );
assert.equal( run( { referrer: 'https://example.com.evil.net/' } ).navigations.length, 0 );

// Arriving from the host site or its network: handoff once per session.
for ( const referrer of [ 'https://artist.example.com/manage/', 'https://example.com/', 'https://community.example.com/x' ] ) {
	const r = run( { referrer } );
	assert.equal( r.navigations.length, 1, 'handoff for ' + referrer );
	assert.ok( r.navigations[ 0 ].startsWith( HANDOFF + '&return=' ) );
	assert.equal( run( { referrer, session: { ecLinkEditHandoffAttempted: '1' } } ).navigations.length, 0, 'once per session' );
}

// Token returned by the handoff: stored, browser remembered, permission checked, no redirect.
const back = run( { hash: '#ec_link_token=abc' } );
assert.equal( back.navigations.length, 0 );
assert.equal( back.localStorage.getItem( 'ecLinkEditKnownEditor' ), '1' );
assert.equal( back.fetches[ 0 ].options.headers.Authorization, 'Bearer abc' );

// Tokenless return (not signed in): no loop, not remembered as an editor.
const none = run( { hash: '#ec_link_token_none=1', referrer: 'https://artist.example.com/' } );
assert.equal( none.navigations.length, 0 );
assert.equal( none.localStorage.getItem( 'ecLinkEditKnownEditor' ), null );

// Known editor whose token expired: handoff again, from any referrer.
assert.equal( run( { referrer: 'https://www.instagram.com/', local: { ecLinkEditKnownEditor: '1' } } ).navigations.length, 1 );

// Valid cached token: no redirect, straight to the permission check.
const cached = run( { local: { ecLinkEditToken: JSON.stringify( { token: 't', expiresAt: Date.now() + 600000 } ) } } );
assert.equal( cached.navigations.length, 0 );
assert.equal( cached.fetches.length, 1 );

console.log( 'edit-button handoff tests passed' );
