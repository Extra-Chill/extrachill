// Run: node tests/ticket-checkout-js.cjs
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require( 'node:path' ).join( __dirname, '../assets/js/ticket-checkout.js' ), 'utf8' );
async function run( ok ) {
	let submit, request, destination, reset = false;
	const button = { disabled: false };
	const error = { hidden: true };
	const widget = {};
	const form = {
		dataset: { event: '42' },
		addEventListener: ( name, callback ) => { submit = callback; },
		querySelector: ( selector ) => selector.includes( 'button' ) ? button : selector === '.cf-turnstile' ? widget : error,
	};
	vm.runInNewContext( source, {
		document: { querySelectorAll: () => [ form ] },
		FormData: class { get( key ) { return { quantity: '2', name: 'Buyer', email: 'buyer@example.com', 'cf-turnstile-response': 'token' }[ key ]; } },
		ecTicketCheckout: { url: 'https://shop.example/wp-json/extrachill/v1/events/tickets/checkout', error: 'Try again' },
		URL,
		fetch: async ( url, options ) => {
			request = { url, options };
			return { ok, json: async () => ok ? { checkout_url: 'https://checkout.stripe.com/example' } : { message: 'Sold out' } };
		},
		window: { location: { assign: ( url ) => { destination = url; } }, turnstile: { reset: ( target ) => { assert.equal( target, widget ); reset = true; } } },
	} );
	await submit( { preventDefault() {} } );
	assert.equal( request.options.method, 'POST' );
	assert.deepEqual( JSON.parse( request.options.body ), { event: 42, quantity: 2, name: 'Buyer', email: 'buyer@example.com', turnstile_response: 'token' } );
	if ( ok ) {
		assert.equal( destination, 'https://checkout.stripe.com/example' );
		assert.equal( button.disabled, true );
	} else {
		assert.equal( destination, undefined );
		assert.equal( error.textContent, 'Sold out' );
		assert.equal( error.hidden, false );
		assert.equal( button.disabled, false );
		assert.equal( reset, true );
	}
}
( async () => {
	await run( true );
	await run( false );
	console.log( 'Ticket checkout JavaScript passed.' );
} )().catch( ( error ) => { console.error( error ); process.exitCode = 1; } );
