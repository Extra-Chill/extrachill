/**
 * Single-event Local Scene digest offer behaviour (extrachill-events#962,
 * extrachill-users#450).
 *
 * - "Not now" hides the offer and remembers that per city in localStorage.
 * - After an attendance mark (`ec:attendance-changed`, dispatched by the
 *   extrachill-users attendance button), reframe the offer as the next step
 *   and tag the submission as `post_mark` for analytics. A first-ever mark
 *   brings a dismissed offer back once, because that is the moment a new
 *   account most needs a reason to return.
 */
( function () {
	'use strict';

	const offer = document.querySelector( '[data-event-digest-offer]' );
	if ( ! offer ) {
		return;
	}

	const mode = offer.dataset.eventDigestMode;
	if ( 'subscribe' !== mode && 'set_scene' !== mode ) {
		return;
	}

	const key =
		'ecEventDigestDismissed:' + ( offer.dataset.eventDigestCity || '' );
	const dismiss = offer.querySelector( '[data-event-digest-dismiss]' );

	function storage( action, value ) {
		try {
			if ( 'get' === action ) {
				return window.localStorage.getItem( key );
			}
			if ( 'set' === action ) {
				window.localStorage.setItem( key, value );
			} else {
				window.localStorage.removeItem( key );
			}
		} catch {
			// Storage blocked: the offer simply stays visible.
		}
		return null;
	}

	if ( storage( 'get' ) ) {
		offer.hidden = true;
	}

	if ( dismiss ) {
		dismiss.hidden = false;
		dismiss.addEventListener( 'click', function () {
			storage( 'set', '1' );
			offer.hidden = true;
		} );
	}

	document.addEventListener( 'ec:attendance-changed', function ( event ) {
		const detail = event.detail || {};
		if ( ! detail.marked ) {
			return;
		}
		if ( offer.hidden && ! detail.firstMark ) {
			return;
		}

		storage( 'remove' );
		offer.hidden = false;

		const heading = offer.querySelector(
			'[data-event-digest-heading] strong'
		);
		if ( heading && offer.dataset.postMarkHeading ) {
			heading.textContent = offer.dataset.postMarkHeading;
		}
		const surface = offer.querySelector( '[data-event-digest-surface]' );
		if ( surface ) {
			surface.value = 'post_mark';
		}
	} );
} )();
