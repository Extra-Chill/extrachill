require( '../../inc/link-pages/live/assets/js/link-page-subscribe.js' );

function mountForm( endpoint = '' ) {
	document.body.innerHTML = `
		<form class="extrch-subscribe-form" data-subscribe-api-url="${ endpoint }">
			<input type="email" value="fan@example.com">
			<button type="submit">Subscribe</button>
			<div class="extrch-form-message" role="status" aria-live="polite"></div>
		</form>
	`;

	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

	return document.querySelector( 'form' );
}

async function submit( form ) {
	form.dispatchEvent(
		new Event( 'submit', { bubbles: true, cancelable: true } )
	);
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'artist subscription form', () => {
	beforeEach( () => {
		document.body.removeAttribute( 'data-extrch-subscribe-api-url' );
		global.fetch = jest.fn();
	} );

	test( 'submits to the form endpoint and reports success', async () => {
		fetch.mockResolvedValue( {
			ok: true,
			json: () =>
				Promise.resolve( { message: 'Thank you for subscribing!' } ),
		} );
		const form = mountForm( 'https://artist.example/subscribe' );

		await submit( form );

		expect( fetch ).toHaveBeenCalledWith(
			'https://artist.example/subscribe',
			expect.objectContaining( {
				method: 'POST',
				body: JSON.stringify( { email: 'fan@example.com' } ),
			} )
		);
		expect( form.querySelector( '[role="status"]' ).textContent ).toBe(
			'Thank you for subscribing!'
		);
		expect( form.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );

	test( 'reports the existing duplicate-subscription response', async () => {
		fetch.mockResolvedValue( {
			ok: false,
			json: () =>
				Promise.resolve( {
					message: 'You are already subscribed to this artist.',
				} ),
		} );
		const form = mountForm( 'https://artist.example/subscribe' );

		await submit( form );

		expect( form.querySelector( '[role="status"]' ).textContent ).toBe(
			'You are already subscribed to this artist.'
		);
	} );

	test( 'fails accessibly without an endpoint and does not submit', async () => {
		const form = mountForm();

		await submit( form );

		expect( fetch ).not.toHaveBeenCalled();
		expect( form.querySelector( '[role="status"]' ).textContent ).toBe(
			'Subscription is temporarily unavailable.'
		);
		expect( form.querySelector( 'button' ).disabled ).toBe( false );
	} );

	test( 'prefers the per-form endpoint over the custom-domain body fallback', async () => {
		document.body.dataset.extrchSubscribeApiUrl =
			'https://custom.example/body-endpoint';
		fetch.mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { message: 'Subscribed' } ),
		} );
		const form = mountForm( 'https://artist.example/form-endpoint' );

		await submit( form );

		expect( fetch ).toHaveBeenCalledWith(
			'https://artist.example/form-endpoint',
			expect.any( Object )
		);
	} );
} );
