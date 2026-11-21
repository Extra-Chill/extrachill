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
			if ( ! fields.get( 'terms_accepted' ) ) {
				error.textContent = ecTicketCheckout.terms || ecTicketCheckout.error;
				error.hidden = false;
				return;
			}
			button.disabled = true;
			error.hidden = true;
			const purchase = {
				event: Number( form.dataset.event ),
				quantity: Number( fields.get( 'quantity' ) ),
				name: fields.get( 'name' ),
				email: fields.get( 'email' ),
				terms_accepted: true,
				venue_show_updates: !! fields.get( 'venue_show_updates' ),
				turnstile_response: fields.get( 'cf-turnstile-response' ) || '',
			};
			// Ticket type and code are optional: single-type shows send the one type.
			[ 'ticket_type', 'code' ].forEach( function ( key ) {
				if ( fields.get( key ) ) {
					purchase[ key ] = fields.get( key );
				}
			} );
			const body = JSON.stringify( purchase );
			// Logged-in buyers send their account cookie and REST nonce so the
			// order lands in My Account > Tickets. A stale nonce falls back to a
			// guest checkout rather than blocking the purchase.
			const send = ( signedIn ) =>
				fetch( ecTicketCheckout.url, {
					method: 'POST',
					credentials: signedIn ? 'include' : 'omit',
					headers: signedIn
						? { 'Content-Type': 'application/json', 'X-WP-Nonce': ecTicketCheckout.nonce }
						: { 'Content-Type': 'application/json' },
					body,
				} );
			try {
				let response = await send( !! ecTicketCheckout.nonce );
				if ( ecTicketCheckout.nonce && response.status === 403 ) {
					const failure = await response.clone().json().catch( () => ( {} ) );
					if ( failure.code === 'rest_cookie_invalid_nonce' ) {
						response = await send( false );
					}
				}
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
