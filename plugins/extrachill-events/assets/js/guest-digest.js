/**
 * Logged-out weekly digest signup (#989).
 *
 * Posts email + city + Turnstile token to the extrachill-api guest-subscribe
 * route. The response is deliberately opaque ({ status: 'check_inbox' }),
 * so success always just says to check the inbox.
 */
( function () {
	const config = window.ecGuestDigest || {};

	function utmFromLocation() {
		const utm = {};
		new URLSearchParams( window.location.search ).forEach(
			function ( value, key ) {
				if ( 0 === key.indexOf( 'utm_' ) ) {
					utm[ key ] = value;
				}
			}
		);
		return utm;
	}

	function init( form ) {
		const text = config.text || {};
		const status = form.querySelector( '.ec-digest-guest__status' );
		const button = form.querySelector( 'button[type="submit"]' );
		const emailInput = form.querySelector( 'input[name="email"]' );

		function showStatus( kind, message ) {
			if ( ! status ) {
				return;
			}
			status.classList.remove( 'notice-success', 'notice-error' );
			if ( kind ) {
				status.classList.add( 'notice-' + kind );
			}
			status.textContent = message || '';
			status.hidden = ! message;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			const email = emailInput ? emailInput.value.trim() : '';
			if ( ! email || ( emailInput && ! emailInput.checkValidity() ) ) {
				showStatus( 'error', text.error );
				return;
			}
			if ( ! window.wp || ! window.wp.apiFetch || ! config.path ) {
				showStatus( 'error', text.error );
				return;
			}

			const tokenField = form.querySelector(
				'input[name="cf-turnstile-response"]'
			);
			const data = {
				location_slug: form.dataset.locationSlug || '',
				email,
				turnstile_response: tokenField ? tokenField.value : '',
			};
			if ( document.referrer ) {
				data.referrer = document.referrer;
			}
			const utm = utmFromLocation();
			if ( Object.keys( utm ).length ) {
				data.utm = utm;
			}

			if ( button ) {
				button.disabled = true;
			}
			showStatus( '', text.sending );

			window.wp
				.apiFetch( { path: config.path, method: 'POST', data } )
				.then( function () {
					if ( emailInput ) {
						emailInput.disabled = true;
					}
					if ( button ) {
						button.hidden = true;
					}
					showStatus( 'success', text.success );
				} )
				.catch( function ( error ) {
					if ( button ) {
						button.disabled = false;
					}
					if ( window.turnstile && window.turnstile.reset ) {
						window.turnstile.reset();
					}
					showStatus(
						'error',
						( error && error.message ) || text.error
					);
				} );
		} );
	}

	document.querySelectorAll( '[data-ec-digest-guest]' ).forEach( init );
} )();
