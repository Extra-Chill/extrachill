( function () {
	'use strict';

	const button = document.querySelector( '[data-venue-update-subscription]' );
	if ( ! button ) {
		return;
	}

	const status = button
		.closest( '[data-venue-update-control]' )
		.querySelector( '[data-venue-update-status]' );
	const input = {
		entity_type: 'venue',
		taxonomy: 'venue',
		slug: button.dataset.slug,
	};

	function setState( subscribed, message ) {
		button.setAttribute( 'aria-pressed', subscribed ? 'true' : 'false' );
		button.textContent = subscribed
			? 'Subscribed to updates'
			: 'Subscribe to updates';
		status.textContent =
			message ||
			( subscribed ? 'Venue updates are on.' : 'Venue updates are off.' );
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
		.then( ( data ) => setState( Boolean( data.subscribed ) ) )
		.catch( () => {
			status.textContent =
				'Subscription status is unavailable. Please try again.';
		} )
		.finally( () => {
			button.disabled = false;
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
					'Unable to update your subscription. Please try again.';
			} )
			.finally( () => {
				button.disabled = false;
			} );
	} );
} )();
