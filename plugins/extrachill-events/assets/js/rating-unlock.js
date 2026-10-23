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
 */
( function () {
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
	} );
} )();
