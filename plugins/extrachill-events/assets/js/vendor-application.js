( () => {
	const root = document.querySelector( '[data-vendor-application]' );
	const form = root?.querySelector( '[data-vendor-application-form]' );
	const status = root?.querySelector( '[data-vendor-application-status]' );
	if ( ! root || ! form || ! status ) {
		return;
	}
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const button = form.querySelector( 'button[type="submit"]' );
		const data = Object.fromEntries( new FormData( form ).entries() );
		data.contact_consent = data.contact_consent === '1';
		data.event_id = Number( data.event_id );
		data.turnstile_response = data[ 'cf-turnstile-response' ] || '';
		delete data[ 'cf-turnstile-response' ];
		button.disabled = true;
		status.hidden = false;
		status.textContent = 'Submitting your application…';
		try {
			const response = await fetch( root.dataset.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( data ),
			} );
			const payload = await response.json();
			if ( ! response.ok ) {
				throw new Error(
					payload.message || 'The application could not be submitted.'
				);
			}
			form.hidden = true;
			status.textContent = `Application received. Save this private withdrawal receipt: ${
				payload.public_id || ''
			}:${ payload.access_token || '' }`;
		} catch ( error ) {
			status.textContent = error.message;
			button.disabled = false;
		}
	} );
} )();
