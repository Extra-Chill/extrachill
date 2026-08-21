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
 * @package
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import useMarkAttendance from './useMarkAttendance';

/**
 * @param {Object}  props               Component properties.
 * @param {number}  props.eventId       Event post ID.
 * @param {number}  props.blogId        Blog ID the event lives on.
 * @param {boolean} props.isLoggedIn    Whether a user is logged in.
 * @param {boolean} props.initialMarked Server-rendered marked state.
 * @param {number}  props.initialCount  Server-rendered attendee count.
 * @param {string}  props.labelDefault  Label when unmarked.
 * @param {string}  props.labelActive   Label when marked.
 * @param {string}  props.loginUrl      Login URL for logged-out users.
 * @param {string}  props.redirectTo    Optional explicit post-login return URL.
 *                                      When empty, the current request URL is
 *                                      used (prior default behavior).
 * @param {boolean} props.pendingIntent Whether a signed attendance continuation should resume.
 * @param {string}  props.intentToken   Signed continuation added before authentication.
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
	redirectTo,
	pendingIntent,
	intentToken,
} ) => {
	const [ marked, setMarked ] = useState( !! initialMarked );
	const [ countLabel, setCountLabel ] = useState(
		initialCount && initialCount.label ? initialCount.label : ''
	);
	const { mark, isMarking, error } = useMarkAttendance();
	const buttonRef = useRef( null );
	const attemptedIntent = useRef( false );
	const [ status, setStatus ] = useState( '' );

	const completeIntent = useCallback( ( response = null ) => {
		setMarked( response ? !! response.marked : true );
		if ( response ) {
			setCountLabel(
				response.count > 0 ? response.count_label || '' : ''
			);
		}
		setStatus( 'Attendance saved.' );
		const url = new URL( window.location.href );
		url.searchParams.delete( 'ec_attendance_intent' );
		window.history.replaceState( {}, '', url.toString() );
		buttonRef.current?.focus();
	}, [] );

	useEffect( () => {
		if (
			! isLoggedIn ||
			! pendingIntent ||
			attemptedIntent.current ||
			isMarking
		) {
			return;
		}
		attemptedIntent.current = true;
		if ( marked ) {
			completeIntent();
			return;
		}
		mark( { eventId, blogId, marked: true } )
			.then( ( response ) => response && completeIntent( response ) )
			.catch( () => {
				attemptedIntent.current = false;
				setStatus( '' );
			} );
	}, [
		blogId,
		completeIntent,
		eventId,
		isLoggedIn,
		isMarking,
		mark,
		marked,
		pendingIntent,
	] );

	const handleClick = () => {
		// Logged-out: send to login, preserving return URL. Composition can
		// pass an explicit redirectTo (e.g. deep-link into the archive/import
		// flow); otherwise fall back to the current request URL.
		if ( ! isLoggedIn ) {
			const target = loginUrl || '/login/';
			const returnTo = redirectTo || window.location.href;
			const continuation = new URL( returnTo, window.location.href );
			if ( intentToken ) {
				continuation.searchParams.set(
					'ec_attendance_intent',
					intentToken
				);
			}
			const login = new URL( target, window.location.href );
			login.searchParams.set( 'redirect_to', continuation.toString() );
			window.location.href = login.toString();
			return;
		}

		if ( isMarking ) {
			return;
		}

		// Optimistic flip.
		const previous = marked;
		setMarked( ! previous );

		mark( { eventId, blogId, marked: ! previous } )
			.then( ( response ) => {
				if ( ! response ) {
					return;
				}
				// Reconcile with server truth.
				setMarked( !! response.marked );
				setStatus(
					response.marked
						? 'Attendance saved.'
						: 'Attendance removed.'
				);
				setCountLabel(
					response.count > 0 ? response.count_label || '' : ''
				);
			} )
			.catch( () => {
				// Revert; the hook exposes the error message.
				setMarked( previous );
				setStatus( '' );
			} );
	};

	const label = marked ? labelActive : labelDefault;
	const buttonClass = marked ? 'button-2' : 'button-3';

	return (
		<>
			<button
				ref={ buttonRef }
				type="button"
				className={ `ec-attendance__button ${ buttonClass } button-medium` }
				onClick={ handleClick }
				disabled={ isMarking }
				aria-pressed={ marked }
				aria-busy={ isMarking }
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
			<span
				className="ec-attendance__status"
				role="status"
				aria-live="polite"
				aria-atomic="true"
			>
				{ status }
			</span>
		</>
	);
};

export default AttendanceButton;
