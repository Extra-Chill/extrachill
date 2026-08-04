/** Explicit venue update subscription control. */
/* global beforeEach, describe, expect, it, jest */

async function flushPromises() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'Venue update subscriptions', () => {
	beforeEach( () => {
		jest.resetModules();
		document.body.innerHTML = `
			<button class="button-3" disabled aria-pressed="false" data-venue-update-subscription
				data-endpoint="https://example.com/wp-json/wp-abilities/v1/abilities/"
				data-nonce="nonce" data-slug="the-royal-american"></button>`;
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
		const button = document.querySelector( 'button' );
		expect( button.textContent ).toBe( 'Get event alerts' );
		expect( button.classList.contains( 'button-3' ) ).toBe( true );
		expect( button.disabled ).toBe( false );
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
			'Event alerts on'
		);
		expect(
			document.querySelector( 'button' ).classList.contains( 'button-2' )
		).toBe( true );
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
			'Get event alerts'
		);
	} );

	it( 'keeps mutation disabled when status cannot be loaded', async () => {
		fetch.mockRejectedValue( new Error( 'offline' ) );
		require( './venue-update-subscriptions' );
		await flushPromises();

		expect( document.querySelector( 'button' ).disabled ).toBe( true );
		expect( document.querySelector( 'button' ).textContent ).toBe(
			'Event alerts unavailable'
		);
	} );
} );
