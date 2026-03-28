/**
 * Concert Tracking — Attendance Button Interactions
 *
 * Handles click events on .ec-attendance__button elements.
 * Uses wp.apiFetch for authenticated REST calls.
 * Optimistic UI updates for instant feedback.
 *
 * @package ExtraChill\Users
 * @since 0.8.0
 */

( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '.ec-attendance__button' );
		if ( ! button ) {
			return;
		}

		var action = button.getAttribute( 'data-action' );
		if ( ! action ) {
			return;
		}

		// Redirect to login for non-authenticated users.
		if ( action === 'login' ) {
			var loginUrl = ( window.ecConcertTracking && window.ecConcertTracking.loginUrl ) || '/login/';
			window.location.href = loginUrl + '?redirect_to=' + encodeURIComponent( window.location.href );
			return;
		}

		if ( action !== 'toggle' ) {
			return;
		}

		var container = button.closest( '.ec-attendance' );
		if ( ! container ) {
			return;
		}

		// Prevent double-clicks.
		if ( button.disabled ) {
			return;
		}
		button.disabled = true;

		var eventId = parseInt( container.getAttribute( 'data-event-id' ), 10 );
		var blogId = parseInt( container.getAttribute( 'data-blog-id' ), 10 );
		var labelDefault = container.getAttribute( 'data-label-default' );
		var labelActive = container.getAttribute( 'data-label-active' );

		// Optimistic UI update.
		var isCurrentlyMarked = container.classList.contains( 'ec-attendance--marked' );
		var labelEl = button.querySelector( '.ec-attendance__label' );
		var checkEl = button.querySelector( '.ec-attendance__check' );

		if ( isCurrentlyMarked ) {
			// Unmarking.
			container.classList.remove( 'ec-attendance--marked' );
			button.classList.remove( 'ec-attendance__button--active' );
			if ( labelEl ) {
				labelEl.textContent = labelDefault;
			}
			if ( checkEl ) {
				checkEl.remove();
			}
		} else {
			// Marking.
			container.classList.add( 'ec-attendance--marked' );
			button.classList.add( 'ec-attendance__button--active' );
			if ( labelEl ) {
				labelEl.textContent = labelActive;
			}
			if ( ! checkEl ) {
				var newCheck = document.createElement( 'span' );
				newCheck.className = 'ec-attendance__check';
				newCheck.setAttribute( 'aria-hidden', 'true' );
				newCheck.textContent = '\u2713';
				button.insertBefore( newCheck, labelEl );
			}
		}

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
			var countEl = container.querySelector( '.ec-attendance__count' );
			if ( response.count > 0 ) {
				if ( countEl ) {
					countEl.textContent = response.count_label;
				} else {
					var newCount = document.createElement( 'span' );
					newCount.className = 'ec-attendance__count';
					newCount.textContent = response.count_label;
					container.appendChild( newCount );
				}
			} else if ( countEl ) {
				countEl.remove();
			}

			// Sync actual state (in case optimistic was wrong).
			if ( response.marked ) {
				container.classList.add( 'ec-attendance--marked' );
				button.classList.add( 'ec-attendance__button--active' );
			} else {
				container.classList.remove( 'ec-attendance--marked' );
				button.classList.remove( 'ec-attendance__button--active' );
			}

			button.disabled = false;
		} ).catch( function () {
			// Revert optimistic update on failure.
			if ( isCurrentlyMarked ) {
				container.classList.add( 'ec-attendance--marked' );
				button.classList.add( 'ec-attendance__button--active' );
			} else {
				container.classList.remove( 'ec-attendance--marked' );
				button.classList.remove( 'ec-attendance__button--active' );
			}
			button.disabled = false;
		} );
	} );
} )();
