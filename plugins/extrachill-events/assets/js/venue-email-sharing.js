( function () {
	'use strict';

	const button = document.querySelector( '[data-venue-email-sharing]' );
	if ( ! button ) {
		return;
	}

	const input = {
		entity_type: 'venue-email-sharing',
		taxonomy: 'venue',
		slug: button.dataset.slug,
	};

	function setState( subscribed ) {
		button.setAttribute( 'aria-pressed', subscribed ? 'true' : 'false' );
		button.classList.toggle( 'button-2', subscribed );
		button.classList.toggle( 'button-3', ! subscribed );
		button.textContent = subscribed ? 'Email shared' : 'Share email';
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
			button.textContent = 'Email sharing unavailable';
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
				button.textContent = 'Try email sharing again';
			} )
			.finally( () => {
				button.disabled = false;
			} );
	} );
} )();
