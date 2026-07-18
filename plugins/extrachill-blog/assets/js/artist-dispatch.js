( function () {
	'use strict';

	if ( ! window.wp || ! window.wp.apiFetch || ! window.ecArtistDispatch ) {
		return;
	}

	const apiFetch = window.wp.apiFetch;
	apiFetch.use( apiFetch.createNonceMiddleware( window.ecArtistDispatch.nonce ) );

	const requestForm = document.getElementById( 'artist-dispatch-request' );
	if ( requestForm ) {
		requestForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const button = requestForm.querySelector( 'button[type="submit"]' );
			const message = requestForm.querySelector( '.artist-dispatch-message' );
			const data = new window.FormData( requestForm );
			button.disabled = true;
			message.textContent = 'Sending your request…';

			try {
				const input = {
					artist_id: Number( data.get( 'artist_id' ) ),
					description: String( data.get( 'description' ) || '' ),
					acknowledgement: data.get( 'acknowledgement' ) === 'on',
					terms_version: window.ecArtistDispatch.termsVersion,
				};
				const sampleUrl = String( data.get( 'sample_url' ) || '' );
				if ( sampleUrl ) {
					input.sample_url = sampleUrl;
				}
				await apiFetch( {
					path: '/wp-abilities/v1/abilities/extrachill/request-artist-dispatch-access/run',
					method: 'POST',
					data: { input },
				} );
				message.textContent = 'Your request is in the editorial queue.';
				window.location.reload();
			} catch ( error ) {
				message.textContent = error && error.message ? error.message : 'The request could not be sent. Please try again.';
				button.disabled = false;
			}
		} );
	}

	const newButton = document.getElementById( 'artist-dispatch-new' );
	if ( newButton ) {
		let creationPromise = null;
		newButton.addEventListener( 'click', () => {
			if ( creationPromise ) {
				return creationPromise;
			}
			const message = document.getElementById( 'artist-dispatch-new-message' );
			newButton.disabled = true;
			message.textContent = 'Creating your private draft…';

			creationPromise = apiFetch( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: '',
					content: '',
					status: 'draft',
				},
				headers: { 'X-Extra-Chill-Artist-Dispatch': 'create' },
			} )
				.then( ( post ) => {
					if ( ! post || ! post.id ) {
						throw new Error( 'WordPress did not return a draft ID.' );
					}
					window.location.assign( `${ window.ecArtistDispatch.writeUrl }?post=${ Number( post.id ) }` );
				} )
				.catch( ( error ) => {
					message.textContent = error && error.message ? error.message : 'The draft could not be created. Please try again.';
					newButton.disabled = false;
					creationPromise = null;
				} );

			return creationPromise;
		} );
	}
}() );
