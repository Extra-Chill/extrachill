/**
 * AttendanceButton — single-event attendance toggle.
 *
 * Headless React replacement for the old assets/js/concert-tracking.js IIFE.
 * Renders the "Going / Check In / I Was There" button in the Event Details
 * action row and toggles attendance via the shared useMarkAttendance hook.
 *
 * Initial state is server-rendered into the mount props (buttons.php) so the
 * first paint matches the prior server output (label, marked state, count).
 *
 * Theme button classes:
 *   - button-2 (green accent) when marked
 *   - button-3 (neutral)      when unmarked
 *
 * @package ExtraChillUsers
 */

import { useState } from '@wordpress/element';
import useMarkAttendance from './useMarkAttendance';

/**
 * @param {Object}  props
 * @param {number}  props.eventId       Event post ID.
 * @param {number}  props.blogId        Blog ID the event lives on.
 * @param {boolean} props.isLoggedIn    Whether a user is logged in.
 * @param {boolean} props.initialMarked Server-rendered marked state.
 * @param {number}  props.initialCount  Server-rendered attendee count.
 * @param {string}  props.labelDefault  Label when unmarked.
 * @param {string}  props.labelActive   Label when marked.
 * @param {string}  props.loginUrl      Login URL for logged-out users.
 */
const AttendanceButton = ( {
	eventId,
	blogId,
	isLoggedIn,
	initialMarked,
	initialCount,
	labelDefault,
	labelActive,
	loginUrl,
} ) => {
	const [ marked, setMarked ] = useState( !! initialMarked );
	const [ countLabel, setCountLabel ] = useState(
		initialCount && initialCount.label ? initialCount.label : ''
	);
	const { mark, isMarking, error } = useMarkAttendance();

	const handleClick = () => {
		// Logged-out: send to login, preserving return URL.
		if ( ! isLoggedIn ) {
			const target = loginUrl || '/login/';
			window.location.href =
				target +
				'?redirect_to=' +
				encodeURIComponent( window.location.href );
			return;
		}

		if ( isMarking ) {
			return;
		}

		// Optimistic flip.
		const previous = marked;
		setMarked( ! previous );

		mark( { eventId, blogId } )
			.then( ( response ) => {
				if ( ! response ) {
					return;
				}
				// Reconcile with server truth.
				setMarked( !! response.marked );
				setCountLabel(
					response.count > 0 ? response.count_label || '' : ''
				);
			} )
			.catch( () => {
				// Revert; the hook exposes the error message.
				setMarked( previous );
			} );
	};

	const label = marked ? labelActive : labelDefault;
	const buttonClass = marked ? 'button-2' : 'button-3';

	return (
		<>
			<button
				type="button"
				className={ `ec-attendance__button ${ buttonClass } button-medium` }
				onClick={ handleClick }
				disabled={ isMarking }
				aria-pressed={ marked }
			>
				{ marked && (
					<span className="ec-attendance__check" aria-hidden="true">
						{ '\u2713' }
					</span>
				) }
				<span className="ec-attendance__label">{ label }</span>
			</button>
			{ countLabel && (
				<span className="ec-attendance__count">{ countLabel }</span>
			) }
			{ error && (
				<span className="ec-attendance__error" role="alert">
					{ error }
				</span>
			) }
		</>
	);
};

export default AttendanceButton;
