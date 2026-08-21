/**
 * useMarkAttendance — concert-attendance toggle hook.
 *
 * Single client-side implementation of the write against
 * `extrachill/set-event-mark` for the single-event attendance
 * button. Mirrors the canonical hook of the same name shipped by
 * extrachill-events (blocks/concert-stats/src/hooks/useMarkAttendance.js):
 * both call sites converge on one React + apiFetch contract instead of the
 * old vanilla-JS data-* IIFE.
 *
 * Lives locally in this plugin (rather than imported from a shared npm
 * package) because no cross-plugin JS-sharing mechanism exists and the two
 * plugins build on different @wordpress/scripts + React majors. A 2-property
 * shared package for ~30 lines would be premature consolidation; the shared
 * contract is the convergence, enforced by mirroring the hook shape.
 *
 * Contract:
 *   const { mark, isMarking, error } = useMarkAttendance();
 *   const result = await mark( { eventId, blogId } );
 *   // result === { marked: bool, count: number, count_label: string }
 *
 * The hook owns ONLY the network write + its in-flight / error state. The
 * caller owns its own optimistic UI.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const useMarkAttendance = () => {
	const [ isMarking, setIsMarking ] = useState( false );
	const [ error, setError ] = useState( null );

	const inFlight = useRef( false );

	const mark = useCallback( ( { eventId, blogId, marked = true } = {} ) => {
		if ( inFlight.current ) {
			return Promise.resolve( null );
		}

		inFlight.current = true;
		setIsMarking( true );
		setError( null );

		const data = { event_id: eventId, marked };
		if ( blogId ) {
			data.blog_id = blogId;
		}

		return apiFetch( {
			path: '/wp-abilities/v1/abilities/extrachill/set-event-mark/run',
			method: 'POST',
			data: { input: data },
		} )
			.then( ( response ) => {
				inFlight.current = false;
				setIsMarking( false );
				return response;
			} )
			.catch( ( err ) => {
				inFlight.current = false;
				setIsMarking( false );
				const message =
					( err && err.message ) ||
					__( 'Failed to update attendance.', 'extrachill-users' );
				setError( message );
				throw err;
			} );
	}, [] );

	return { mark, isMarking, error };
};

export default useMarkAttendance;
