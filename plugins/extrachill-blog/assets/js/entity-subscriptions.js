( function () {
	'use strict';

	const buttons = document.querySelectorAll( '[data-entity-subscription][data-endpoint]' );

	function getControl( button ) {
		return button.closest( '[data-entity-subscription-control]' );
	}

	function setState( button, subscribed, message ) {
		button.setAttribute( 'aria-pressed', subscribed ? 'true' : 'false' );
		button.textContent = subscribed ? button.dataset.onLabel : button.dataset.offLabel;
		getControl( button ).querySelector( '.entity-pillar-subscription-status' ).textContent = message || ( subscribed ? button.dataset.onStatus : button.dataset.offStatus );
	}

	function request( button, ability, method ) {
		const input = {
			entity_type: button.dataset.entityType,
			taxonomy: button.dataset.taxonomy,
			slug: button.dataset.slug,
		};
		let url = button.dataset.endpoint + 'extrachill/' + ability + '/run';
		const options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': button.dataset.nonce },
		};

		if ( 'GET' === method ) {
			const query = new URLSearchParams();
			Object.keys( input ).forEach( function ( key ) {
				query.set( 'input[' + key + ']', input[ key ] );
			} );
			url += '?' + query.toString();
		} else {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify( { input: input } );
		}

		return window.fetch( url, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data.message || 'Unable to update this subscription.' );
				}
				return data;
			} );
		} );
	}

	buttons.forEach( function ( button ) {
		request( button, 'entity-subscription-status', 'GET' ).then( function ( data ) {
			setState( button, Boolean( data.subscribed ) );
			button.disabled = false;
		} ).catch( function () {
			getControl( button ).querySelector( '.entity-pillar-subscription-status' ).textContent = "Couldn't load this setting. Refresh to try again.";
		} );

		button.addEventListener( 'click', function () {
			const subscribed = 'true' === button.getAttribute( 'aria-pressed' );
			button.disabled = true;
			request( button, subscribed ? 'entity-unsubscribe' : 'entity-subscribe', 'POST' ).then( function ( data ) {
				setState( button, Boolean( data.subscribed ) );
			} ).catch( function () {
				getControl( button ).querySelector( '.entity-pillar-subscription-status' ).textContent = 'Unable to update this setting. Please try again.';
			} ).finally( function () {
				button.disabled = false;
			} );
		} );
	} );
}() );
