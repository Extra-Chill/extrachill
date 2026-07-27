( function () {
	'use strict';

	const button = document.querySelector( '[data-local-scene-digest]' );
	if ( ! button ) {
		return;
	}
	const status = button
		.closest( '[data-local-scene-digest-control]' )
		.querySelector( '[data-local-scene-digest-status]' );
	const input = {
		entity_type: 'local_scene_digest',
		taxonomy: 'location',
		slug: button.dataset.slug,
	};

	function setState( subscribed, message ) {
		button.setAttribute( 'aria-pressed', subscribed ? 'true' : 'false' );
		button.textContent = subscribed
			? 'Subscribed to email + updates'
			: 'Subscribe to email + updates';
		status.textContent =
			message ||
			( subscribed
				? 'Weekly email and in-app updates are on.'
				: 'Weekly email and in-app updates are off.' );
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
