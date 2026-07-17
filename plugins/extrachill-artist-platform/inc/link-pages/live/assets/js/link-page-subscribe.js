/**
 * Handles artist subscription forms on profiles and link pages.
 * Uses REST API for form submission.
 */

document.addEventListener( 'DOMContentLoaded', function () {
	const subscribeModal = document.getElementById( 'extrch-subscribe-modal' );
	const subscribeIconTrigger = document.querySelector(
		'.extrch-subscribe-icon-trigger'
	);
	const modalCloseButton = subscribeModal
		? subscribeModal.querySelector( '.extrch-subscribe-modal-close' )
		: null;
	const modalOverlay = subscribeModal
		? subscribeModal.querySelector( '.extrch-subscribe-modal-overlay' )
		: null;
	const modalForm = subscribeModal
		? subscribeModal.querySelector( '#extrch-subscribe-form-modal' )
		: null;
	const subscribeForms = document.querySelectorAll(
		'.extrch-subscribe-form'
	);

	function closeSubscribeModal() {
		if ( subscribeModal ) {
			subscribeModal.classList.add( 'extrch-modal-hidden' );
			document.body.classList.remove( 'extrch-modal-open' );
			if ( modalForm ) {
				modalForm.reset();
				const formMessage = modalForm.querySelector(
					'.extrch-form-message'
				);
				if ( formMessage ) {
					formMessage.textContent = '';
				}
			}
		}
	}

	if ( subscribeModal && subscribeIconTrigger ) {
		subscribeIconTrigger.addEventListener( 'click', function () {
			subscribeModal.classList.remove( 'extrch-modal-hidden' );
			document.body.classList.add( 'extrch-modal-open' );
		} );

		if ( modalCloseButton ) {
			modalCloseButton.addEventListener( 'click', closeSubscribeModal );
		}
		if ( modalOverlay ) {
			modalOverlay.addEventListener( 'click', closeSubscribeModal );
		}

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closeSubscribeModal();
			}
		} );
	}

	function handleFormSubmission( event ) {
		event.preventDefault();

		const form = event.target;
		const formMessage = form.querySelector( '.extrch-form-message' );
		const emailInput = form.querySelector( 'input[type="email"]' );

		if ( ! emailInput || ! emailInput.value ) {
			if ( formMessage ) {
				formMessage.textContent = 'Please enter your email address.';
				formMessage.style.color = 'red';
			}
			return;
		}

		const submitButton = form.querySelector( 'button[type="submit"]' );

		if ( submitButton ) {
			submitButton.disabled = true;
			submitButton.textContent = 'Subscribing...';
		}
		form.setAttribute( 'aria-busy', 'true' );
		if ( formMessage ) {
			formMessage.textContent = '';
			formMessage.style.color = '';
		}

		const subscribeApiUrl =
			form.dataset.subscribeApiUrl ||
			( document.body && document.body.dataset
				? document.body.dataset.extrchSubscribeApiUrl
				: '' );
		if ( ! subscribeApiUrl ) {
			if ( formMessage ) {
				formMessage.textContent =
					'Subscription is temporarily unavailable.';
				formMessage.style.color = 'red';
			}
			if ( submitButton ) {
				submitButton.disabled = false;
				submitButton.textContent = 'Subscribe';
			}
			form.removeAttribute( 'aria-busy' );
			return;
		}

		fetch( subscribeApiUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( {
				email: emailInput.value,
			} ),
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					return response
						.json()
						.then( ( err ) => Promise.reject( err ) );
				}
				return response.json();
			} )
			.then( ( data ) => {
				if ( formMessage ) {
					formMessage.textContent = data.message || 'Success!';
					formMessage.style.color = 'green';
				}
				form.reset();
				if ( form.id === 'extrch-subscribe-form-modal' ) {
					setTimeout( closeSubscribeModal, 2000 );
				}
			} )
			.catch( ( error ) => {
				if ( formMessage ) {
					formMessage.textContent =
						error.message || 'An unexpected error occurred.';
					formMessage.style.color = 'red';
				}
			} )
			.finally( () => {
				form.removeAttribute( 'aria-busy' );
				if ( submitButton ) {
					submitButton.disabled = false;
					submitButton.textContent = 'Subscribe';
				}
			} );
	}

	subscribeForms.forEach( function ( form ) {
		form.addEventListener( 'submit', handleFormSubmission );
	} );
} );
