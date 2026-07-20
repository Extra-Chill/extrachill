/**
 * useStats — Fetch aggregate concert stats from REST API.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const emptyResult = ( queryKey, loading = true ) => ( {
	queryKey,
	stats: null,
	loading,
	error: null,
} );

/**
 * @param {number} userId
 * @param {Object} filters - { year, dateTo, enabled }
 */
export default function useStats( userId, filters = {} ) {
	const { year = 0, dateTo = '', enabled = true } = filters;
	const queryKey = [ userId, year, dateTo, enabled ].join( ':' );
	const activeQueryKey = useRef( queryKey );
	activeQueryKey.current = queryKey;
	const abortRef = useRef( null );
	const generationRef = useRef( 0 );
	const [ result, setResult ] = useState( () => emptyResult( queryKey ) );

	const fetchStats = useCallback( () => {
		if ( abortRef.current ) {
			abortRef.current.abort();
		}

		const generation = ++generationRef.current;
		if ( ! userId || ! enabled ) {
			abortRef.current = null;
			setResult( emptyResult( queryKey, false ) );
			return;
		}

		const controller = new AbortController();
		abortRef.current = controller;
		setResult( emptyResult( queryKey ) );

		const params = new URLSearchParams();
		if ( year ) {
			params.set( 'year', year );
		}
		if ( dateTo ) {
			params.set( 'date_to', dateTo );
		}
		const query = params.toString();
		const path = `/extrachill/v1/concert-tracking/user/${ userId }/stats${
			query ? `?${ query }` : ''
		}`;

		apiFetch( { path, signal: controller.signal } )
			.then( ( response ) => {
				if (
					controller.signal.aborted ||
					generationRef.current !== generation ||
					activeQueryKey.current !== queryKey
				) {
					return;
				}

				setResult( {
					queryKey,
					stats: response,
					loading: false,
					error: null,
				} );
			} )
			.catch( ( err ) => {
				if (
					( err && err.name === 'AbortError' ) ||
					controller.signal.aborted ||
					generationRef.current !== generation ||
					activeQueryKey.current !== queryKey
				) {
					return;
				}

				setResult( {
					queryKey,
					stats: null,
					loading: false,
					error: err,
				} );
			} );
	}, [ userId, year, dateTo, enabled, queryKey ] );

	useEffect( () => {
		fetchStats();
		return () => {
			if ( abortRef.current ) {
				abortRef.current.abort();
			}
		};
	}, [ fetchStats ] );

	const activeResult =
		result.queryKey === queryKey ? result : emptyResult( queryKey );

	return { ...activeResult, refetch: fetchStats };
}
