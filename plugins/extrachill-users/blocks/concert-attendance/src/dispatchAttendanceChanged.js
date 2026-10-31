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
 * const { eventId, blogId, marked, firstMark } = e.detail; ... } )`.
 *
 * `firstMark` is true only when this resolved request was the user's
 * first-ever attendance mark, so a listener can tailor a one-time follow-up
 * (e.g. extrachill-events' Local Scene digest offer) without asking again.
 *
 * @param {Object}  detail
 * @param {number}  detail.eventId   Event post ID.
 * @param {number}  detail.blogId    Blog ID the event lives on.
 * @param {boolean} detail.marked    Attendance state after the request resolved.
 * @param {boolean} detail.firstMark Whether this was the user's first-ever mark.
 */
const dispatchAttendanceChanged = ( {
	eventId,
	blogId,
	marked,
	firstMark = false,
} ) => {
	document.dispatchEvent(
		new CustomEvent( 'ec:attendance-changed', {
			detail: { eventId, blogId, marked, firstMark: !! firstMark },
		} )
	);
};

export default dispatchAttendanceChanged;
