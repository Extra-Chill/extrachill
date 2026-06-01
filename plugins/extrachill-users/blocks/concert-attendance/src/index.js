/**
 * Concert Attendance — single-event button frontend mount.
 *
 * Hydrates the server-rendered mount point emitted by
 * inc/concert-tracking/buttons.php into a headless React component that
 * toggles attendance via the shared useMarkAttendance hook. Replaces the
 * legacy vanilla-JS data-* IIFE (assets/js/concert-tracking.js) and the
 * window.ecConcertTracking global.
 *
 * Initial props are read from the mount element's data attributes, which are
 * the server's first-paint state (not an imperative control surface — the
 * React component owns all interaction after mount).
 *
 * @package ExtraChillUsers
 */

import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import AttendanceButton from './AttendanceButton';

domReady( () => {
	const mount = document.getElementById( 'ec-attendance-root' );
	if ( ! mount ) {
		return;
	}

	const eventId = parseInt( mount.dataset.eventId, 10 ) || 0;
	const blogId = parseInt( mount.dataset.blogId, 10 ) || 0;
	if ( ! eventId ) {
		return;
	}

	const count = parseInt( mount.dataset.count, 10 ) || 0;

	const root = createRoot( mount );
	root.render(
		<AttendanceButton
			eventId={ eventId }
			blogId={ blogId }
			isLoggedIn={ mount.dataset.isLoggedIn === '1' }
			initialMarked={ mount.dataset.marked === '1' }
			initialCount={ { count, label: mount.dataset.countLabel || '' } }
			labelDefault={ mount.dataset.labelDefault || '' }
			labelActive={ mount.dataset.labelActive || '' }
			loginUrl={ mount.dataset.loginUrl || '/login/' }
		/>
	);
} );
