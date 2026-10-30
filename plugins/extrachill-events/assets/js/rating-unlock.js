/**
 * Unlock show ratings as soon as someone marks "I Was There".
 *
 * Extra Chill only lets attendees rate a show (see
 * ec_events_require_attendance_to_rate). The extrachill-users attendance
 * button announces a resolved mark with `ec:attendance-changed`, and
 * data-machine-events reveals or re-locks its rating form on
 * `dme:rating-eligibility`. This file maps one to the other, so the rating
 * form appears without a reload. The server still checks attendance on
 * submit.
 *
 * The rating form sits at the bottom of the page, far from the attendance
 * button, so whenever the star picker is open a "Rate this show" link under
 * the button jumps to it.
 */
( function () {
	const LINK_CLASS = 'ec-rate-it-link';

	function ratingField() {
		const field = document.querySelector( '#respond .dme-rating-field' );
		return field && ! field.closest( '[hidden]' ) ? field : null;
	}

	function removeLink() {
		document
			.querySelectorAll( '.' + LINK_CLASS )
			.forEach( ( el ) => el.remove() );
	}

	function syncLink() {
		const root = document.getElementById( 'ec-attendance-root' );
		const field = ratingField();
		if ( ! root || ! field ) {
			removeLink();
			return;
		}
		if ( document.querySelector( '.' + LINK_CLASS ) ) {
			return;
		}
		// Outside the attendance root: its children belong to React.
		const wrap = document.createElement( 'p' );
		wrap.className = LINK_CLASS;
		const link = document.createElement( 'a' );
		link.href = '#respond';
		link.className = 'button-2 button-small';
		link.textContent = 'Rate this show ↓';
		link.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			document
				.getElementById( 'respond' )
				.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			const first = field.querySelector( 'input' );
			if ( first ) {
				first.focus( { preventScroll: true } );
			}
		} );
		wrap.appendChild( link );
		root.insertAdjacentElement( 'afterend', wrap );
	}

	document.addEventListener( 'ec:attendance-changed', function ( event ) {
		const detail = ( event && event.detail ) || {};
		if ( ! detail.eventId ) {
			return;
		}
		document.dispatchEvent(
			new CustomEvent( 'dme:rating-eligibility', {
				detail: { eventId: detail.eventId, canRate: !! detail.marked },
			} )
		);
		syncLink();
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', syncLink );
	} else {
		syncLink();
	}
} )();
