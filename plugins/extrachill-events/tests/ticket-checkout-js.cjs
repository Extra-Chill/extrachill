// Run: node tests/ticket-checkout-js.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require( 'node:path' ).join( __dirname, '../assets/js/ticket-checkout.js' ), 'utf8' );
async function run( ok, options = {} ) {
	let submit, destination, reset = false;
	const requests = [];
	const button = { disabled: false };
	const error = { hidden: true };
	const widget = {};
	const form = {
		dataset: { event: '42' },
		addEventListener: ( name, callback ) => { submit = callback; },
		querySelector: ( selector ) => selector.includes( 'button' ) ? button : selector === '.cf-turnstile' ? widget : error,
	};
	const fields = { quantity: '2', name: 'Buyer', email: 'buyer@example.com', 'cf-turnstile-response': 'token', terms_accepted: options.terms === false ? null : '1', ...( options.fields || {} ) };
	const responses = options.responses || [ ok ? { ok: true, status: 200, body: { checkout_url: 'https://checkout.stripe.com/example' } } : { ok: false, status: 400, body: { message: 'Sold out' } } ];
	vm.runInNewContext( source, {
		document: { querySelectorAll: () => [ form ] },
		FormData: class { get( key ) { return fields[ key ]; } },
		ecTicketCheckout: { url: 'https://shop.example/wp-json/extrachill/v1/events/tickets/checkout', error: 'Try again', terms: 'Agree first', nonce: options.nonce || '' },
		URL,
		fetch: async ( url, init ) => {
			requests.push( { url, options: init } );
			const next = responses[ Math.min( requests.length - 1, responses.length - 1 ) ];
			const response = { ok: next.ok, status: next.status, json: async () => next.body };
			response.clone = () => response;
			return response;
		},
		window: { location: { assign: ( url ) => { destination = url; } }, turnstile: { reset: ( target ) => { assert.equal( target, widget ); reset = true; } } },
	} );
	await submit( { preventDefault() {} } );
	return { requests, destination, button, error, reset };
}
( async () => {
	// Guest checkout: terms sent, no cookies.
	let result = await run( true );
	assert.equal( result.requests.length, 1 );
	assert.equal( result.requests[ 0 ].options.method, 'POST' );
	assert.equal( result.requests[ 0 ].options.credentials, 'omit' );
	assert.equal( result.requests[ 0 ].options.headers[ 'X-WP-Nonce' ], undefined );
	assert.deepEqual( JSON.parse( result.requests[ 0 ].options.body ), { event: 42, quantity: 2, name: 'Buyer', email: 'buyer@example.com', terms_accepted: true, venue_show_updates: false, turnstile_response: 'token' } );
	assert.equal( result.destination, 'https://checkout.stripe.com/example' );
	assert.equal( result.button.disabled, true );

	// A chosen ticket type and code ride along (extrachill#1716).
	result = await run( true, { fields: { ticket_type: 'vip', code: 'FRIENDS' } } );
	const sent = JSON.parse( result.requests[ 0 ].options.body );
	assert.equal( sent.ticket_type, 'vip' );
	assert.equal( sent.code, 'FRIENDS' );

	// Failure surfaces the server message and resets Turnstile.
	result = await run( false );
	assert.equal( result.destination, undefined );
	assert.equal( result.error.textContent, 'Sold out' );
	assert.equal( result.error.hidden, false );
	assert.equal( result.button.disabled, false );
	assert.equal( result.reset, true );

	// Unticked Terms never reach Shop.
	result = await run( true, { terms: false } );
	assert.equal( result.requests.length, 0 );
	assert.equal( result.error.textContent, 'Agree first' );
	assert.equal( result.error.hidden, false );

	// Logged-in buyers send their cookie and REST nonce so Shop records the account.
	result = await run( true, { nonce: 'n0nce' } );
	assert.equal( result.requests.length, 1 );
	assert.equal( result.requests[ 0 ].options.credentials, 'include' );
	assert.equal( result.requests[ 0 ].options.headers[ 'X-WP-Nonce' ], 'n0nce' );
	assert.equal( result.destination, 'https://checkout.stripe.com/example' );

	// A stale nonce falls back to a guest checkout instead of blocking the sale.
	result = await run( true, { nonce: 'stale', responses: [
		{ ok: false, status: 403, body: { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' } },
		{ ok: true, status: 200, body: { checkout_url: 'https://checkout.stripe.com/example' } },
	] } );
	assert.equal( result.requests.length, 2 );
	assert.equal( result.requests[ 1 ].options.credentials, 'omit' );
	assert.equal( result.destination, 'https://checkout.stripe.com/example' );
	console.log( 'Ticket checkout JavaScript passed.' );
} )().catch( ( error ) => { console.error( error ); process.exitCode = 1; } );
