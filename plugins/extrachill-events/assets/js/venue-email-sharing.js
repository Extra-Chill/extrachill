( function () {
	'use strict';

	const button = document.querySelector( '[data-venue-email-sharing]' );
	if ( ! button ) {
		return;
	}

	const status = button
		.closest( '[data-venue-email-sharing-control]' )
		.querySelector( '[data-venue-email-sharing-status]' );
	const input = {
		entity_type: 'venue-email-sharing',
		taxonomy: 'venue',
		slug: button.dataset.slug,
	};

	function setState( subscribed, message ) {
		button.setAttribute( 'aria-pressed', subscribed ? 'true' : 'false' );
		button.textContent = subscribed ? 'Stop sharing' : 'Share email';
		status.textContent =
			message ||
			( subscribed
				? 'Shared with this venue'
				: 'Not shared with this venue' );
	}

	function request( ability, method ) {
		let url = button.dataset.endpoint + 'extrachill/' + ability + '/run';
		const options = {
			method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': button.dataset.nonce },
		};
		if ( 'GET' === method ) {
			const query = new URLSearchParams();
			Object.keys( input ).forEach( ( key ) =>
				query.set( `input[${ key }]`, input[ key ] )
			);
			url += `?${ query.toString() }`;
		} else {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( { input } );
		}
		return window.fetch( url, options ).then( ( response ) =>
			response.json().then( ( data ) => {
				if ( ! response.ok ) {
					throw new Error( data.message || 'Request failed.' );
				}
				return data;
			} )
		);
	}

	request( 'entity-subscription-status', 'GET' )
		.then( ( data ) => {
			setState( Boolean( data.subscribed ) );
			button.disabled = false;
		} )
		.catch( () => {
			status.textContent =
				"Couldn't load this setting. Refresh to try again.";
		} );

	button.addEventListener( 'click', () => {
		const subscribed = 'true' === button.getAttribute( 'aria-pressed' );
		button.disabled = true;
		request(
			subscribed ? 'entity-unsubscribe' : 'entity-subscribe',
			'POST'
		)
			.then( ( data ) => setState( Boolean( data.subscribed ) ) )
			.catch( () => {
				status.textContent =
					'Unable to update email sharing. Please try again.';
			} )
			.finally( () => {
				button.disabled = false;
			} );
	} );
} )();
