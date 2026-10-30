/** Local Scene digest progressive subscription control. */
/* global beforeEach, describe, expect, it, jest */

async function flushPromises() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'Local Scene digest subscription', () => {
	beforeEach( () => {
		jest.resetModules();
		document.body.innerHTML = `
			<div data-local-scene-digest-control>
				<span data-local-scene-digest-status></span>
				<button disabled aria-pressed="false" data-local-scene-digest
					data-endpoint="https://example.com/wp-json/wp-abilities/v1/abilities/"
					data-nonce="nonce" data-slug="austin"></button>
			</div>`;
		global.fetch = jest.fn();
	} );

	it( 'checks status without subscribing on load', async () => {
		fetch.mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { subscribed: false } ),
		} );
		require( './local-scene-digest' );
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
			document.querySelector( '[data-local-scene-digest-status]' )
				.textContent
		).toBe( 'Weekly email and in-app updates are off.' );
	} );

	it( 'subscribes only after an explicit click', async () => {
		fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: false } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: () => Promise.resolve( { subscribed: true } ),
			} );
		require( './local-scene-digest' );
		await flushPromises();
		document.querySelector( 'button' ).click();
		await flushPromises();

		expect( fetch.mock.calls[ 1 ][ 0 ] ).toContain(
			'entity-subscribe/run'
		);
		expect( fetch.mock.calls[ 1 ][ 1 ].method ).toBe( 'POST' );
		expect( JSON.parse( fetch.mock.calls[ 1 ][ 1 ].body ).input ).toEqual( {
			entity_type: 'local_scene_digest',
			taxonomy: 'location',
			slug: 'austin',
		} );
		expect( document.querySelector( 'button' ).textContent ).toBe(
			'Subscribed to email + updates'
		);
		expect(
			document.querySelector( '[data-local-scene-digest-status]' )
				.textContent
		).toBe( 'Weekly email and in-app updates are on.' );
	} );
} );
