/**
 * useMarkAttendance — shared concert-attendance toggle hook.
 *
 * Single client-side implementation of the write against
 * `/extrachill/v1/concert-tracking/toggle`. Used by both the concert-stats
 * block search results (EventSearchResult) and the single-event attendance
 * button (extrachill-users renders its own React mount that mirrors this hook).
 *
 * Contract:
 *   const { mark, isMarking, error } = useMarkAttendance();
 *   const result = await mark( { eventId, blogId } );
 *   // result === { marked: bool, count?: number, count_label?: string }
 *
 * The hook owns ONLY the network write + its in-flight / error state. Callers
 * own their own optimistic UI (the block flips a list row; the button flips
 * its own marked state) so this stays presentation-agnostic and reusable.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const useMarkAttendance = () => {
	const [ isMarking, setIsMarking ] = useState( false );
	const [ error, setError ] = useState( null );

	// Guard against overlapping toggles for the same hook instance.
	const inFlight = useRef( false );

	const mark = useCallback( ( { eventId, blogId } = {} ) => {
		if ( inFlight.current ) {
			return Promise.resolve( null );
		}

		inFlight.current = true;
		setIsMarking( true );
		setError( null );

		const data = { event_id: eventId };
		// blogId is optional: the REST route defaults to the current blog when
		// omitted. The single-event button passes it explicitly because the
		// event may be rendered in a cross-site context.
		if ( blogId ) {
			data.blog_id = blogId;
		}

		return apiFetch( {
			path: '/extrachill/v1/concert-tracking/toggle',
			method: 'POST',
			data,
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
					( err && err.message ) || 'Failed to update attendance.';
				setError( message );
				throw err;
			} );
	}, [] );

	return { mark, isMarking, error };
};

export default useMarkAttendance;
