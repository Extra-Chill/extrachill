/* global ecTicketCheckout */
( function () {
	'use strict';
	document.querySelectorAll( '[data-ticket-checkout]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			const button = form.querySelector( 'button[type="submit"]' );
			if ( button.disabled ) {
				return;
			}
			const error = form.querySelector( '[data-ticket-error]' );
			const fields = new FormData( form );
			button.disabled = true;
			error.hidden = true;
			try {
				const response = await fetch( ecTicketCheckout.url, {
					method: 'POST',
					credentials: 'omit',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						event: Number( form.dataset.event ),
						quantity: Number( fields.get( 'quantity' ) ),
						name: fields.get( 'name' ),
						email: fields.get( 'email' ),
						turnstile_response: fields.get( 'cf-turnstile-response' ) || '',
					} ),
				} );
				const result = await response.json();
				if ( ! response.ok ) {
					throw new Error( result.message || ecTicketCheckout.error );
				}
				const destination = new URL( result.checkout_url );
				if ( destination.protocol !== 'https:' ) {
					throw new Error( ecTicketCheckout.error );
				}
				window.location.assign( destination.href );
			} catch ( failure ) {
				error.textContent = failure.message || ecTicketCheckout.error;
				error.hidden = false;
				if ( window.turnstile && window.turnstile.reset ) {
					const widget = form.querySelector( '.cf-turnstile' );
					if ( widget ) {
						window.turnstile.reset( widget );
					}
				}
				button.disabled = false;
			}
		} );
	} );
}() );
