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
	let subscribed = false;
	const requests = [];

	await loadScript( [ notifications ], ( url, options ) => {
		requests.push( { url, options } );
		if ( url.includes( '/entity-subscribe/' ) ) {
			subscribed = true;
		} else if ( url.includes( '/entity-unsubscribe/' ) ) {
			subscribed = false;
		}
		return Promise.resolve( {
			ok: true,
			json: () => Promise.resolve( { subscribed } ),
		} );
	} );

	assert.equal( notifications.textContent, 'Turn on' );
	assert.equal( notifications.status.textContent, 'Off' );
	assert.equal( notifications.disabled, false );
	assert.equal( requests[0].options.method, 'GET' );
	assert.match( requests[0].url, /entity-subscription-status/ );

	notifications.listeners.click();
	await flushPromises();
	assert.equal( notifications.textContent, 'Turn off' );
	assert.equal( notifications.status.textContent, 'On' );
	assert.equal( notifications.disabled, false );
	assert.match( requests[1].url, /entity-subscribe/ );
	assert.equal( JSON.parse( requests[1].options.body ).input.entity_type, 'artist' );

	notifications.listeners.click();
	await flushPromises();
	assert.equal( notifications.textContent, 'Turn on' );
	assert.equal( notifications.status.textContent, 'Off' );
	assert.equal( notifications.disabled, false );
	assert.match( requests[2].url, /entity-unsubscribe/ );

	const unavailable = makeButton( {
		...notifications.dataset,
	} );
	await loadScript( [ unavailable ], () => Promise.reject( new Error( 'offline' ) ) );

	assert.equal( unavailable.disabled, true );
	assert.equal( unavailable.status.textContent, "Couldn't load this setting. Refresh to try again." );

	process.stdout.write( 'Entity subscription JavaScript tests passed.\n' );
} )().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
