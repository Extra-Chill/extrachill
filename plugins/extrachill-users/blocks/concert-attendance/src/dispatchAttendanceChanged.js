/**
 * dispatchAttendanceChanged — the concert-attendance block's public JS
 * contract for other plugins.
 *
 * Fires `ec:attendance-changed` on `document` immediately after an
 * attendance mark/unmark request RESOLVES — never optimistically, never on
 * click alone — so a listener knows the server-side write (and anything
 * else hooked to the server-side `ec_users_event_marked` /
 * `ec_users_event_unmarked` actions, such as extrachill-events' RSVP perk
 * pass, see extrachill-events#879) has already completed.
 *
 * Deliberately generic: this plugin has no concept of RSVP perks, QR
 * passes, or anything else a listener might build on top of attendance. It
 * only announces "attendance for this event changed," the same way the
 * server-side `ec_users_event_marked` / `ec_users_event_unmarked` hooks do.
 *
 * Consumers: `document.addEventListener( 'ec:attendance-changed', ( e ) => {
 * const { eventId, blogId, marked } = e.detail; ... } )`.
 *
 * @param {Object}  detail
 * @param {number}  detail.eventId Event post ID.
 * @param {number}  detail.blogId  Blog ID the event lives on.
 * @param {boolean} detail.marked  Attendance state after the request resolved.
 */
const dispatchAttendanceChanged = ( { eventId, blogId, marked } ) => {
	document.dispatchEvent(
		new CustomEvent( 'ec:attendance-changed', {
			detail: { eventId, blogId, marked },
		} )
	);
};

export default dispatchAttendanceChanged;
