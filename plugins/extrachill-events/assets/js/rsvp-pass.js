/**
 * RSVP Perk Pass — attendee pass watcher + host door-list redeem.
 *
 * Vanilla script, no build step, matching this plugin's other single-page
 * enhancements (assets/js/near-me.js, assets/js/discovery.js).
 *
 * Attendee side: the "Going" button is a React component owned by
 * extrachill-users (blocks/concert-attendance) that this plugin does not
 * control. Rather than couple to its internals, this listens for clicks on
 * its stable, accessibility-required `.ec-attendance__button` class and
 * re-fetches this plugin's own pass-state ability afterward — a standard,
 * low-coupling integration between two independently-versioned plugins'
 * UI, not a workaround for something that should be fixed upstream: the
 * attendance button has no reason to know that a perk pass exists.
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
	 * @param {number} attempt Retry counter (max 1 extra attempt).
	 */
	function refreshPass( eventId, attempt ) {
		attempt = attempt || 0;

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

				// A newly-marked "Going" issues the pass server-side inside
				// the same request that the attendance button is awaiting;
				// by the time our first poll lands it is normally already
				// committed. One short retry covers the rare slow case
				// without polling indefinitely.
				if ( ( ! response || ! response.issued ) && attempt < 1 ) {
					window.setTimeout( function () {
						refreshPass( eventId, attempt + 1 );
					}, 1500 );
				}
			} )
			.catch( function () {
				// Network hiccups are non-fatal: the pass stays as it was
				// server-rendered on load, or from the previous poll.
			} );
	}

	/** Watch the attendance button for state changes and refresh the pass. */
	function initPassWatcher() {
		if ( ! window.ecRsvpPass || ! window.ecRsvpPass.eventId ) {
			return;
		}

		const eventId = window.ecRsvpPass.eventId;

		// Bound directly to the button (there is at most one per event
		// page) rather than delegated on document: the attendance button
		// is a React component owned by extrachill-users, server-rendered
		// fresh on every page load, so there is a concrete element to bind
		// to and no reason to delegate from a shared ancestor.
		const button = document.querySelector( '.ec-attendance__button' );
		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			// Give the mark/unmark REST call time to land (it issues or
			// revokes the pass synchronously server-side) before asking for
			// the resulting truth.
			window.setTimeout( function () {
				refreshPass( eventId, 0 );
			}, 900 );
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
