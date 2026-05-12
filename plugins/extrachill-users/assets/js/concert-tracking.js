/**
 * Concert Tracking — Attendance Button Interactions
 *
 * Handles click events on .ec-attendance__button elements.
 * Uses wp.apiFetch for authenticated REST calls.
 * Optimistic UI updates toggle between theme button classes:
 *   - button-3 (neutral) when unmarked
 *   - button-2 (green accent) when marked
 *
 * @package
 * @since 0.8.0
 */

( function () {
	'use strict';

	// Toggle button between marked (button-2) and unmarked (button-3) states.
	function setButtonState( button, container, marked, labelEl, labelDefault, labelActive ) {
		if ( marked ) {
			container.classList.add( 'ec-attendance--marked' );
			button.classList.remove( 'button-3' );
			button.classList.add( 'button-2' );
			if ( labelEl ) {
				labelEl.textContent = labelActive;
			}
			// Add check mark if not present.
			if ( ! button.querySelector( '.ec-attendance__check' ) ) {
				const check = document.createElement( 'span' );
				check.className = 'ec-attendance__check';
				check.setAttribute( 'aria-hidden', 'true' );
				check.textContent = '\u2713';
				button.insertBefore( check, labelEl );
			}
		} else {
			container.classList.remove( 'ec-attendance--marked' );
			button.classList.remove( 'button-2' );
			button.classList.add( 'button-3' );
			if ( labelEl ) {
				labelEl.textContent = labelDefault;
			}
			// Remove check mark.
			const checkEl = button.querySelector( '.ec-attendance__check' );
			if ( checkEl ) {
				checkEl.remove();
			}
		}
	}

	document.addEventListener( 'click', function ( e ) {
		const button = e.target.closest( '.ec-attendance__button' );
		if ( ! button ) {
			return;
		}

		const action = button.getAttribute( 'data-action' );
		if ( ! action ) {
			return;
		}

		// Redirect to login for non-authenticated users.
		if ( action === 'login' ) {
			const loginUrl = ( window.ecConcertTracking && window.ecConcertTracking.loginUrl ) || '/login/';
			window.location.href = loginUrl + '?redirect_to=' + encodeURIComponent( window.location.href );
			return;
		}

		if ( action !== 'toggle' ) {
			return;
		}

		const container = button.closest( '.ec-attendance' );
		if ( ! container ) {
			return;
		}

		// Prevent double-clicks.
		if ( button.disabled ) {
			return;
		}
		button.disabled = true;

		const eventId = parseInt( container.getAttribute( 'data-event-id' ), 10 );
		const blogId = parseInt( container.getAttribute( 'data-blog-id' ), 10 );
		const labelDefault = container.getAttribute( 'data-label-default' );
		const labelActive = container.getAttribute( 'data-label-active' );
		const labelEl = button.querySelector( '.ec-attendance__label' );

		// Optimistic UI update.
		const isCurrentlyMarked = container.classList.contains( 'ec-attendance--marked' );
		setButtonState( button, container, ! isCurrentlyMarked, labelEl, labelDefault, labelActive );

		// API call.
		wp.apiFetch( {
			path: '/extrachill/v1/concert-tracking/toggle',
			method: 'POST',
			data: {
				event_id: eventId,
				blog_id: blogId,
			},
		} ).then( function ( response ) {
			// Update count label.
			const countEl = container.querySelector( '.ec-attendance__count' );
			if ( response.count > 0 ) {
				if ( countEl ) {
					countEl.textContent = response.count_label;
				} else {
					const newCount = document.createElement( 'span' );
					newCount.className = 'ec-attendance__count';
					newCount.textContent = response.count_label;
					container.appendChild( newCount );
				}
			} else if ( countEl ) {
				countEl.remove();
			}

			// Sync actual state (in case optimistic was wrong).
			setButtonState( button, container, response.marked, labelEl, labelDefault, labelActive );
			button.disabled = false;
		} ).catch( function () {
			// Revert optimistic update on failure.
			setButtonState( button, container, isCurrentlyMarked, labelEl, labelDefault, labelActive );
			button.disabled = false;
		} );
	} );
} )();
