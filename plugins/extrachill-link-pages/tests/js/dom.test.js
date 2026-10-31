const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const assert = require( 'node:assert/strict' );

function classes( initial = [] ) {
	const values = new Set( initial );
	return {
		add: ( value ) => values.add( value ),
		remove: ( value ) => values.delete( value ),
		contains: ( value ) => values.has( value ),
	};
}

async function testSharing() {
	const listeners = {};
	const element = ( initialClasses = [] ) => ( {
		classList: classes( initialClasses ),
		dataset: {},
		style: {},
		addEventListener( type, callback ) { listeners[ this.name + ':' + type ] = callback; },
		querySelector() { return null; },
	} );
	const bell = element( [ 'extrch-share-trigger', 'extrch-bell-page-trigger' ] ); bell.name = 'bell';
	const page = element( [ 'extrch-share-trigger', 'extrch-share-page-trigger' ] ); page.name = 'page'; page.dataset = { shareUrl: 'https://example.com/page', shareTitle: 'Page' };
	const native = element(); native.name = 'native';
	const icon = { className: 'fas fa-copy' };
	const label = { textContent: 'Copy Link' };
	const copy = element(); copy.name = 'copy'; copy.querySelector = ( selector ) => selector.includes( 'icon' ) ? icon : label;
	const modal = element( [ 'extrch-modal-hidden' ] ); modal.name = 'modal';
	const named = { '.extrch-share-option-native': native, '.extrch-share-option-copy-link': copy };
	modal.querySelector = ( selector ) => named[ selector ] || null;
	modal.querySelectorAll = () => [];
	let nativeShares = 0;
	let clipboardWrites = 0;
	const document = {
		body: { dataset: { extrchTrackingClickUrl: 'https://api.example/click', extrchLinkPageId: '40' }, style: {} },
		addEventListener( type, callback ) { if ( 'DOMContentLoaded' === type ) { callback(); } },
		getElementById: () => modal,
		querySelectorAll: ( selector ) => '.extrch-share-trigger' === selector ? [ bell, page ] : [],
		querySelector: () => null,
	};
	const context = {
		document,
		navigator: {
			share: async () => { ++nativeShares; },
			clipboard: { writeText: async () => { ++clipboardWrites; } },
			sendBeacon: () => true,
		},
		window: { location: { href: 'https://example.com/page' } },
		URL,
		Blob,
		setTimeout: ( callback ) => callback(),
		fetch: async () => ( {} ),
		console,
	};
	vm.runInNewContext( fs.readFileSync( 'assets/js/extrch-share-modal.js', 'utf8' ), context );
	assert.equal( listeners['bell:click'], undefined, 'Subscription bell must not open share modal.' );
	assert.equal( typeof listeners['page:click'], 'function' );
	listeners['page:click']( { preventDefault() {} } );
	assert.equal( typeof listeners['native:click'], 'function' );
	await listeners['native:click']();
	assert.equal( nativeShares, 1 );
	await listeners['copy:click']();
	assert.equal( clipboardWrites, 1 );
}

function testYoutubeToggle() {
	let handler;
	let prevented = 0;
	let inserted;
	const parent = {
		nextElementSibling: null,
		parentNode: {
			insertBefore( node ) { inserted = node; parent.nextElementSibling = node; node.parentNode = this; },
			removeChild( node ) { if ( inserted === node ) { inserted = null; parent.nextElementSibling = null; } },
		},
	};
	const link = { href: 'https://youtu.be/abcdefghijk', closest: ( selector ) => selector.includes( 'links' ) ? {} : parent };
	const body = { addEventListener( type, callback ) { if ( 'click' === type ) { handler = callback; } } };
	const document = {
		body,
		readyState: 'complete',
		addEventListener() {},
		querySelectorAll: () => [],
		createElement: () => {
			const classList = classes();
			const node = { classList, innerHTML: '', offsetHeight: 1, parentNode: null };
			Object.defineProperty( node, 'className', { set: ( value ) => value.split( /\s+/ ).filter( Boolean ).forEach( ( name ) => classList.add( name ) ) } );
			return node;
		},
	};
	const event = {
		target: { closest: ( selector ) => selector.startsWith( 'a.' ) ? link : null },
		preventDefault: () => { ++prevented; },
	};
	vm.runInNewContext( fs.readFileSync( 'assets/js/link-page-youtube-embed.js', 'utf8' ), { document, window: {}, setTimeout: ( callback ) => callback(), console } );
	handler( event );
	assert.equal( prevented, 1 );
	assert.match( inserted.innerHTML, /youtube\.com\/embed\/abcdefghijk/ );
	assert.equal( inserted.classList.contains( 'video-visible' ), true );
	handler( event );
	assert.equal( inserted, null, 'Second click must close and remove the active embed.' );
}

( async () => {
	await testSharing();
	testYoutubeToggle();
	console.log( 'Standalone public DOM behavior passes.' );
} )().catch( ( error ) => { console.error( error ); process.exitCode = 1; } );
