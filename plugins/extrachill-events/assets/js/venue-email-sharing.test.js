/** Scoped venue email-sharing control. */
/* global beforeEach, describe, expect, it, jest */

async function flushPromises() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'Venue email sharing', () => {
	beforeEach( () => {
		jest.resetModules();
		document.body.innerHTML = `
			<div data-venue-email-sharing-control>
				<span data-venue-email-sharing-status></span>
				<button disabled aria-pressed="false" data-venue-email-sharing
					data-endpoint="https://example.com/wp-json/wp-abilities/v1/abilities/"
					data-nonce="nonce" data-slug="the-royal-american"></button>
			</div>`;
		global.fetch = jest.fn();
	} );

	it( 'loads status without granting consent', async () => {
		fetch.mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { subscribed: false } ),
		} );
		require( './venue-email-sharing' );
		await flushPromises();

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		expect( fetch.mock.calls[ 0 ][ 1 ].method ).toBe( 'GET' );
		expect( fetch.mock.calls[ 0 ][ 0 ] ).toContain(
			'entity-subscription-status'
		);
		expect(
			document.querySelector( '[data-venue-email-sharing-status]' )
				.textContent
		).toBe( 'Not shared with this venue' );
		expect( document.querySelector( 'button' ).disabled ).toBe( false );
	} );

	it( 'uses only the scoped venue email-sharing identity', async () => {
		fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: false } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: true } ),
			} );
		require( './venue-email-sharing' );
		await flushPromises();
		document.querySelector( 'button' ).click();
		await flushPromises();

		expect( JSON.parse( fetch.mock.calls[ 1 ][ 1 ].body ).input ).toEqual( {
			entity_type: 'venue-email-sharing',
			taxonomy: 'venue',
			slug: 'the-royal-american',
		} );
		expect( fetch.mock.calls[ 1 ][ 1 ].body ).not.toContain(
			'"entity_type":"venue"'
		);
		expect( document.querySelector( 'button' ).textContent ).toBe(
			'Stop sharing'
		);
	} );

	it( 'revokes only after an explicit second click', async () => {
		fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: true } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: false } ),
			} );
		require( './venue-email-sharing' );
		await flushPromises();
		document.querySelector( 'button' ).click();
		await flushPromises();

		expect( fetch.mock.calls[ 1 ][ 0 ] ).toContain(
			'entity-unsubscribe/run'
		);
		expect( document.querySelector( 'button' ).textContent ).toBe(
			'Share email'
		);
	} );

	it( 'keeps mutation disabled when status cannot be loaded', async () => {
		fetch.mockRejectedValue( new Error( 'offline' ) );
		require( './venue-email-sharing' );
		await flushPromises();

		expect( document.querySelector( 'button' ).disabled ).toBe( true );
		expect(
			document.querySelector( '[data-venue-email-sharing-status]' )
				.textContent
		).toBe( "Couldn't load this setting. Refresh to try again." );
	} );
} );
