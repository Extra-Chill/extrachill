/** Explicit venue update subscription control. */
/* global beforeEach, describe, expect, it, jest */

async function flushPromises() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'Venue update subscriptions', () => {
	beforeEach( () => {
		jest.resetModules();
		document.body.innerHTML = `
			<div data-venue-update-control>
				<span data-venue-update-status></span>
				<button disabled aria-pressed="false" data-venue-update-subscription
					data-endpoint="https://example.com/wp-json/wp-abilities/v1/abilities/"
					data-nonce="nonce" data-slug="the-royal-american"></button>
			</div>`;
		global.fetch = jest.fn();
	} );

	it( 'loads status without mutating consent', async () => {
		fetch.mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { subscribed: false } ),
		} );
		require( './venue-update-subscriptions' );
		await flushPromises();

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		expect( fetch.mock.calls[ 0 ][ 1 ].method ).toBe( 'GET' );
		expect( fetch.mock.calls[ 0 ][ 0 ] ).toContain(
			'entity-subscription-status'
		);
		expect( fetch.mock.calls[ 0 ][ 0 ] ).not.toContain(
			'entity-subscribe/run'
		);
		expect(
			document.querySelector( '[data-venue-update-status]' ).textContent
		).toBe( 'Off' );
		expect( document.querySelector( 'button' ).disabled ).toBe( false );
	} );

	it( 'subscribes with the exact venue identity after a click', async () => {
		fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: false } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: true } ),
			} );
		require( './venue-update-subscriptions' );
		await flushPromises();
		document.querySelector( 'button' ).click();
		await flushPromises();

		expect( fetch.mock.calls[ 1 ][ 0 ] ).toContain(
			'entity-subscribe/run'
		);
		expect( JSON.parse( fetch.mock.calls[ 1 ][ 1 ].body ).input ).toEqual( {
			entity_type: 'venue',
			taxonomy: 'venue',
			slug: 'the-royal-american',
		} );
		expect( document.querySelector( 'button' ).textContent ).toBe(
			'Turn off'
		);
		expect(
			document.querySelector( '[data-venue-update-status]' ).textContent
		).toBe( 'On' );
	} );

	it( 'unsubscribes only after an explicit second click', async () => {
		fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: true } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: false } ),
			} );
		require( './venue-update-subscriptions' );
		await flushPromises();
		document.querySelector( 'button' ).click();
		await flushPromises();

		expect( fetch.mock.calls[ 1 ][ 0 ] ).toContain(
			'entity-unsubscribe/run'
		);
		expect( document.querySelector( 'button' ).textContent ).toBe(
			'Turn on'
		);
	} );

	it( 'keeps mutation disabled when status cannot be loaded', async () => {
		fetch.mockRejectedValue( new Error( 'offline' ) );
		require( './venue-update-subscriptions' );
		await flushPromises();

		expect( document.querySelector( 'button' ).disabled ).toBe( true );
		expect(
			document.querySelector( '[data-venue-update-status]' ).textContent
		).toBe( "Couldn't load this setting. Refresh to try again." );
	} );
} );
