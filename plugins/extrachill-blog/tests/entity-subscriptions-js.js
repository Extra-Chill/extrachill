#!/usr/bin/env node

const assert = require( 'node:assert/strict' );
const script = require.resolve( '../assets/js/entity-subscriptions.js' );

function makeButton( dataset ) {
	const status = { textContent: 'Checking...' };
	const attributes = { 'aria-pressed': 'false' };
	const button = {
		dataset,
		disabled: true,
		textContent: dataset.offLabel,
		setAttribute: ( key, value ) => {
			attributes[ key ] = value;
		},
		getAttribute: ( key ) => attributes[ key ],
		closest: () => ( {
			querySelector: () => status,
		} ),
		addEventListener: ( event, callback ) => {
			button.listeners[ event ] = callback;
		},
		listeners: {},
		status,
	};

	return button;
}

function flushPromises() {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

async function loadScript( buttons, fetch ) {
	global.document = { querySelectorAll: () => buttons };
	global.fetch = fetch;
	global.window = { fetch };
	delete require.cache[ script ];
	require( script );
	await flushPromises();
}

( async () => {
	const shared = {
		taxonomy: 'artist',
		slug: 'susto',
		endpoint: 'https://example.test/wp-json/wp-abilities/v1/abilities/',
		nonce: 'nonce',
	};
	const notifications = makeButton( {
		...shared,
		entityType: 'artist',
		onLabel: 'Turn off',
		offLabel: 'Turn on',
		onStatus: 'On',
		offStatus: 'Off',
	} );
	const email = makeButton( {
		...shared,
		entityType: 'artist-email-sharing',
		onLabel: 'Stop sharing',
		offLabel: 'Share email',
		onStatus: 'Shared with this artist',
		offStatus: 'Not shared with this artist',
	} );
	let request = 0;

	await loadScript( [ notifications, email ], () => {
		const current = ++request;
		return Promise.resolve( {
			ok: true,
			json: () => Promise.resolve( { subscribed: 2 === current } ),
		} );
	} );

	assert.equal( notifications.textContent, 'Turn on' );
	assert.equal( notifications.status.textContent, 'Off' );
	assert.equal( notifications.disabled, false );
	assert.equal( email.textContent, 'Stop sharing' );
	assert.equal( email.status.textContent, 'Shared with this artist' );
	assert.equal( email.disabled, false );

	const unavailable = makeButton( {
		...shared,
		entityType: 'artist-email-sharing',
		onLabel: 'Stop sharing',
		offLabel: 'Share email',
		onStatus: 'Shared with this artist',
		offStatus: 'Not shared with this artist',
	} );
	await loadScript( [ unavailable ], () => Promise.reject( new Error( 'offline' ) ) );

	assert.equal( unavailable.disabled, true );
	assert.equal( unavailable.status.textContent, "Couldn't load this setting. Refresh to try again." );

	process.stdout.write( 'Entity subscription JavaScript tests passed.\n' );
} )().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
