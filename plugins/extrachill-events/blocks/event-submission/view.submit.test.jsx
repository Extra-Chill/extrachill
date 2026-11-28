/* global describe, afterEach, it, jest, expect */

const endpoint = '/wp-json/extrachill/v1/event-submissions';
const successMessage = 'Thanks! We received your submission.';
const missingContactMessage = 'Name and email are required.';
const originalFetch = global.fetch;

const submit = async ( { nonce, hasContactFields = false, ok = true } ) => {
	const contactFields = hasContactFields
		? `<input name="contact_name" value="Event Submitter" />
		   <input name="contact_email" value="submitter@example.test" />`
		: '';
	document.body.innerHTML = `
		<div class="ec-event-submission"
			data-endpoint="${ endpoint }"
			data-success-message="${ successMessage }"
			${ nonce ? `data-rest-nonce="${ nonce }"` : '' }>
			<form class="ec-event-submission__form">
				<input name="cf-turnstile-response" value="test-token" />
				${ contactFields }
				<div class="ec-event-submission__status"></div>
			</form>
		</div>`;
	global.fetch = jest.fn().mockResolvedValue( {
		ok,
		json: async () =>
			ok
				? {}
				: { code: 'missing_contact', message: missingContactMessage },
	} );
	jest.isolateModules( () => require( './view.js' ) );
	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	const form = document.querySelector( 'form' );
	form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	return form.querySelector( '.ec-event-submission__status' );
};

describe( 'manual event submission', () => {
	afterEach( () => {
		global.fetch = originalFetch;
		document.body.innerHTML = '';
	} );

	it( 'sends the REST nonce with same-origin credentials and multipart form data', async () => {
		const status = await submit( { nonce: 'test-nonce' } );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( global.fetch ).toHaveBeenCalledWith( endpoint, {
			method: 'POST',
			headers: { 'X-WP-Nonce': 'test-nonce' },
			credentials: 'same-origin',
			body: expect.any( FormData ),
		} );
		expect( status.textContent ).toBe( successMessage );
	} );

	it( 'submits anonymous contact details without a nonce', async () => {
		const status = await submit( { hasContactFields: true } );

		const [ , options ] = global.fetch.mock.calls[ 0 ];
		expect( options.headers ).not.toHaveProperty( 'X-WP-Nonce' );
		expect( options.body.get( 'contact_name' ) ).toBe( 'Event Submitter' );
		expect( options.body.get( 'contact_email' ) ).toBe(
			'submitter@example.test'
		);
		expect( status.textContent ).toBe( successMessage );
	} );

	it( 'asks signed-in submitters to refresh when the REST API requires contact details', async () => {
		const status = await submit( { nonce: 'test-nonce', ok: false } );

		expect( status.textContent ).toBe(
			'Your session expired. Refresh the page and try again.'
		);
		expect( status.classList.contains( 'is-error' ) ).toBe( true );
	} );

	it( 'preserves the missing-contact message when contact fields are present', async () => {
		const status = await submit( { hasContactFields: true, ok: false } );

		expect( status.textContent ).toBe( missingContactMessage );
	} );
} );
