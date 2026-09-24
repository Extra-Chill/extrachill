/**
 * RSVP Perk Pass — attendee pass watcher + host door-list redeem.
 *
 * Vanilla script, no build step, matching this plugin's other single-page
 * enhancements (assets/js/near-me.js, assets/js/discovery.js).
 *
 * Attendee side: listens for `ec:attendance-changed`, a generic DOM
 * CustomEvent extrachill-users' concert-attendance block dispatches on
 * `document` after a mark/unmark request RESOLVES (never optimistically,
 * never on a timer) — the block's public JS contract
 * (blocks/concert-attendance/src/dispatchAttendanceChanged.js there). This
 * replaces an earlier version that bound directly to that component's
 * `.ec-attendance__button` CSS class and guessed completion with fixed
 * setTimeout delays: cross-plugin markup coupling, and a real race on a
 * slow connection — a phone at a bar, the exact use case (extrachill-events#879).
 * The server-rendered pass remains the correct no-JS/initial state; this
 * listener is a pure enhancement on top of it.
 *
 * Harmless if the event never fires (an older extrachill-users without the
 * dispatch deployed): no listener attaches, the server-rendered pass still
 * shows on load, nothing throws.
 *
 * Host side: the door list's Redeem button calls this plugin's own
 * redeem-event-pass ability directly.
 *
 * @package
 */

( function () {
	'use strict';

	/**
	 * Thin apiFetch wrapper. wp.apiFetch is guaranteed present because this
	 * script depends on the 'wp-api-fetch' handle (see
	 * inc/core/rsvp-pass-integration.php), which WordPress core wires with
	 * the REST root URL and nonce automatically.
	 *
	 * @param {string} path
	 * @param {string} method
	 * @param {Object} data
	 * @return {Promise} The apiFetch response promise.
	 */
	function apiFetch( path, method, data ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			return Promise.reject( new Error( 'apiFetch unavailable' ) );
		}
		return window.wp.apiFetch( { path, method, data } );
	}

	/**
	 * Render (or hide) the pass card from an ability response.
	 *
	 * @param {HTMLElement|null} container
	 * @param {Object|null}      pass
	 */
	function renderPass( container, pass ) {
		if ( ! container ) {
			return;
		}

		if ( ! pass || ! pass.issued ) {
			container.hidden = true;
			return;
		}

		const perkEl = container.querySelector( '.ec-rsvp-pass__perk' );
		const codeEl = container.querySelector( '.ec-rsvp-pass__code' );

		if ( perkEl && pass.perk_text ) {
			perkEl.textContent = pass.perk_text;
		}
		if ( codeEl ) {
			codeEl.textContent = pass.code || '';
		}

		container.hidden = false;
	}

	/**
	 * Fetch and render the current pass state for an event.
	 *
	 * @param {number} eventId
	 */
	function refreshPass( eventId ) {
		apiFetch(
			'/wp-abilities/v1/abilities/extrachill/get-my-event-pass/run',
			'POST',
			{ input: { event_id: eventId } }
		)
			.then( function ( response ) {
				const container = document.getElementById(
					'ec-rsvp-pass-' + eventId
				);
				renderPass( container, response );
			} )
			.catch( function () {
				// Network hiccups are non-fatal: the pass stays as it was
				// server-rendered on load, or from the previous refresh.
			} );
	}

	/**
	 * Watch for attendance changes on this page's event and refresh the
	 * pass accordingly. No timers, no coupling to another plugin's markup:
	 * `ec:attendance-changed` fires only after the mark/unmark request has
	 * actually resolved, so by the time this listener runs the server-side
	 * pass issue/revoke (which happens synchronously inside that same
	 * request, see rsvp-pass-service.php) has already happened.
	 */
	function initPassWatcher() {
		if ( ! window.ecRsvpPass || ! window.ecRsvpPass.eventId ) {
			return;
		}

		const eventId = window.ecRsvpPass.eventId;

		document.addEventListener( 'ec:attendance-changed', function ( event ) {
			const detail = event.detail || {};
			if ( parseInt( detail.eventId, 10 ) !== eventId ) {
				return;
			}
			refreshPass( eventId );
		} );
	}

	/** Wire the host door list's one-tap Redeem buttons. */
	function initDoorList() {
		const list = document.querySelector( '.ec-door-list' );
		if ( ! list ) {
			return;
		}

		const eventId = parseInt( list.getAttribute( 'data-event-id' ), 10 );

		list.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '.ec-door-list__redeem' );
			if ( ! button ) {
				return;
			}

			const userId = parseInt(
				button.getAttribute( 'data-user-id' ),
				10
			);
			if ( ! userId || ! eventId ) {
				return;
			}

			button.disabled = true;

			apiFetch(
				'/wp-abilities/v1/abilities/extrachill/redeem-event-pass/run',
				'POST',
				{ input: { event_id: eventId, user_id: userId } }
			)
				.then( function ( response ) {
					const row = list.querySelector(
						'.ec-door-list__row[data-user-id="' + userId + '"]'
					);
					if ( ! row ) {
						return;
					}

					const label =
						response && response.redeemed_at
							? 'Redeemed ' + response.redeemed_at
							: 'Redeemed';

					const status = row.querySelector( '.ec-door-list__status' );
					if ( status ) {
						status.textContent = label;
						return;
					}

					const span = document.createElement( 'span' );
					span.className = 'ec-door-list__status';
					span.textContent = label;
					button.replaceWith( span );
				} )
				.catch( function () {
					button.disabled = false;
				} );
		} );
	}

	// Enqueued with in_footer=true (see inc/core/rsvp-pass-integration.php),
	// so the DOM is already parsed by the time this executes — no
	// DOMContentLoaded listener needed.
	initPassWatcher();
	initDoorList();
} )();
