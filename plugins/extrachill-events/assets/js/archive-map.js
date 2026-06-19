/**
 * Archive Map Collapse Toggle
 *
 * Toggles the collapsible events-map wrapper on taxonomy archives
 * (location / venue / artist). The map defaults collapsed so the event
 * list — not the map — is the dominant element (data-machine-events#373).
 *
 * Leaflet caveat: the events-map block only calls invalidateSize() once at
 * mount. When the panel starts hidden the map boots with a zero-size box, so
 * the first time we reveal it we dispatch a synthetic window `resize` event.
 * Leaflet's Map registers a window-resize handler by default (trackResize),
 * which calls invalidateSize() for us — no dependency on block internals.
 *
 * Plain vanilla JS, no jQuery / React — matches assets/js/discovery.js style.
 *
 * @package ExtraChillEvents
 * @since 0.30.0
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var wrappers = document.querySelectorAll( '[data-collapsible-map]' );
		if ( ! wrappers.length ) {
			return;
		}

		for ( var i = 0; i < wrappers.length; i++ ) {
			setupWrapper( wrappers[ i ] );
		}
	}

	function setupWrapper( wrapper ) {
		var toggle = wrapper.querySelector(
			'.extrachill-events-archive-map__toggle'
		);
		var panel = wrapper.querySelector(
			'.extrachill-events-archive-map__panel'
		);
		if ( ! toggle || ! panel ) {
			return;
		}

		toggle.addEventListener( 'click', function () {
			var expanded =
				toggle.getAttribute( 'aria-expanded' ) === 'true';
			setExpanded( wrapper, toggle, panel, ! expanded );
		} );
	}

	function setExpanded( wrapper, toggle, panel, expand ) {
		toggle.setAttribute( 'aria-expanded', expand ? 'true' : 'false' );
		wrapper.classList.toggle( 'is-expanded', expand );

		var label = toggle.querySelector(
			'.extrachill-events-archive-map__toggle-label'
		);

		if ( expand ) {
			panel.hidden = false;
			if ( label ) {
				label.textContent = 'Hide map';
			}
			// Nudge Leaflet to recompute tile layout now that the panel
			// has a real size. Defer to the next frame so the panel has
			// laid out before the resize fires.
			requestAnimationFrame( function () {
				window.dispatchEvent( new Event( 'resize' ) );
			} );
		} else {
			panel.hidden = true;
			if ( label ) {
				label.textContent = 'Show map';
			}
		}
	}
} )();
