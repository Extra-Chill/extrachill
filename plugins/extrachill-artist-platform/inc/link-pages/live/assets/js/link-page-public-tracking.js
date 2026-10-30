/**
 * Public Link Page Click Tracking
 *
 * Tracks link clicks for artist link pages via REST API.
 * URL normalization is handled server-side.
 */

/* global navigator */

( function () {
	'use strict';

	const body = document.body;
	const dataset = body && body.dataset ? body.dataset : null;

	const clickRestUrl = dataset ? dataset.extrchTrackingClickUrl : '';
	const linkPageId = dataset ? dataset.extrchLinkPageId : '';

	if ( ! clickRestUrl || ! linkPageId ) {
		return;
	}

	function sendBeacon( url, data ) {
		const jsonData = JSON.stringify( data );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon(
				url,
				new Blob( [ jsonData ], { type: 'application/json' } )
			);
		} else {
			fetch( url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: jsonData,
				keepalive: true,
			} ).catch( () => {} );
		}
	}

	// Track link clicks
	const linkContainer = document.querySelector(
		'.extrch-link-page-content-wrapper'
	);
	if ( linkContainer ) {
		linkContainer.addEventListener( 'click', ( event ) => {
			const linkElement = event.target.closest( 'a' );
			if (
				linkElement &&
				linkElement.href &&
				! linkElement.classList.contains( 'extrch-link-page-edit-btn' )
			) {
				const linkTextEl = linkElement.querySelector(
					'.extrch-link-page-link-text'
				);
				const linkText = linkTextEl
					? linkTextEl.textContent.trim()
					: '';

				sendBeacon( clickRestUrl, {
					click_type: 'link_page_link',
					link_page_id: linkPageId,
					source_url: window.location.href,
					destination_url: linkElement.href,
					element_text: linkText,
				} );
			}
		} );
	}
} )();
