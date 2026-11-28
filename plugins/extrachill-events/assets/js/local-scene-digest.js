/**
 * Progressive entity-subscription toggle.
 *
 * Drives the city Local Scene digest control and, through data attributes,
 * the venue show-updates control (extrachill#1724): `data-entity-type`,
 * `data-taxonomy`, `data-label-on|off` and `data-status-on|off` override the
 * city defaults.
 */
( function () {
	'use strict';

	function initToggle( button ) {
		const status = button
			.closest( '[data-local-scene-digest-control]' )
			.querySelector( '[data-local-scene-digest-status]' );
		const input = {
			entity_type: button.dataset.entityType || 'local_scene_digest',
			taxonomy: button.dataset.taxonomy || 'location',
			slug: button.dataset.slug,
		};

		function setState( subscribed, message ) {
			button.setAttribute(
				'aria-pressed',
				subscribed ? 'true' : 'false'
			);
			button.textContent = subscribed
				? button.dataset.labelOn || 'Subscribed to email + updates'
				: button.dataset.labelOff || 'Subscribe to email + updates';
			status.textContent =
				message ||
				( subscribed
					? button.dataset.statusOn ||
						'Weekly email and in-app updates are on.'
					: button.dataset.statusOff ||
						'Weekly email and in-app updates are off.' );
		}

		function request( ability, method ) {
			let url =
				button.dataset.endpoint + 'extrachill/' + ability + '/run';
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
	}

	document
		.querySelectorAll( '[data-local-scene-digest]' )
		.forEach( initToggle );
} )();
