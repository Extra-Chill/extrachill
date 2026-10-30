/**
 * Archive map persistence (#847).
 *
 * The events-map block owns its collapse toggle (render + expand path,
 * deferred mount, invalidateSize). This script only persists the reader's
 * open/closed choice across archives: expanding the map on one city, venue,
 * or artist page keeps it open on the next one.
 *
 * Re-opening is done by clicking the block's own toggle, so the block's
 * expand path stays the single source of truth — Leaflet mounts (or
 * invalidates size) inside the now-visible container and never renders grey
 * tiles.
 */
( function () {
	'use strict';

	const STORAGE_KEY = 'ecEventsArchiveMapOpen';

	function persistedOpen() {
		try {
			return window.localStorage.getItem( STORAGE_KEY ) === '1';
		} catch {
			return false;
		}
	}

	function store( open ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, open ? '1' : '0' );
		} catch {
			/* Storage unavailable (private mode); choice is per-page-load. */
		}
	}

	/* Persist on every toggle click. Bubble phase: the block's own handler
	 * (target phase) has already flipped aria-expanded, so the read below is
	 * the new state. Disabled toggles never emit click, so a collapsed map
	 * whose frontend never boots stores nothing. */
	document.addEventListener( 'click', ( event ) => {
		const target = event.target;
		if ( ! target || typeof target.closest !== 'function' ) {
			return;
		}
		const toggle = target.closest( '.data-machine-events-map-toggle' );
		if ( ! toggle ) {
			return;
		}
		store( toggle.getAttribute( 'aria-expanded' ) === 'true' );
	} );

	if ( ! persistedOpen() ) {
		return;
	}

	/* Auto-expand persisted-open maps. The server renders the toggle
	 * `disabled` and the block enables it once its frontend boots, so poll
	 * briefly for that moment, then click once. */
	let waitedMs = 0;
	( function pollForToggle() {
		const toggles = document.querySelectorAll(
			'.data-machine-events-map-toggle[aria-expanded="false"]'
		);
		for ( let index = 0; index < toggles.length; index++ ) {
			if ( ! toggles[ index ].disabled ) {
				toggles[ index ].click();
				return;
			}
		}
		if ( waitedMs >= 5000 ) {
			return;
		}
		waitedMs += 100;
		window.setTimeout( pollForToggle, 100 );
	} )();
} )();
